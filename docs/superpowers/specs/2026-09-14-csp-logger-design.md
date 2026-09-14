# CSP Violation Logger — Design

## Purpose

A small Laravel/Filament application that ingests Content-Security-Policy
(CSP) violation reports from a set of websites (running CSP in
report-only mode, across different domains), stores them durably via a
Redis-backed queue, and provides an admin panel to filter/inspect the
violations. The goal is to observe real-world violations across the
sites and use them to determine what CSP policy can safely be enforced.

Scale: the reporting sites together see roughly 500k pageviews/month.
Violation report volume can spike well above that (e.g. a single
misconfigured script can cause every pageview to fire a report), so
ingestion must never lose reports and must not block on the database.

## Stack

- Laravel (latest stable), PHP 8.3+
- Filament (latest stable) for the admin panel
- MySQL for storage
- Redis + Laravel Horizon for queue management/monitoring
- Laravel Sail (Docker) for local development

## Architecture & Data Flow

```
Browser (any registered site, CSP in report-only mode)
   │  POST application/csp-report (legacy) or application/reports+json (Reporting API)
   ▼
Route: POST /csp-report (routes/api.php — unauthenticated, no CSRF)
   │  throttle middleware (e.g. 120 req/min per IP) as a basic abuse guard
   │  CORS: Access-Control-Allow-Origin: * (see Routing & CORS below)
   ▼
CspReportController@store
   │  Reads raw body + Content-Type, dispatches ProcessCspReportJob(rawBody, contentType)
   │  Responds 204 No Content immediately — no parsing or DB work in the request path
   │  Dispatch failures (e.g. Redis unreachable) are NOT caught — they surface as a
   │  500 (browsers ignore the response either way) so an outage is loud in logs
   │  instead of silently dropping reports
   ▼
Redis queue ("csp-reports") — durable buffer, survives DB slowness/outages and deploys
   ▼
Horizon-managed worker → ProcessCspReportJob::handle()
   │  1. Detect format from Content-Type and normalize into a common set of fields
   │     (legacy: single `csp-report` object; Reporting API: array of reports,
   │     only entries with type === "csp-violation" are processed, others ignored)
   │  2. Extract hostname from document-uri/url, lowercased explicitly in code
   │     (not relied on DB collation), port stripped. If no usable hostname can be
   │     extracted at all, log a warning and discard — nothing to key a row on.
   │  3. Look up an *active* Site by exact hostname match:
   │       - match found  → atomic upsert into csp_violations (increment counter)
   │       - no match     → atomic upsert into unauthorized_report_domains
   │  4. Malformed/unparseable payloads are logged as a warning and the job
   │     completes successfully (no retries, no failed_jobs entry — this is a
   │     permanent failure, not a transient one). Real exceptions (DB connection
   │     issues etc.) use normal job retry (3 attempts, backoff).
   ▼
MySQL
   ▼
Filament admin panel (single admin user)
```

A daily scheduled command prunes `csp_violations` rows whose
`last_seen_at` is older than 30 days.

## Routing & CORS

CSP's two reporting mechanisms differ in CORS behavior:

- **Legacy `report-uri`** is exempt from CORS entirely — no preflight,
  no headers required, regardless of the target origin.
- **Reporting API (`report-to`)** does require CORS: browsers send an
  `OPTIONS` preflight and require `Access-Control-Allow-Origin` (etc.)
  in the response before the report POST is accepted.

Since the endpoint accepts both formats, it must answer preflights with
`Access-Control-Allow-Origin: *` (wide open — the endpoint returns
nothing sensitive, and already accepts writes from unregistered domains
by design; restricting the origin would require a `sites` lookup on
every preflight, reintroducing a DB read into the fast request path).

Laravel 11/12 no longer include `routes/api.php` by default (removed
from the skeleton; restoring it via `php artisan install:api` also
pulls in unwanted Sanctum scaffolding). This app adds a minimal
`routes/api.php` by hand, wired into `bootstrap/app.php` via
`withRouting(api: ...)`, without running `install:api` — keeping
`/csp-report` naturally outside the `web` middleware group (no
session/CSRF) without unused auth machinery.

