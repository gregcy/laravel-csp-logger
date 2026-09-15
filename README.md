# CSP Violation Logger

A Laravel + Filament application that ingests Content-Security-Policy (CSP)
violation reports from a set of websites running CSP in report-only mode,
aggregates them with occurrence counts, and exposes a filterable admin panel
to help determine what CSP policy can safely be enforced.

## Design docs

Full design and build history lives in `docs/superpowers/`:

- [Application design spec](docs/superpowers/specs/2026-09-14-csp-logger-design.md)
- [Production deployment design spec](docs/superpowers/specs/2026-09-14-csp-logger-production-deployment-design.md)
- [Application implementation plan](docs/superpowers/plans/2026-09-14-csp-logger.md)
- [Deployment implementation plan](docs/superpowers/plans/2026-09-14-csp-logger-deployment.md) (includes the full production runbook)

## Architecture

- **Ingestion:** `POST /csp-report` accepts both the legacy `report-uri` and
  modern Reporting API formats, queues a job, and returns `204` immediately
  — no parsing or database work happens in the request path.
- **Processing:** A Redis-backed queue (managed by Laravel Horizon) parses
  each report, matches its domain against a `sites` allowlist (exact
  hostname match), and atomically upserts an aggregated row in
  `csp_violations` — or `unauthorized_report_domains` if the domain isn't
  registered.
- **Admin panel:** Filament resources at `/admin` for managing registered
  sites, filtering violations (by site, directive, disposition, blocked
  URI, date range), and reviewing/promoting unauthorized domains.
- **Stack:** Laravel 12, Filament 5, Laravel Horizon, MySQL, Redis.

## Local development

Uses [Laravel Sail](https://laravel.com/docs/sail):

```bash
composer install
cp .env.example .env
php artisan key:generate
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan make:filament-user
```

Then visit `http://localhost/admin`, register a site via the Sites
resource, and post a sample CSP report to `http://localhost/csp-report` to
see it land in the violations table. Horizon's dashboard is at
`http://localhost/horizon`.

Run tests:

```bash
./vendor/bin/sail artisan test
```

## Production deployment

A self-contained Docker Compose stack (`docker-compose.prod.yml`) with
automatic HTTPS via Caddy. Full first-time setup is documented in the
Production Runbook section of the
[deployment plan](docs/superpowers/plans/2026-09-14-csp-logger-deployment.md).
Summary:

1. Provision a server with Docker + Docker Compose installed.
2. Clone this repo, copy `.env.production.example` to `.env`, and fill in
   DB credentials (`DB_PASSWORD` and `MYSQL_ROOT_PASSWORD` as two
   *separate* alphanumeric values), `APP_DOMAIN`, and `APP_URL` (as a
   literal `https://` + domain — leave `APP_KEY` blank for now).
3. Point the domain's DNS at the server.
4. `docker compose -f docker-compose.prod.yml build`, then generate
   `APP_KEY` via
   `docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show`
   and paste it into `.env`.
5. `./deploy.sh` — builds, waits for MySQL/Redis to be healthy, migrates,
   then brings up the full stack.
6. `docker compose -f docker-compose.prod.yml exec app php artisan make:filament-user`
   to create the admin account.
7. Log into `https://<domain>/admin` and register your reporting sites.

Every subsequent deploy is just `./deploy.sh`.

## License

The Laravel framework is open-sourced software licensed under the
[MIT license](https://opensource.org/licenses/MIT).
