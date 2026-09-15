# CLAUDE.md

Working context for AI agents (Claude Code or otherwise) in this repository.

## What this is

A Laravel/Filament application that ingests CSP (Content-Security-Policy)
violation reports from a set of external websites (running CSP in
report-only mode), aggregates them, and exposes a filterable admin panel
to determine what CSP policy can actually be enforced.

Full design/build history lives in `docs/superpowers/`:

- `specs/2026-09-14-csp-logger-design.md` — application design
- `specs/2026-09-14-csp-logger-production-deployment-design.md` — deployment design
- `plans/2026-09-14-csp-logger.md` — application implementation plan, task-by-task
- `plans/2026-09-14-csp-logger-deployment.md` — deployment implementation plan, including the full production runbook

Read the relevant spec/plan before making non-trivial changes. Several
design decisions here (the aggregation strategy, the domain-allowlist
model, CORS handling for the two different CSP report formats) were made
deliberately after back-and-forth with the user, not as an obvious
default — the specs record the *why*, which the code alone won't tell you.

## Architecture at a glance

- `POST /csp-report` (`routes/api.php`) — public, unauthenticated ingestion
  endpoint. Does no parsing or DB work itself; it dispatches
  `ProcessCspReportJob` and returns `204` immediately.
- `ProcessCspReportJob` — normalizes the report (legacy `report-uri` or
  Reporting API format, via `CspReportNormalizer`), extracts the hostname
  (`HostnameExtractor`), and matches it against the `sites` table (exact
  hostname, lowercased explicitly in code, `is_active` required).
- `CspViolationRecorder` — does the actual persistence via a raw
  `INSERT ... ON DUPLICATE KEY UPDATE` statement. This is MySQL-specific
  syntax — it's why the app requires MySQL (not SQLite) even in tests.
- Known + active site → aggregated row in `csp_violations`. Unknown or
  inactive site → aggregated row in `unauthorized_report_domains`,
  reviewable in Filament and promotable via a "Register as Site" action
  (which does not retroactively migrate historical data).
- Filament resources: `SiteResource` (registry), `CspViolationResource`
  (the main filtered working view), `UnauthorizedReportDomainResource`
  (review queue).
- Horizon manages the Redis-backed queue — specifically the `csp-reports`
  queue. The job's `$queue` property and `config/horizon.php`'s
  supervisors must agree on this name, or reports silently stop
  processing.
- Single admin user, no roles or multi-tenancy, by design.

## Gotchas worth knowing before touching things

- **This app requires MySQL.** `CspViolationRecorder`'s upsert logic uses
  MySQL-specific syntax. Tests run against a real `testing` MySQL
  database (`phpunit.xml`), not SQLite — intentional, not an oversight.
- **`env_file:` injection does not expand `${VAR}` placeholders.**
  Something like `APP_URL=https://${APP_DOMAIN}` inside `.env` reaches the
  `app`/`horizon`/`scheduler` containers as the literal unexpanded string,
  not an interpolated value. Any `.env` value that needs to reuse another
  variable's value must be written as a plain literal instead (see the
  comments in `.env.production.example`).
- **Domain matching is exact-hostname only**, explicitly lowercased in
  application code (never relying on database collation).
  `www.example.com` and `example.com` are different sites; registering
  one does not authorize the other.
- **Horizon's dashboard assets don't need `vendor:publish`** in the
  currently pinned version — it serves them directly from
  `vendor/laravel/horizon/dist/`. Don't reintroduce a publish step without
  checking the installed Horizon version first (this was tried and
  reverted during the deployment build — see that plan's ledger history
  in git log for the reasoning).
- **The production Docker image is one build, three roles.** `app`,
  `horizon`, and `scheduler` in `docker-compose.prod.yml` all share the
  exact same built image, differing only in their `command:`. Don't
  duplicate build logic per service.
- **The CSP report ingestion endpoint always returns `204`**, regardless
  of payload validity — this is intentional (CSP reporting is
  fire-and-forget from the browser's side). A failure to *queue* the
  report at all (e.g. Redis down) is deliberately left uncaught so it
  surfaces loudly as a 500 rather than silently dropping data.

## Common commands

Local dev (Sail):

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan test
./vendor/bin/sail artisan migrate
```

Production (see the deployment plan for the full first-time runbook):

```bash
./deploy.sh
```