## Domain Authorization Model

Sites must be registered up front (via the SiteResource) before their
reports are stored as real violations. This is an allowlist, not
auto-registration:

- Matching is by **exact hostname** (e.g. `www.example.com`,
  `example.com`, and `blog.example.com` are registered separately —
  registering one does not automatically authorize its subdomains).
- Reports from a hostname that doesn't match any active `Site` are not
  discarded — they're aggregated into a separate
  `unauthorized_report_domains` table so they can be reviewed later
  (e.g. to catch a forgotten registration, a typo'd domain, or actual
  spam/abuse).
- From the admin panel, an unauthorized domain can be promoted to a
  real `Site` via a "Register as Site" action. This only affects
  future reports — the historical unauthorized entry is not
  retroactively migrated into `csp_violations`.
- Hostnames are explicitly lowercased in application code (both when
  registering a `Site` and when matching an incoming report) rather
  than relying on the database column's collation to make comparisons
  case-insensitive.
- Deactivating a `Site` (`is_active = false`) only changes where
  *future* reports land (redirected to `unauthorized_report_domains`).
  Its existing historical rows in `csp_violations` stay untouched and
  visible in the admin panel — deactivation is not retroactive.

## Data Model

### `sites`
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| domain | string, unique | exact hostname, stored lowercased |
| name | string, nullable | friendly label |
| is_active | boolean, default true | inactive sites' reports are treated as unauthorized |
| timestamps | | |

### `csp_violations`
Aggregated — one row per distinct violation shape per site, with an
occurrence counter, not one row per raw event.

| column | type | notes |
|---|---|---|
| id | bigint pk | |
| site_id | FK → sites | |
| effective_directive | string | e.g. `script-src` |
| blocked_uri | string | |
| source_file | string | normalized to `''` instead of `NULL` (see below) |
| line_number | integer, nullable → normalized to `0` | |
| column_number | integer, nullable → normalized to `0` | |
| disposition | string | `enforce` or `report` |
| document_uri | string | |
| referrer | string, nullable | |
| status_code | integer, nullable | |
| original_policy | text, nullable | |
| script_sample | text, nullable | |
| raw_sample | json | latest raw payload received for this violation, for debugging |
| occurrence_count | unsigned integer, default 1 | incremented atomically |
| first_seen_at | timestamp | set on first insert, never updated |
| last_seen_at | timestamp | updated on every occurrence |
| timestamps | | |

Unique index: `(site_id, effective_directive, blocked_uri, source_file)`.
Nullable fields participating in this key are normalized to empty
string/zero rather than left `NULL`, because MySQL treats each `NULL`
in a unique index as distinct, which would defeat deduplication.

Upsert is done as a single atomic statement
(`INSERT ... ON DUPLICATE KEY UPDATE occurrence_count = occurrence_count + 1, last_seen_at = ...`)
so concurrent Horizon workers processing the same violation shape don't
race or need explicit locking.

### `unauthorized_report_domains`
| column | type | notes |
|---|---|---|
| id | bigint pk | |
| domain | string, unique | |
| occurrence_count | unsigned integer, default 1 | |
| first_seen_at | timestamp | |
| last_seen_at | timestamp | |
| sample_raw_payload | json | |
| timestamps | | |

Same atomic-upsert approach as `csp_violations`.

## Filament Admin Panel

Single admin user (no roles/multi-tenancy needed), created via a
one-time interactive `php artisan make:filament-user` during setup
(no seeder, credentials never touch the repo).

1. **SiteResource** — manage the registry: create/edit `domain`, `name`,
   `is_active`. List shows a live violation count per site
   (`withCount`).
