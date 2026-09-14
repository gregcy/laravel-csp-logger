# CSP Violation Logger — Production Deployment Design

## Purpose

Package the existing CSP Violation Logger application (Laravel 12 +
Filament 5 + Horizon, built per
`docs/superpowers/specs/2026-09-14-csp-logger-design.md`) as a
self-contained Docker Compose stack suitable for running on a single
VPS/server, with automatic HTTPS and no dependency on externally
managed infrastructure. The existing `compose.yaml` (Laravel Sail) is
dev-only and stays untouched — this adds a separate, production-focused
stack alongside it.

## Scope

In scope: a production-grade multi-stage Dockerfile, a
`docker-compose.prod.yml` running the full stack (app, queue worker,
scheduler, database, cache/queue backend, TLS-terminating reverse
proxy), a deploy script, and environment configuration templates.

Explicitly out of scope for this pass: database backup/snapshot
automation, multi-server or high-availability setups, and zero-downtime
deploys. This targets a single-VPS deployment for a single-admin
internal tool, where brief downtime during a deploy is acceptable.
Revisit if operational requirements change.

## Architecture & Service Layout

```
Internet
   │  :80, :443
   ▼
caddy (official image, automatic Let's Encrypt HTTPS)
   │  reverse_proxy → app:8080
   ▼
app (custom image, serversideup/php:8.4-fpm-nginx base)
   │  serves the Filament panel + POST /csp-report
   ▼
mysql (8.4) ◄──────────┐
   ▲                    │
   │                    │
redis (7, --appendonly) │
   ▲                    │
   │                    │
horizon ─────────────────┘   (same app image, command: php artisan horizon)
scheduler                    (same app image, command: php artisan schedule:work)
```

