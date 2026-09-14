# CSP Logger Production Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Package the existing CSP Violation Logger app as a self-contained production Docker Compose stack — custom image, containerized MySQL/Redis, Horizon/scheduler workers, and Caddy for automatic HTTPS — deployable to a single VPS with one script.

**Architecture:** One multi-stage Dockerfile (based on `serversideup/php:8.4-fpm-nginx`) produces a single image reused by three services (`app`, `horizon`, `scheduler`) that differ only in their `command:`. `docker-compose.prod.yml` wires these to containerized `mysql`/`redis` (with health checks gating startup order) and a `caddy` reverse proxy that is the only service reachable from outside. `deploy.sh` builds, waits for the database to be healthy, migrates, then brings the stack up.

**Tech Stack:** Docker, Docker Compose, `serversideup/php:8.4-fpm-nginx`, MySQL 8.4, Redis 7, Caddy 2.

**Spec:** `docs/superpowers/specs/2026-09-14-csp-logger-production-deployment-design.md`

## Global Constraints

- The existing dev-only `compose.yaml` (Laravel Sail) is untouched by this work.
- Only `caddy` publishes ports to the host (80/443); every other service is internal-network-only.
- `.env` is never committed and never baked into the image via `COPY` — it reaches containers only via Compose's `env_file:` mechanism.
- Migrations run explicitly via `deploy.sh`, never automatically on container boot.
- Redis persistence is enabled (`--appendonly yes`) so queued jobs survive a Redis restart.
- `app`, `horizon`, and `scheduler` all depend on `mysql`/`redis` reaching a healthy state before starting (`depends_on: condition: service_healthy`).

---

## Task 1: Production Dockerfile

**Files:**
- Create: `Dockerfile`
- Create: `.dockerignore`

**Interfaces:**
- Produces: a buildable image (build it locally as `csp-logger-app:test` to verify) containing the app code, production Composer dependencies only, and the PHP `redis` extension. Tasks 3-4 reference this same `Dockerfile` via `build: .` in `docker-compose.prod.yml`.

- [ ] **Step 1: Write `.dockerignore`**

Create `.dockerignore` at the repo root:

```
.git
.env
.env.example
.env.production.example
node_modules
vendor
tests
docs
.claude
compose.yaml
docker-compose.prod.yml
Dockerfile
.dockerignore
README.md
.editorconfig
.gitattributes
.gitignore
```

Excluding `vendor/` is the important one here — it guarantees the image's `vendor/` comes from a clean `composer install --no-dev` inside the build, never from whatever dev-dependency-laden `vendor/` happens to exist on the host running `docker build`.

- [ ] **Step 2: Write the Dockerfile**

Create `Dockerfile` at the repo root:

```dockerfile
FROM serversideup/php:8.4-fpm-nginx AS base

USER root
RUN install-php-extensions redis intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM base AS deploy

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

USER root
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
```

The `redis` PHP extension (phpredis) is installed explicitly because the app's `.env` configures `REDIS_CLIENT=phpredis`, and the base image doesn't bundle it by default the way the Sail dev image does. `intl` is required by `filament/support` (a hard `ext-intl` requirement in its `composer.json`) — without it, `composer install` fails outright.

- [ ] **Step 3: Build the image**

```bash
docker build -t csp-logger-app:test .
```

Expected: builds successfully with no errors. This will take a minute or two the first time (downloading the base image, installing the extension, running `composer install`).

- [ ] **Step 4: Verify the image runs and has no dev dependencies**

```bash
docker run --rm csp-logger-app:test php artisan --version
```

Expected: prints `Laravel Framework 12.x.y` with no errors about missing vendor files.

```bash
docker run --rm csp-logger-app:test test -f vendor/bin/phpunit && echo "FOUND (bad)" || echo "NOT FOUND (good)"
```

Expected: `NOT FOUND (good)` — confirms `--no-dev` actually excluded PHPUnit.

```bash
docker run --rm csp-logger-app:test php -m
```

Expected: the module list includes both `redis` and `intl` — confirms both extensions installed correctly (`intl` is a hard requirement of `filament/support`; without it `composer install` fails before this step is ever reached).

- [ ] **Step 5: Commit**

```bash
git add Dockerfile .dockerignore
git commit -m "Add production Dockerfile for the app/horizon/scheduler image"
```

---

## Task 2: Production environment template

**Files:**
- Create: `.env.production.example`