2. **CspViolationResource** — the main working view, default sorted by
   `last_seen_at` desc.
   - Filters: Site (select), `effective_directive` (select, a static
     hardcoded list of standard CSP directives — `script-src`,
     `style-src`, `img-src`, `connect-src`, `font-src`, `frame-src`,
     `object-src`, `media-src`, `worker-src`, `form-action`,
     `base-uri`, `frame-ancestors`, etc. — rather than a live `DISTINCT`
     query, since the directive set is small and effectively fixed,
     unlike `blocked_uri`), `disposition` (select), `blocked_uri` (text
     contains search), date range on `last_seen_at`.
   - A view page/modal renders the stored `raw_sample` JSON for full
     detail on any one violation.
3. **UnauthorizedDomainResource** — review queue for unrecognized
   domains: `domain`, `occurrence_count`, `first_seen_at`,
   `last_seen_at`. Row action **"Register as Site"** opens a prefilled
   form that creates the corresponding `Site`.

Dashboard widgets/charts (e.g. "top violated directives") are
explicitly out of scope for the initial build — easy to add later once
there's real data, not needed to meet the stated goal of filtering
violations to determine a policy.

## Queue / Horizon

- Single Horizon supervisor for the `csp-reports` queue, `balance=auto`,
  `minProcesses=1`, `maxProcesses=10` — a starting point sized for
  ~500k pageviews/month (roughly 12/min average) with headroom for
  bursts, easily tuned later from real Horizon metrics.
- Horizon dashboard (`/horizon`) gated via `Horizon::auth`, restricted
  to the authenticated admin user (same guard as Filament).
- Redis is the queue backend (`QUEUE_CONNECTION=redis`).

## Retention

A daily scheduled Artisan command prunes `csp_violations` rows where
`last_seen_at` is older than 30 days. `unauthorized_report_domains` is
not pruned by this initial design (low volume, useful as a longer-lived
audit trail — can revisit if it grows unexpectedly).

## Error Handling Summary

- Ingestion endpoint always returns `204 No Content` once a report is
  successfully queued, regardless of payload validity — CSP reporting
  is fire-and-forget, browsers do nothing with the response, and
  returning errors for bad payloads provides no value while adding
  complexity.
- A failure to *queue* the report at all (e.g. Redis is unreachable) is
  not caught in the controller — it surfaces as a `500`. Browsers
  ignore the response either way, so there's no user-facing cost, and
  this ensures a queue outage is loud in logs/error tracking rather
  than silently dropping reports with no trace.
- Malformed/unparseable report bodies, or bodies with no usable
  hostname to extract: logged as a warning, job completes successfully,
  no retry, nothing written to any table.
- Reporting API entries with a `type` other than `csp-violation` are
  silently ignored (out of scope for this app).
- Genuine transient failures (e.g. DB connection errors during upsert):
  normal Laravel job retry (3 attempts with backoff) via Horizon.

## Testing Plan

- Feature tests against `POST /csp-report`:
  - legacy `csp-report` format, known domain → row created/incremented in `csp_violations`
  - Reporting API format, single and batched reports, known domain → same
  - either format, unknown domain → row created/incremented in `unauthorized_report_domains`
  - malformed JSON → still responds 204, no rows created, warning logged
  - valid JSON with no usable hostname (missing document-uri/url) → 204, no rows created, warning logged
  - repeat identical violation → `occurrence_count` increments, `last_seen_at` updates, `first_seen_at` unchanged
  - a deactivated site's report → row created/incremented in `unauthorized_report_domains`, not `csp_violations`
  - `OPTIONS` preflight on `/csp-report` → CORS headers present, wide open origin
  - queue backend unreachable → request surfaces a `500`, no silent swallow
- Unit tests:
  - report normalizer (both formats → common internal representation)
  - domain extraction/matching (explicit lowercasing, port stripping, exact-match-only semantics)
- Filament tests:
  - "Register as Site" action on UnauthorizedDomainResource creates the expected `Site`
  - CspViolationResource filters (site, directive, disposition, blocked_uri, date range) narrow results as expected
  - deactivating a `Site` leaves its existing `csp_violations` rows visible in the resource
- Scheduled command test: prune removes `csp_violations` rows past the
  30-day retention window and leaves newer rows untouched.