- **One built image, three roles.** `app`, `horizon`, and `scheduler`
  all run the identical image produced by the project's `Dockerfile`;
  only the container `command:` differs. This follows the pattern
  documented by the base image
  ([serversideup/docker-php](https://github.com/serversideup/docker-php))
  for exactly this Laravel deployment shape.
- **`schedule:work`, not a cron loop.** Laravel's own `schedule:work`
  command runs as a persistent foreground process and handles the
  existing daily `csp:prune` command's timing internally.
- **Built-in health checks.** The base image ships `healthcheck-horizon`
  and `healthcheck-schedule` scripts for these specific roles.
- **Only `caddy` is reachable from outside.** `app`, `horizon`,
  `scheduler`, `mysql`, and `redis` sit on an internal Docker network
  with no published ports; Caddy is the sole entry point on 80/443.
- **Redis persistence is enabled** (`--appendonly yes`) so
  queued-but-unprocessed jobs survive a Redis container restart — this
  matters because the existing ingestion design depends on Redis as a
  durable buffer between the HTTP request and the database write.

## Dockerfile

Single multi-stage Dockerfile at the repo root:

```dockerfile
FROM serversideup/php:8.4-fpm-nginx AS base
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM base AS deploy
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
USER root
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
```

- `--no-dev` excludes PHPUnit, Faker, and other dev-only Composer
  dependencies from the production image.
- No Node/Vite build stage — Filament ships its own compiled
  admin-panel assets; this app has no custom frontend requiring a JS
  build step.
- A `.dockerignore` file excludes `vendor/`, `node_modules/`, `.git/`,
  `tests/`, and other non-runtime paths from the build context.

## `docker-compose.prod.yml`

Six services:

| Service | Image | Role |
|---|---|---|
| `app` | built from repo `Dockerfile` | Serves web traffic (nginx + php-fpm) on internal port 8080; `depends_on: mysql (healthy), redis (healthy)` |
| `horizon` | same built image | `command: ["php", "/var/www/html/artisan", "horizon"]`; `stop_signal: SIGTERM`; `healthcheck: healthcheck-horizon`; `depends_on: mysql (healthy), redis (healthy)` |
| `scheduler` | same built image | `command: ["php", "/var/www/html/artisan", "schedule:work"]`; `stop_signal: SIGTERM`; `healthcheck: healthcheck-schedule`; `depends_on: mysql (healthy), redis (healthy)` |
| `mysql` | `mysql:8.4` | Persistent volume `mysql_data`; `healthcheck: mysqladmin ping` |
| `redis` | `redis:7-alpine` | `command: redis-server --appendonly yes`; persistent volume `redis_data`; `healthcheck: redis-cli ping` |
| `caddy` | `caddy:2-alpine` | Publishes `80`/`443`; mounts `docker/caddy/Caddyfile`; persistent volumes `caddy_data`/`caddy_config` |

All six services share one internal Docker network. `app`, `horizon`,
and `scheduler` additionally share a named volume `app_storage` mounted
at `/var/www/html/storage`, so logs and framework cache stay consistent
regardless of which container writes them. (This volume starts empty;
Docker automatically copies the image's existing `storage/` contents —
the required `framework/cache`, `framework/sessions`, `framework/views`,
and `logs` subdirectory skeleton — into it the first time it's mounted,
so no separate init step is needed.)

`mysql`'s and `redis`'s health checks exist specifically so `app`,
`horizon`, and `scheduler` don't start (and so `deploy.sh`'s migration
step doesn't run) against a database that hasn't finished its
first-time initialization yet — see the Deploy Workflow section.

## Caddy Configuration

`docker/caddy/Caddyfile`:

```
{$APP_DOMAIN} {
    reverse_proxy app:8080
}
```

Caddy reads the domain from the `APP_DOMAIN` environment variable and
obtains/renews a Let's Encrypt certificate automatically — no manual
certificate management.

## Secrets & Environment

A plain `.env` file on the server. Every service that needs a value
from it declares `env_file: .env` in `docker-compose.prod.yml` —
`app`, `horizon`, and `scheduler` (for the full Laravel configuration)
**and `caddy`** (specifically for `APP_DOMAIN`, which the Caddyfile
resolves via `{$APP_DOMAIN}`). `env_file:` is the standard Compose
mechanism for this: it requires no bind-mount and keeps `docker compose
run` (used for one-off commands like migrations) automatically
consistent with the long-running services, since they all read from
the same declared source. The real `.env` is never committed, and is
never `COPY`'d into the image during build (which would bake secrets
into an image layer).

`.env.production.example` is added alongside the existing dev
`.env.example`, documenting the production-specific values:
`APP_ENV=production`, `APP_DEBUG=false`, `DB_HOST=mysql`,
`REDIS_HOST=redis`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`,
`APP_DOMAIN=` (consumed by the Caddyfile), plus the existing app's
required values (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `APP_KEY`
— see the Deploy Workflow section for how `APP_KEY` is actually
generated on a fresh server).

`config/horizon.php`'s existing `production` supervisor block (built as
part of the original application) is what Horizon reads once
`APP_ENV=production` is set — no changes needed there.

## Deploy Workflow

`deploy.sh` at the repo root:

```bash
#!/usr/bin/env bash
set -euo pipefail

git pull
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d --wait mysql redis
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.prod.yml up -d
```

Migrations run explicitly, once per deploy, as their own step —
matching the earlier decision to avoid auto-migrating on every
container boot. `mysql` and `redis` are brought up first, and `up -d
--wait` blocks until their health checks (defined in
`docker-compose.prod.yml`, see the service table above) report
healthy, so the migration step never races a MySQL instance that's
still doing its first-time initialization on a fresh volume. Since `up
-d` recreates any container whose image changed, `horizon` and
`scheduler` automatically restart with the new code; no separate
restart step is needed.

**One-time setup steps** (documented in the repo, not scripted):
1. Provision the server, install Docker + Docker Compose.
2. Clone the repo.
3. Copy `.env.production.example` to `.env` and fill in what can be
   known up front: DB credentials, `APP_DOMAIN`. Leave `APP_KEY` blank
   for now — it can't be generated until the image exists (see step 5).
4. Point the domain's DNS at the server.
5. Build the image, then generate the app key using it (a throwaway
   container needs no `APP_KEY` to already be set to run this command):
   ```bash
   docker compose -f docker-compose.prod.yml build
   docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show
   ```
   Paste the printed value into `.env` as `APP_KEY=`.
6. Run `deploy.sh` for the first time (it rebuilds, which is a no-op
   since nothing changed, then brings up the full stack per the
   sequence above).
7. Create the admin user: `docker compose -f docker-compose.prod.yml
   exec app php artisan make:filament-user` — this remains a manual,
   interactive step, never automated, consistent with how the
   application's admin user was always intended to be created.
8. Register the reporting sites via the Filament admin panel
   (`SiteResource`), per the existing application design.

## Testing / Verification Plan

There is no new application code to unit-test here — this is
infrastructure packaging. Verification is a manual smoke-test sequence,
to be run once against the built stack:

- `docker compose -f docker-compose.prod.yml up -d` brings up all six
  services with no errors; `docker compose ps` shows all healthy.
- Visiting `https://<domain>/admin` serves the Filament login page over
  a valid TLS certificate (no browser warning).
- `POST https://<domain>/csp-report` with a sample legacy-format CSP
  report returns `204`.
- After registering a matching `Site`, the same POST results in a
  visible row in `CspViolationResource`'s table within a few seconds
  (proving the full HTTP → Redis → Horizon → MySQL path works in the
  containerized environment).
- `docker compose -f docker-compose.prod.yml logs horizon` shows the
  Horizon worker actively processing jobs, and the built-in
  `healthcheck-horizon` reports healthy via `docker compose ps`.
- `docker compose -f docker-compose.prod.yml logs scheduler` shows
  `schedule:work` running; `healthcheck-schedule` reports healthy.
- Stopping and restarting the `redis` container mid-flight, then
  POSTing a report before Redis is back up, should fail loudly (not
  silently) per the existing application's design — confirming the
  "never lose reports silently" behavior holds in production, not just
  in tests.