**Interfaces:**
- Produces: the template a real `.env` gets copied from during first-time server setup (per the spec's one-time setup steps). Task 3-5's `docker-compose.prod.yml` `env_file:`/`${...}` references assume a `.env` populated from this template exists at the repo root at deploy time.

- [ ] **Step 1: Write `.env.production.example`**

Create `.env.production.example`:

```
APP_NAME="CSP Violation Logger"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
# Full URL, e.g. https://csp-logger.example.com — must match APP_DOMAIN
# below (with the https:// prefix). Set as a literal value, not a
# ${APP_DOMAIN} reference: Docker's env_file mechanism (used to inject
# this file into the app/horizon/scheduler containers) does not expand
# ${...} placeholders inside .env values, so a reference here would
# reach the containers as the literal unexpanded string.
APP_URL=

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=csp_logger
DB_USERNAME=csp_logger
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis

CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Domain this app is served on (no scheme, e.g. csp-logger.example.com).
# Consumed by Caddy (docker/caddy/Caddyfile), which resolves {$APP_DOMAIN}
# from its own container's environment at runtime. Must match the domain
# used in APP_URL above.
APP_DOMAIN=
```

`LOG_LEVEL` is `warning` here (vs. `debug` in the dev `.env.example`) — production shouldn't log every debug-level line. Everything else mirrors the dev template's structure so the two stay easy to compare.

- [ ] **Step 2: Verify no real secrets are present**

```bash
grep -E "^(DB_PASSWORD|APP_KEY)=.+" .env.production.example
```

Expected: no output (both `DB_PASSWORD=` and `APP_KEY=` are blank — this is a template, not a real `.env`). Anchoring to the start of the line and naming the two fields explicitly avoids a false positive on `REDIS_PASSWORD=null`, which is a legitimate placeholder value (matching the dev `.env.example`'s own convention for "no Redis password configured"), not a secret.

- [ ] **Step 3: Commit**

```bash
git add .env.production.example
git commit -m "Add production environment template"
```

---

## Task 3: `docker-compose.prod.yml` — database layer (mysql, redis)

**Files:**
- Create: `docker-compose.prod.yml`

**Interfaces:**
- Produces: the `mysql` and `redis` services, the `csp-logger` network, and the `mysql_data`/`redis_data` named volumes, all of which Task 4's `app`/`horizon`/`scheduler` services (added to this same file) declare `depends_on: condition: service_healthy` against.

This task builds and proves out just the database layer in isolation — it's the layer every other service depends on being healthy, so it gets its own verification checkpoint before the app layer is added on top.

- [ ] **Step 1: Create a local test `.env`**

This is a throwaway local file for verifying the compose stack as you build it — it is already covered by the repo's `.gitignore` (`.env` is excluded) and must never be committed.

```bash
cp .env.production.example .env
```

Edit `.env` and fill in test values for local verification:

```
DB_DATABASE=csp_logger
DB_USERNAME=csp_logger
DB_PASSWORD=test-local-password
APP_URL=https://localhost
APP_DOMAIN=localhost
```

(Leave `APP_KEY` blank for now — Task 4 covers generating it. `APP_DOMAIN=localhost` matters later, in Task 5: Caddy treats `localhost` as a local-only name and issues itself a locally-trusted certificate instead of attempting a real Let's Encrypt request, which is what makes the HTTPS reverse proxy fully testable on a dev machine with no public domain.)

- [ ] **Step 2: Write `docker-compose.prod.yml` with the database layer**

Create `docker-compose.prod.yml`:

```yaml
services:
  mysql:
    image: mysql:8.4
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u${DB_USERNAME}", "-p${DB_PASSWORD}"]
      interval: 5s
      timeout: 5s
      retries: 20
    networks:
      - csp-logger

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: redis-server --appendonly yes
    volumes:
      - redis_data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 20
    networks:
      - csp-logger

networks:
  csp-logger:

volumes:
  mysql_data:
  redis_data:
```

`${DB_PASSWORD}` etc. here are resolved by Compose's automatic loading of the root `.env` file for the compose file's own variable substitution — a separate mechanism from the `env_file:` directive Task 4 adds to the app-layer services (that one injects variables *into a container's process environment*; this one substitutes into the YAML itself before Compose even starts anything).

- [ ] **Step 3: Bring up the database layer and verify health**

```bash
docker compose -f docker-compose.prod.yml up -d --wait mysql redis
```

Expected: command completes (doesn't hang or error) once both containers report healthy — `--wait` blocks until that happens or fails if a health check never passes.

```bash
docker compose -f docker-compose.prod.yml ps
```

Expected: both `mysql` and `redis` show status `Up (healthy)`.

- [ ] **Step 4: Tear down**

```bash
docker compose -f docker-compose.prod.yml down -v
```

(`-v` removes the volumes too — fine at this stage since there's no real data yet; Task 4 onward will use plain `down` once there's data worth keeping between test runs.)

- [ ] **Step 5: Commit**

```bash
git add docker-compose.prod.yml
git commit -m "Add docker-compose.prod.yml database layer (mysql, redis)"
```

---

## Task 4: `docker-compose.prod.yml` — app layer (app, horizon, scheduler)

**Files:**
- Modify: `docker-compose.prod.yml`

**Interfaces:**
- Consumes: `Dockerfile` (Task 1, via `build: .`); `mysql`/`redis` services and the `csp-logger` network (Task 3).
- Produces: the `app`, `horizon`, and `scheduler` services and the `app_storage` volume. Task 5's `caddy` service depends on `app`.

- [ ] **Step 1: Add the app-layer services to `docker-compose.prod.yml`**

Edit `docker-compose.prod.yml`. Add `app`, `horizon`, and `scheduler` under `services:` (alongside the existing `mysql`/`redis`), and add `app_storage` under `volumes:`:

```yaml
  app:
    build:
      context: .
      dockerfile: Dockerfile
    restart: unless-stopped
    env_file:
      - .env
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    volumes:
      - app_storage:/var/www/html/storage
    networks:
      - csp-logger

  horizon:
    build:
      context: .
      dockerfile: Dockerfile
    restart: unless-stopped
    command: ["php", "/var/www/html/artisan", "horizon"]
    stop_signal: SIGTERM
    healthcheck:
      test: ["CMD", "healthcheck-horizon"]
      start_period: 10s
    env_file:
      - .env
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    volumes:
      - app_storage:/var/www/html/storage
    networks:
      - csp-logger

  scheduler:
    build:
      context: .
      dockerfile: Dockerfile
    restart: unless-stopped
    command: ["php", "/var/www/html/artisan", "schedule:work"]
    stop_signal: SIGTERM
    healthcheck:
      test: ["CMD", "healthcheck-schedule"]
      start_period: 10s
    env_file:
      - .env
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    volumes:
      - app_storage:/var/www/html/storage
    networks:
      - csp-logger
```

Add `app_storage:` under the existing `volumes:` block (alongside `mysql_data`/`redis_data`).

`env_file: .env` here is the mechanism that gets the full set of Laravel environment variables *into* these three containers' processes — distinct from the `${DB_PASSWORD}`-style substitution used in the `mysql` service, which Compose resolves from the same physical file before the containers even start.

- [ ] **Step 2: Generate a real `APP_KEY`**

Using the local test `.env` from Task 3:

```bash
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show
```

Expected: prints a `base64:...` key. Paste it into `.env` as `APP_KEY=base64:...` (this command needs no `APP_KEY` already set to run, since it's only generating one).

- [ ] **Step 3: Bring up the full stack and run migrations**

```bash
docker compose -f docker-compose.prod.yml up -d --wait mysql redis
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
```

Expected: migration output lists all migrations (`create_users_table`, `create_sites_table`, `create_csp_violations_table`, etc.) as `DONE`, no connection errors.

```bash
docker compose -f docker-compose.prod.yml up -d
```

Expected: `app`, `horizon`, and `scheduler` all start.

- [ ] **Step 4: Verify Horizon and the scheduler are healthy**

```bash
docker compose -f docker-compose.prod.yml ps
```

Expected: `horizon` and `scheduler` both eventually show `Up (healthy)` (allow the `start_period: 10s` to elapse first).

```bash
docker compose -f docker-compose.prod.yml logs horizon
```

Expected: log output shows Horizon starting up and listing the `csp-reports` supervisor (from the existing app's `config/horizon.php`), no fatal errors.

- [ ] **Step 5: Verify the app container is up and free of startup errors**

```bash
docker compose -f docker-compose.prod.yml ps app
```

Expected: status `Up` (the `app` service has no `healthcheck:` of its own per the spec — its reachability over HTTP gets its first real proof in Task 5, once Caddy is in front of it to actually route a request in).

```bash
docker compose -f docker-compose.prod.yml logs app
```

Expected: nginx/php-fpm startup log lines, no fatal errors (e.g. no "Connection refused" to MySQL/Redis, which would indicate the `depends_on: condition: service_healthy` ordering isn't working).

- [ ] **Step 6: Tear down (keep volumes this time)**

```bash
docker compose -f docker-compose.prod.yml down
```

(No `-v` — Task 5 reuses this same database, since it now has real migrated schema and an admin-testable state worth keeping across the next task's test runs.)

- [ ] **Step 7: Commit**

```bash
git add docker-compose.prod.yml
git commit -m "Add app/horizon/scheduler services to docker-compose.prod.yml"
```

---

## Task 5: Caddy reverse proxy with automatic HTTPS

**Files:**
- Create: `docker/caddy/Caddyfile`
- Modify: `docker-compose.prod.yml`

**Interfaces:**
- Consumes: the `app` service (Task 4), reached at `app:8080` inside the `csp-logger` network.
- Produces: the `caddy` service — the only service that publishes ports to the host.

This task can fully verify the HTTPS reverse-proxy path on a dev machine with no public domain, by setting `APP_DOMAIN=localhost` — Caddy recognizes `localhost` as a local-only name and issues its own locally-trusted certificate instead of attempting a real ACME/Let's Encrypt request (which needs a real public DNS record and inbound internet access neither available nor desired for this test).

- [ ] **Step 1: Write the Caddyfile**

Create `docker/caddy/Caddyfile`:

```
{$APP_DOMAIN} {
	reverse_proxy app:8080
}
```

- [ ] **Step 2: Add the `caddy` service**

Edit `docker-compose.prod.yml`, adding under `services:`:

```yaml
  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    env_file:
      - .env
    volumes:
      - ./docker/caddy/Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    depends_on:
      - app
    networks:
      - csp-logger
```

And add `caddy_data:` and `caddy_config:` under `volumes:`.

`env_file: .env` here is what makes `APP_DOMAIN` available inside the Caddy container's process environment, which is what `{$APP_DOMAIN}` in the Caddyfile resolves against at runtime.

- [ ] **Step 3: Bring up the full stack**

Using the same local test `.env` from Tasks 3-4 (`APP_DOMAIN=localhost`):

```bash
docker compose -f docker-compose.prod.yml up -d --wait mysql redis
docker compose -f docker-compose.prod.yml up -d
```

Expected: all five services (`app`, `horizon`, `scheduler`, `mysql`, `redis`) plus the new `caddy` service start. Give Caddy a few seconds to obtain its local certificate.

- [ ] **Step 4: Verify HTTPS reverse-proxying works, from inside the network**

This avoids depending on host ports 80/443 being free (they may not be, on a shared dev machine — this exercises the exact same Caddy routing/TLS logic Docker's internal network provides regardless):

```bash
docker compose -f docker-compose.prod.yml exec caddy wget --no-check-certificate -qO- https://localhost/up
```

Expected: prints the health-check route's response body with no error (an empty or minimal body with no `wget` error is fine — the point is the request succeeds through Caddy's TLS termination and reverse proxy to the `app` container).

- [ ] **Step 5: Verify via the published host ports, if 80/443 are free locally**

```bash
curl -sk -o /dev/null -w "%{http_code}\n" https://localhost/up
```

Expected: `200`. If this instead fails to connect, check whether something else on the machine is already bound to port 80 or 443 (`docker ps` will show other unrelated containers using those ports on a shared dev machine) — that's a local-environment conflict, not a problem with this stack, and Step 4 already proved the stack itself works correctly. On the real target VPS, ports 80/443 will be free for this stack to claim.

- [ ] **Step 6: Tear down**

```bash
docker compose -f docker-compose.prod.yml down
```

- [ ] **Step 7: Commit**

```bash
git add docker-compose.prod.yml docker/caddy/Caddyfile
git commit -m "Add Caddy reverse proxy with automatic HTTPS"
```

---

## Task 6: Deploy script and full local dry run

**Files:**
- Create: `deploy.sh`

**Interfaces:**
- Consumes: everything from Tasks 1-5 (`Dockerfile`, `docker-compose.prod.yml`, `.env`).
- Produces: the one command (`./deploy.sh`) a real server runs for every deploy, including the first one.

- [ ] **Step 1: Write `deploy.sh`**

Create `deploy.sh` at the repo root:

```bash
#!/usr/bin/env bash
set -euo pipefail

git pull
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d --wait mysql redis
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.prod.yml up -d
```

```bash
chmod +x deploy.sh
```

- [ ] **Step 2: Dry-run the full script against a clean local stack**

Starting from a torn-down state (`docker compose -f docker-compose.prod.yml down -v` if anything from earlier tasks is still up), with the same local test `.env` from Tasks 3-5 (`APP_KEY` and `APP_DOMAIN=localhost` already set):

```bash
./deploy.sh
```

Expected: `git pull` reports something like `Already up to date.` (there's nothing new to pull in a local checkout — this is expected and fine); the build completes; `mysql`/`redis` become healthy; migrations run and print `DONE` for every migration; the full stack comes up.

```bash
docker compose -f docker-compose.prod.yml ps
```

Expected: all six services `Up` (`horizon`/`scheduler`/`mysql`/`redis` additionally `(healthy)`).

- [ ] **Step 3: Re-run the script to confirm it's safe to run repeatedly**

```bash
./deploy.sh
```

Expected: completes cleanly again — migrations report nothing new to run (`Nothing to migrate.`), no errors from re-creating already-running containers with an unchanged image.

- [ ] **Step 4: Verify a CSP report actually flows end-to-end through the deployed stack**

Task 5 already proved Caddy correctly terminates TLS and reverse-proxies to `app`. This step proves the other half — that a report ingested by the containerized `app` actually makes it through Redis, gets picked up by the containerized `horizon`, and lands in the containerized `mysql` — using Laravel's own HTTP client from inside the `app` container (guaranteed present; no assumptions about which HTTP tools happen to be installed in any particular base image), talking to the app's own nginx on `localhost:8080` directly.

Register a `Site` for `example.com`:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute="\App\Models\Site::create(['domain' => 'example.com', 'is_active' => true]);"
```

POST a sample legacy-format CSP report:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute="
\$body = json_encode(['csp-report' => [
    'document-uri' => 'https://example.com/page',
    'effective-directive' => 'script-src',
    'blocked-uri' => 'https://evil.example/script.js',
    'disposition' => 'report',
]]);
\$response = \Illuminate\Support\Facades\Http::withBody(\$body, 'application/csp-report')
    ->post('http://localhost:8080/csp-report');
echo \$response->status();
"
```

Expected: `204`.

Confirm the row landed (allow a second or two for Horizon to pick up the queued job):

```bash
sleep 2
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute="echo \App\Models\CspViolation::count();"
```

Expected: `1` — confirming the full path (app → Redis queue → Horizon worker → `CspViolationRecorder` → MySQL) works inside the containerized stack, not just in the original app's isolated test suite.

- [ ] **Step 5: Tear down the local test stack**

```bash
docker compose -f docker-compose.prod.yml down -v
rm .env
```

(Removes the local test `.env` used throughout Tasks 3-6 — it held a throwaway local password and `APP_DOMAIN=localhost`, not anything meant to persist.)

- [ ] **Step 6: Commit**

```bash
git add deploy.sh
git commit -m "Add deploy.sh"
```

## Production Runbook (manual, one-time — not part of this plan's automated verification)

This can't be exercised locally (it needs a real server and a real public
domain for Let's Encrypt), so it's documentation for whoever runs the
first real deploy, not a task with a pass/fail check:

1. Provision the server, install Docker + Docker Compose.
2. Clone the repo.
3. Copy `.env.production.example` to `.env`; fill in `DB_DATABASE`,
   `DB_USERNAME`, a real `DB_PASSWORD`, a real `MYSQL_ROOT_PASSWORD`
   (a separate value from `DB_PASSWORD` — it's the MySQL server's root
   credential, not the application's), `APP_DOMAIN` (the real public
   domain, not `localhost`), and `APP_URL` (`https://` + that same
   domain — set as a literal value, since `${APP_DOMAIN}`-style
   references inside `.env` do not get expanded when Docker injects
   this file into the containers). Leave `APP_KEY` blank. For
   `DB_PASSWORD` and `MYSQL_ROOT_PASSWORD` specifically, use a purely
   alphanumeric password (e.g. `openssl rand -hex 24`) — these values
   are consumed both via Docker Compose's `${VAR}` YAML interpolation
   (in the `mysql` service's `environment:` and healthcheck) and via
   literal `env_file:` injection into `app`/`horizon`/`scheduler`, and
   characters like `$`, `#`, or unquoted spaces can be interpreted
   differently by the two mechanisms, causing the app and database to
   silently disagree about the password.
4. Point the domain's DNS at the server.
5. `docker compose -f docker-compose.prod.yml build`, then
   `docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show`
   and paste the result into `.env` as `APP_KEY=`.
6. Run `./deploy.sh`.
7. `docker compose -f docker-compose.prod.yml exec app php artisan make:filament-user`
   — create the real admin account interactively.
8. Log into `https://<domain>/admin` and register the reporting sites
   via the Sites resource.

