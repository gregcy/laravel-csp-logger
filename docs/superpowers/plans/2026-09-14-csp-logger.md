# CSP Violation Logger Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Laravel/Filament application that ingests CSP violation reports (both legacy `report-uri` and Reporting API formats) from an allowlist of registered sites, queues them durably via Redis/Horizon, aggregates them with occurrence counts, and exposes a filterable Filament admin panel.

**Architecture:** A public, unauthenticated `POST /csp-report` endpoint does nothing but dispatch a queued job and return `204`. The job normalizes either report format into a common DTO, resolves the reporting domain against a `sites` allowlist, and atomically upserts into either `csp_violations` (known site) or `unauthorized_report_domains` (unknown/inactive site). Filament provides three resources (Sites, CSP Violations, Unauthorized Domains) over this data, and Horizon manages/monitors the Redis-backed queue.

**Tech Stack:** Laravel 12.x, PHP 8.3+, Filament 5.x, MySQL, Redis, Laravel Horizon, Laravel Sail, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-14-csp-logger-design.md`

## Global Constraints

- Database is MySQL — the upsert logic in this plan uses `INSERT ... ON DUPLICATE KEY UPDATE`, which is MySQL-specific syntax, so tests must run against MySQL (not SQLite) for that logic to be validated for real.
- Queue connection is Redis (`QUEUE_CONNECTION=redis`), managed by Horizon.
- Domain matching is **exact hostname**, explicitly lowercased in application code (never relying on DB collation).
- The ingestion endpoint always returns `204 No Content` for any parseable-enough request; it never returns validation errors for bad report bodies.
- `routes/api.php` does not exist in a fresh Laravel 12 skeleton and must be added by hand (no `php artisan install:api`, to avoid pulling in unused Sanctum scaffolding).
- Single Filament admin user — no roles/multi-tenancy.

---

## Task 1: Scaffold the Laravel application with Sail (MySQL + Redis)

**Files:**
- Create: entire Laravel skeleton at repo root (`app/`, `bootstrap/`, `config/`, `routes/`, `composer.json`, `docker-compose.yml`, `.env`, etc.)
- Preserve: `docs/superpowers/` (already committed — must not be deleted or overwritten)

This repo currently has only a `README.md` (empty) and the `docs/superpowers/` tree. The Laravel installer refuses to scaffold into a non-empty directory, so we scaffold into a temp directory and copy the result in.

**Interfaces:**
- Produces: a working Laravel 12 app, bootable via `./vendor/bin/sail up -d`, with `mysql` and `redis` services, ready for all later tasks to add code into `app/`, `routes/`, `database/`, `config/`.

- [ ] **Step 1: Verify Docker is available**

Sail is Docker-based — every step from Step 5 onward depends on a running Docker daemon.

```bash
docker info
```

Expected: prints daemon/server info without error. If this fails, install/start Docker Desktop (or your Docker engine of choice) before continuing — don't proceed to scaffolding until this succeeds.

- [ ] **Step 2: Scaffold Laravel into a temp directory**

```bash
composer create-project laravel/laravel:^12.0 /tmp/csp-logger-scaffold
```

Expected: completes with "Application ready" message, `/tmp/csp-logger-scaffold` contains a full Laravel skeleton including `vendor/`, `.env`, and an auto-generated `APP_KEY`.

- [ ] **Step 3: Copy the scaffold into the repo root, preserving existing files**

```bash
rsync -a --exclude='.git' /tmp/csp-logger-scaffold/ /home/greg/workspace/laravel-csp-logger/
rm -rf /tmp/csp-logger-scaffold
```

Expected: `ls /home/greg/workspace/laravel-csp-logger` now shows `app/`, `bootstrap/`, `routes/`, `composer.json`, `.env`, etc., alongside the pre-existing `docs/superpowers/` directory (untouched — `rsync` only adds/overwrites files present in the source, and the scaffold has no `docs/` directory to collide with). The scaffold's own `README.md` overwrites the empty placeholder one, which is fine since it had no content.

- [ ] **Step 4: Verify the base install**

```bash
php artisan --version
```

Expected: `Laravel Framework 12.x.y`

- [ ] **Step 5: Add Sail with MySQL and Redis services**

```bash
php artisan sail:install --with=mysql,redis --no-interaction
```

Expected: creates/updates `docker-compose.yml` with `laravel.test`, `mysql`, and `redis` services, and updates `.env` (`DB_CONNECTION=mysql`, `DB_HOST=mysql`, `REDIS_HOST=redis`, etc.).

- [ ] **Step 6: Bring the stack up and verify connectivity**

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

Expected: containers start, and the default `users`/`cache`/`jobs` migrations run successfully against the `mysql` container with no connection errors.

- [ ] **Step 7: Set the queue connection to Redis**

Edit `.env` and `.env.example`, changing:

```
QUEUE_CONNECTION=database
```

to:

```
QUEUE_CONNECTION=redis
```

- [ ] **Step 8: Configure the testing database connection**

Because Task 6 onward relies on MySQL-specific `ON DUPLICATE KEY UPDATE` syntax, tests must run against MySQL, not the SQLite in-memory default. Edit `phpunit.xml`, and inside the `<php>` block, ensure/replace:

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="testing"/>
```

(remove any pre-existing `DB_CONNECTION` value="sqlite" or `DB_DATABASE` value=":memory:" lines).

Create the testing database:

```bash
./vendor/bin/sail mysql -e "CREATE DATABASE IF NOT EXISTS testing;"
```

Expected: no error. `./vendor/bin/sail artisan test` now runs (with zero tests yet) against MySQL without connection errors.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "Scaffold Laravel 12 app with Sail (MySQL + Redis)"
```

---

## Task 2: Install Filament and Horizon

**Files:**
- Modify: `composer.json`, `.env`, `.env.example`
- Create: `app/Providers/Filament/AdminPanelProvider.php` (generated), `config/horizon.php` (generated)

**Interfaces:**
- Produces: `/admin` (Filament panel, empty of resources until Tasks 10-12), `/horizon` (dashboard, unrestricted until Task 13 adds the auth gate).

- [ ] **Step 1: Require and install Filament**

```bash
./vendor/bin/sail composer require filament/filament:"^5.0"
./vendor/bin/sail artisan filament:install --panels
```

Expected: creates `app/Providers/Filament/AdminPanelProvider.php` registering a panel at path `admin`.

- [ ] **Step 2: MANUAL CHECKPOINT — create the one-time admin user**

**Stop here regardless of execution mode.** This command is interactive — it prompts for name/email/password — and credentials must never pass through an automated subagent or a scripted plan step. If you're running this plan via a dispatched subagent, the subagent should stop at this exact step and ask you to run the following yourself (in your own terminal, or via `!` in this session), then confirm back before it continues:

```bash
./vendor/bin/sail artisan make:filament-user
```

Expected: a row is created in the `users` table; you can log in at `/admin` once the panel has resources.

- [ ] **Step 3: Require and install Horizon**

```bash
./vendor/bin/sail composer require laravel/horizon
./vendor/bin/sail artisan horizon:install
```

Expected: creates `config/horizon.php`, publishes Horizon's public assets.

- [ ] **Step 4: Verify Horizon boots**

```bash
./vendor/bin/sail artisan horizon:status
```

Expected: `Horizon is inactive.` (it's not running yet, but the command resolving without error confirms the package is wired up).

For local development, run Horizon in its own terminal whenever you want queued jobs to actually process:

```bash
./vendor/bin/sail artisan horizon
```

This plan does not cover keeping Horizon running persistently in production (e.g. supervisor/systemd config, or a dedicated docker-compose service) — that's environment/deployment-specific and belongs to wherever this app is eventually hosted, not to this repo-focused implementation plan.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Install Filament panel and Laravel Horizon"
```

---

## Task 3: Migrations and models for sites, csp_violations, unauthorized_report_domains

**Files:**
- Create: `database/migrations/<timestamp>_create_sites_table.php`
- Create: `database/migrations/<timestamp>_create_csp_violations_table.php`
- Create: `database/migrations/<timestamp>_create_unauthorized_report_domains_table.php`
- Create: `app/Models/Site.php`
- Create: `app/Models/CspViolation.php`
- Create: `app/Models/UnauthorizedReportDomain.php`
- Test: `tests/Feature/Models/SiteTest.php`
- Test: `tests/Feature/Models/CspViolationTest.php`

**Interfaces:**
- Produces:
  - `Site` model: `domain` (string), `name` (?string), `is_active` (bool), `violations()` hasMany `CspViolation`.
  - `CspViolation` model: all columns per spec's data model table.
  - `UnauthorizedReportDomain` model: all columns per spec's data model table.
  - Both dedup tables carry a DB-level unique constraint later tasks rely on for atomic upserts.

- [ ] **Step 1: Write the failing test for the `sites` unique constraint**

Create `tests/Feature/Models/SiteTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_must_be_unique(): void
    {
        Site::create(['domain' => 'example.com', 'name' => 'Example', 'is_active' => true]);

        $this->expectException(QueryException::class);

        Site::create(['domain' => 'example.com', 'name' => 'Duplicate', 'is_active' => true]);
    }

    public function test_is_active_defaults_to_true(): void
    {
        $site = Site::create(['domain' => 'example.com', 'name' => 'Example']);

        $this->assertTrue($site->fresh()->is_active);
    }

    public function test_domain_is_stored_lowercase(): void
    {
        $site = Site::create(['domain' => 'Example.COM', 'is_active' => true]);

        $this->assertSame('example.com', $site->fresh()->domain);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=SiteTest
```

Expected: FAIL — `Class "App\Models\Site" not found` (no model or migration exists yet).

- [ ] **Step 3: Create the `sites` migration**

```bash
./vendor/bin/sail artisan make:migration create_sites_table
```

Replace its contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
```

- [ ] **Step 4: Create the `Site` model**

Create `app/Models/Site.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory;

    protected $fillable = ['domain', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Site $site) {
            $site->domain = strtolower($site->domain);
        });
    }

    public function violations(): HasMany
    {
        return $this->hasMany(CspViolation::class);
    }
}
```

- [ ] **Step 5: Run migrations and the test again**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test --filter=SiteTest
```

Expected: PASS (3 tests).

- [ ] **Step 6: Write the failing test for the `csp_violations` dedup constraint**

Create `tests/Feature/Models/CspViolationTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Models\CspViolation;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CspViolationTest extends TestCase
{
    use RefreshDatabase;

    private function baseAttributes(Site $site): array
    {
        return [
            'site_id' => $site->id,
            'effective_directive' => 'script-src',
            'blocked_uri' => 'https://evil.example/script.js',
            'source_file' => '',
            'line_number' => 0,
            'column_number' => 0,
            'disposition' => 'report',
            'document_uri' => 'https://example.com/page',
            'raw_sample' => json_encode(['csp-report' => []]),
            'occurrence_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function test_dedup_key_is_unique_per_site_directive_blocked_uri_and_source_file(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);

        CspViolation::create($this->baseAttributes($site));

        $this->expectException(QueryException::class);

        CspViolation::create($this->baseAttributes($site));
    }

    public function test_belongs_to_site(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);
        $violation = CspViolation::create($this->baseAttributes($site));

        $this->assertTrue($violation->site->is($site));
    }
}
```

- [ ] **Step 7: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=CspViolationTest
```

Expected: FAIL — `Class "App\Models\CspViolation" not found`.

- [ ] **Step 8: Create the `csp_violations` migration**

```bash
./vendor/bin/sail artisan make:migration create_csp_violations_table
```

Replace its contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csp_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('effective_directive');
            $table->string('blocked_uri');
            $table->string('source_file')->default('');
            $table->unsignedInteger('line_number')->default(0);
            $table->unsignedInteger('column_number')->default(0);
            $table->string('disposition');
            $table->string('document_uri');
            $table->string('referrer')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('original_policy')->nullable();
            $table->text('script_sample')->nullable();
            $table->json('raw_sample');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(
                ['site_id', 'effective_directive', 'blocked_uri', 'source_file'],
                'csp_violations_dedup_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csp_violations');
    }
};
```

- [ ] **Step 9: Create the `CspViolation` model**

Create `app/Models/CspViolation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CspViolation extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id', 'effective_directive', 'blocked_uri', 'source_file',
        'line_number', 'column_number', 'disposition', 'document_uri',
        'referrer', 'status_code', 'original_policy', 'script_sample',
        'raw_sample', 'occurrence_count', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'raw_sample' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
```

- [ ] **Step 10: Run migrations and the test again**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test --filter=CspViolationTest
```

Expected: PASS (2 tests).

- [ ] **Step 11: Create the `unauthorized_report_domains` migration (no test — mirrors the pattern just proven)**

```bash
./vendor/bin/sail artisan make:migration create_unauthorized_report_domains_table
```

Replace its contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unauthorized_report_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->json('sample_raw_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unauthorized_report_domains');
    }
};
```

- [ ] **Step 12: Create the `UnauthorizedReportDomain` model**

Create `app/Models/UnauthorizedReportDomain.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnauthorizedReportDomain extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain', 'occurrence_count', 'first_seen_at', 'last_seen_at', 'sample_raw_payload',
    ];

    protected $casts = [
        'sample_raw_payload' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
```

- [ ] **Step 13: Run migrations and the full test suite**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test
```

Expected: all tests pass.

- [ ] **Step 14: Commit**

```bash
git add -A
git commit -m "Add sites, csp_violations, unauthorized_report_domains schema and models"
```

---

## Task 4: `HostnameExtractor` service

**Files:**
- Create: `app/Services/Csp/HostnameExtractor.php`
- Test: `tests/Unit/Services/Csp/HostnameExtractorTest.php`

**Interfaces:**
- Produces: `HostnameExtractor::extract(?string $uri): ?string` — a static method returning a lowercased hostname with no port, or `null` if none can be extracted. Task 6 (normalizer isn't involved) and Task 7 (job) call this directly.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/Csp/HostnameExtractorTest.php`:

```php
<?php

namespace Tests\Unit\Services\Csp;

use App\Services\Csp\HostnameExtractor;
use PHPUnit\Framework\TestCase;

class HostnameExtractorTest extends TestCase
{
    public function test_extracts_lowercase_hostname(): void
    {
        $this->assertSame('example.com', HostnameExtractor::extract('https://EXAMPLE.com/page'));
    }

    public function test_strips_port(): void
    {
        $this->assertSame('example.com', HostnameExtractor::extract('https://example.com:8080/page'));
    }

    public function test_returns_null_for_missing_uri(): void
    {
        $this->assertNull(HostnameExtractor::extract(null));
    }

    public function test_returns_null_for_empty_string(): void
    {
        $this->assertNull(HostnameExtractor::extract(''));
    }

    public function test_returns_null_for_unparseable_uri(): void
    {
        $this->assertNull(HostnameExtractor::extract('not a url at all ::::'));
    }

    public function test_returns_null_when_uri_has_no_host(): void
    {
        $this->assertNull(HostnameExtractor::extract('/relative/path'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=HostnameExtractorTest
```

Expected: FAIL — `Class "App\Services\Csp\HostnameExtractor" not found`.

- [ ] **Step 3: Implement `HostnameExtractor`**

Create `app/Services/Csp/HostnameExtractor.php`:

```php
<?php

namespace App\Services\Csp;

class HostnameExtractor
{
    public static function extract(?string $uri): ?string
    {
        if ($uri === null || trim($uri) === '') {
            return null;
        }

        $host = parse_url($uri, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }
}
```

- [ ] **Step 4: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=HostnameExtractorTest
```

Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add HostnameExtractor service"
```

---

## Task 5: `NormalizedCspReport` DTO and `CspReportNormalizer` service

**Files:**
- Create: `app/Services/Csp/NormalizedCspReport.php`
- Create: `app/Services/Csp/CspReportParseException.php`
- Create: `app/Services/Csp/CspReportNormalizer.php`
- Test: `tests/Unit/Services/Csp/CspReportNormalizerTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `NormalizedCspReport` — readonly DTO with public properties: `documentUri` (?string), `referrer` (?string), `violatedDirective` (?string), `effectiveDirective` (?string), `originalPolicy` (?string), `disposition` (?string), `blockedUri` (?string), `statusCode` (?int), `scriptSample` (?string), `sourceFile` (?string), `lineNumber` (?int), `columnNumber` (?int), `raw` (array — the single decoded report object, for storage as `raw_sample`).
  - `CspReportParseException extends \RuntimeException`.
  - `CspReportNormalizer::normalize(string $rawBody, ?string $contentType): array` — returns `NormalizedCspReport[]` (possibly empty for a Reporting API batch containing no `csp-violation` entries), throws `CspReportParseException` for unparseable/malformed input. Task 7's job calls this and catches `CspReportParseException`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/Csp/CspReportNormalizerTest.php`:

```php
<?php

namespace Tests\Unit\Services\Csp;

use App\Services\Csp\CspReportNormalizer;
use App\Services\Csp\CspReportParseException;
use PHPUnit\Framework\TestCase;

class CspReportNormalizerTest extends TestCase
{
    private CspReportNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new CspReportNormalizer();
    }

    public function test_parses_legacy_report_uri_format(): void
    {
        $body = json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/page',
                'referrer' => 'https://example.com/',
                'violated-directive' => 'script-src',
                'effective-directive' => 'script-src',
                'original-policy' => "default-src 'self'",
                'disposition' => 'report',
                'blocked-uri' => 'https://evil.example/script.js',
                'status-code' => 200,
                'script-sample' => '',
                'source-file' => 'https://example.com/page',
                'line-number' => 10,
                'column-number' => 5,
            ],
        ]);

        $reports = $this->normalizer->normalize($body, 'application/csp-report');

        $this->assertCount(1, $reports);
        $report = $reports[0];
        $this->assertSame('https://example.com/page', $report->documentUri);
        $this->assertSame('script-src', $report->effectiveDirective);
        $this->assertSame('https://evil.example/script.js', $report->blockedUri);
        $this->assertSame(10, $report->lineNumber);
        $this->assertSame(5, $report->columnNumber);
        $this->assertSame('https://example.com/page', $report->sourceFile);
    }

    public function test_parses_reporting_api_single_report(): void
    {
        $body = json_encode([[
            'type' => 'csp-violation',
            'url' => 'https://example.com/page',
            'body' => [
                'documentURL' => 'https://example.com/page',
                'referrer' => 'https://example.com/',
                'violatedDirective' => 'script-src',
                'effectiveDirective' => 'script-src',
                'originalPolicy' => "default-src 'self'",
                'disposition' => 'report',
                'blockedURL' => 'https://evil.example/script.js',
                'statusCode' => 200,
                'sample' => '',
                'sourceFile' => 'https://example.com/page',
                'lineNumber' => 10,
                'columnNumber' => 5,
            ],
        ]]);

        $reports = $this->normalizer->normalize($body, 'application/reports+json');

        $this->assertCount(1, $reports);
        $report = $reports[0];
        $this->assertSame('https://example.com/page', $report->documentUri);
        $this->assertSame('script-src', $report->effectiveDirective);
        $this->assertSame('https://evil.example/script.js', $report->blockedUri);
    }

    public function test_parses_reporting_api_batch_and_ignores_non_csp_types(): void
    {
        $body = json_encode([
            [
                'type' => 'deprecation',
                'url' => 'https://example.com/page',
                'body' => ['id' => 'SomeDeprecatedFeature'],
            ],
            [
                'type' => 'csp-violation',
                'url' => 'https://example.com/page',
                'body' => [
                    'documentURL' => 'https://example.com/page',
                    'effectiveDirective' => 'img-src',
                    'blockedURL' => 'https://tracker.example/pixel.gif',
                    'disposition' => 'report',
                ],
            ],
        ]);

        $reports = $this->normalizer->normalize($body, 'application/reports+json');

        $this->assertCount(1, $reports);
        $this->assertSame('img-src', $reports[0]->effectiveDirective);
    }

    public function test_reporting_api_batch_with_no_csp_entries_returns_empty_array(): void
    {
        $body = json_encode([
            ['type' => 'deprecation', 'url' => 'https://example.com/page', 'body' => []],
        ]);

        $reports = $this->normalizer->normalize($body, 'application/reports+json');

        $this->assertSame([], $reports);
    }

    public function test_throws_on_invalid_json(): void
    {
        $this->expectException(CspReportParseException::class);

        $this->normalizer->normalize('{not valid json', 'application/csp-report');
    }

    public function test_throws_when_legacy_content_type_missing_csp_report_key(): void
    {
        $this->expectException(CspReportParseException::class);

        $this->normalizer->normalize(json_encode(['foo' => 'bar']), 'application/csp-report');
    }

    public function test_falls_back_to_body_shape_when_content_type_is_missing(): void
    {
        $body = json_encode(['csp-report' => ['document-uri' => 'https://example.com/page']]);

        $reports = $this->normalizer->normalize($body, null);

        $this->assertCount(1, $reports);
        $this->assertSame('https://example.com/page', $reports[0]->documentUri);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=CspReportNormalizerTest
```

Expected: FAIL — `Class "App\Services\Csp\CspReportNormalizer" not found`.

- [ ] **Step 3: Implement the DTO and exception**

Create `app/Services/Csp/NormalizedCspReport.php`:

```php
<?php

namespace App\Services\Csp;

final class NormalizedCspReport
{
    public function __construct(
        public readonly ?string $documentUri,
        public readonly ?string $referrer,
        public readonly ?string $violatedDirective,
        public readonly ?string $effectiveDirective,
        public readonly ?string $originalPolicy,
        public readonly ?string $disposition,
        public readonly ?string $blockedUri,
        public readonly ?int $statusCode,
        public readonly ?string $scriptSample,
        public readonly ?string $sourceFile,
        public readonly ?int $lineNumber,
        public readonly ?int $columnNumber,
        public readonly array $raw,
    ) {
    }
}
```

Create `app/Services/Csp/CspReportParseException.php`:

```php
<?php

namespace App\Services\Csp;

class CspReportParseException extends \RuntimeException
{
}
```

- [ ] **Step 4: Implement `CspReportNormalizer`**

Create `app/Services/Csp/CspReportNormalizer.php`:

```php
<?php

namespace App\Services\Csp;

class CspReportNormalizer
{
    /**
     * @return NormalizedCspReport[]
     */
    public function normalize(string $rawBody, ?string $contentType): array
    {
        $decoded = json_decode($rawBody, associative: true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CspReportParseException('Request body is not valid JSON: '.json_last_error_msg());
        }

        $isReportingApi = $contentType !== null && str_contains($contentType, 'reports+json');
        $isLegacy = $contentType !== null && str_contains($contentType, 'csp-report');

        if (! $isReportingApi && ! $isLegacy) {
            // No usable Content-Type — fall back to sniffing the decoded body's shape.
            $isReportingApi = is_array($decoded) && array_is_list($decoded);
            $isLegacy = is_array($decoded) && array_key_exists('csp-report', $decoded);
        }

        if ($isLegacy) {
            return [$this->normalizeLegacy($decoded)];
        }

        if ($isReportingApi) {
            return $this->normalizeReportingApiBatch($decoded);
        }

        throw new CspReportParseException('Unable to determine CSP report format from Content-Type or body shape.');
    }

    private function normalizeLegacy(mixed $decoded): NormalizedCspReport
    {
        if (! is_array($decoded) || ! array_key_exists('csp-report', $decoded) || ! is_array($decoded['csp-report'])) {
            throw new CspReportParseException('Legacy report body is missing the "csp-report" object.');
        }

        $report = $decoded['csp-report'];

        return new NormalizedCspReport(
            documentUri: $report['document-uri'] ?? null,
            referrer: $report['referrer'] ?? null,
            violatedDirective: $report['violated-directive'] ?? null,
            effectiveDirective: $report['effective-directive'] ?? $report['violated-directive'] ?? null,
            originalPolicy: $report['original-policy'] ?? null,
            disposition: $report['disposition'] ?? null,
            blockedUri: $report['blocked-uri'] ?? null,
            statusCode: isset($report['status-code']) ? (int) $report['status-code'] : null,
            scriptSample: $report['script-sample'] ?? null,
            sourceFile: $report['source-file'] ?? null,
            lineNumber: isset($report['line-number']) ? (int) $report['line-number'] : null,
            columnNumber: isset($report['column-number']) ? (int) $report['column-number'] : null,
            raw: $decoded,
        );
    }

    /**
     * @return NormalizedCspReport[]
     */
    private function normalizeReportingApiBatch(mixed $decoded): array
    {
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new CspReportParseException('Reporting API body must be a JSON array.');
        }

        $reports = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'csp-violation') {
                continue;
            }

            $body = is_array($entry['body'] ?? null) ? $entry['body'] : [];

            $reports[] = new NormalizedCspReport(
                documentUri: $body['documentURL'] ?? $entry['url'] ?? null,
                referrer: $body['referrer'] ?? null,
                violatedDirective: $body['violatedDirective'] ?? null,
                effectiveDirective: $body['effectiveDirective'] ?? $body['violatedDirective'] ?? null,
                originalPolicy: $body['originalPolicy'] ?? null,
                disposition: $body['disposition'] ?? null,
                blockedUri: $body['blockedURL'] ?? null,
                statusCode: isset($body['statusCode']) ? (int) $body['statusCode'] : null,
                scriptSample: $body['sample'] ?? null,
                sourceFile: $body['sourceFile'] ?? null,
                lineNumber: isset($body['lineNumber']) ? (int) $body['lineNumber'] : null,
                columnNumber: isset($body['columnNumber']) ? (int) $body['columnNumber'] : null,
                raw: $entry,
            );
        }

        return $reports;
    }
}
```

- [ ] **Step 5: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=CspReportNormalizerTest
```

Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add CspReportNormalizer and NormalizedCspReport DTO"
```

---

## Task 6: `CspViolationRecorder` — atomic upsert into `csp_violations` / `unauthorized_report_domains`

**Files:**
- Create: `app/Services/Csp/CspViolationRecorder.php`
- Test: `tests/Feature/Services/Csp/CspViolationRecorderTest.php`

**Interfaces:**
- Consumes: `App\Models\Site`, `App\Models\CspViolation`, `App\Models\UnauthorizedReportDomain` (Task 3); `App\Services\Csp\NormalizedCspReport` (Task 5).
- Produces: `CspViolationRecorder::recordKnown(Site $site, NormalizedCspReport $report): void` and `CspViolationRecorder::recordUnauthorized(string $domain, NormalizedCspReport $report): void`. Task 7's job calls both.

This is where the spec's nullable-field normalization (`''`/`0` instead of `NULL`, so the unique index dedupes correctly) and the atomic `INSERT ... ON DUPLICATE KEY UPDATE` both live.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Services/Csp/CspViolationRecorderTest.php`:

```php
<?php

namespace Tests\Feature\Services\Csp;

use App\Models\CspViolation;
use App\Models\Site;
use App\Models\UnauthorizedReportDomain;
use App\Services\Csp\CspViolationRecorder;
use App\Services\Csp\NormalizedCspReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CspViolationRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $overrides = []): NormalizedCspReport
    {
        $defaults = [
            'documentUri' => 'https://example.com/page',
            'referrer' => null,
            'violatedDirective' => 'script-src',
            'effectiveDirective' => 'script-src',
            'originalPolicy' => "default-src 'self'",
            'disposition' => 'report',
            'blockedUri' => 'https://evil.example/script.js',
            'statusCode' => 200,
            'scriptSample' => null,
            'sourceFile' => null,
            'lineNumber' => null,
            'columnNumber' => null,
            'raw' => ['csp-report' => ['blocked-uri' => 'https://evil.example/script.js']],
        ];

        $attrs = array_merge($defaults, $overrides);

        return new NormalizedCspReport(...$attrs);
    }

    public function test_records_a_new_known_violation(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);
        $recorder = new CspViolationRecorder();

        $recorder->recordKnown($site, $this->report());

        $this->assertDatabaseCount('csp_violations', 1);
        $violation = CspViolation::first();
        $this->assertSame($site->id, $violation->site_id);
        $this->assertSame('', $violation->source_file);
        $this->assertSame(0, $violation->line_number);
        $this->assertSame(0, $violation->column_number);
        $this->assertSame(1, $violation->occurrence_count);
        $this->assertNotNull($violation->first_seen_at);
        $this->assertNotNull($violation->last_seen_at);
    }

    public function test_repeat_identical_violation_increments_count_and_bumps_last_seen_only(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);
        $recorder = new CspViolationRecorder();

        $recorder->recordKnown($site, $this->report());
        $firstSeenAt = CspViolation::first()->first_seen_at;

        sleep(1);
        $recorder->recordKnown($site, $this->report());

        $this->assertDatabaseCount('csp_violations', 1);
        $violation = CspViolation::first();
        $this->assertSame(2, $violation->occurrence_count);
        $this->assertEquals($firstSeenAt, $violation->first_seen_at);
        $this->assertTrue($violation->last_seen_at->gt($firstSeenAt));
    }

    public function test_different_blocked_uri_creates_a_separate_row(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);
        $recorder = new CspViolationRecorder();

        $recorder->recordKnown($site, $this->report());
        $recorder->recordKnown($site, $this->report(['blockedUri' => 'https://other.example/x.js']));

        $this->assertDatabaseCount('csp_violations', 2);
    }

    public function test_records_a_new_unauthorized_domain(): void
    {
        $recorder = new CspViolationRecorder();

        $recorder->recordUnauthorized('unknown.example', $this->report());

        $this->assertDatabaseCount('unauthorized_report_domains', 1);
        $row = UnauthorizedReportDomain::first();
        $this->assertSame('unknown.example', $row->domain);
        $this->assertSame(1, $row->occurrence_count);
    }

    public function test_repeat_unauthorized_domain_increments_count(): void
    {
        $recorder = new CspViolationRecorder();

        $recorder->recordUnauthorized('unknown.example', $this->report());
        $recorder->recordUnauthorized('unknown.example', $this->report());

        $this->assertDatabaseCount('unauthorized_report_domains', 1);
        $this->assertSame(2, UnauthorizedReportDomain::first()->occurrence_count);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=CspViolationRecorderTest
```

Expected: FAIL — `Class "App\Services\Csp\CspViolationRecorder" not found`.

- [ ] **Step 3: Implement `CspViolationRecorder`**

Create `app/Services/Csp/CspViolationRecorder.php`:

```php
<?php

namespace App\Services\Csp;

use App\Models\Site;
use Illuminate\Support\Facades\DB;

class CspViolationRecorder
{
    public function recordKnown(Site $site, NormalizedCspReport $report): void
    {
        $now = now();

        DB::statement(
            <<<'SQL'
            INSERT INTO csp_violations
                (site_id, effective_directive, blocked_uri, source_file, line_number, column_number,
                 disposition, document_uri, referrer, status_code, original_policy, script_sample,
                 raw_sample, occurrence_count, first_seen_at, last_seen_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                occurrence_count = occurrence_count + 1,
                last_seen_at = VALUES(last_seen_at),
                raw_sample = VALUES(raw_sample),
                updated_at = VALUES(updated_at)
            SQL,
            [
                $site->id,
                (string) $report->effectiveDirective,
                (string) $report->blockedUri,
                $report->sourceFile ?? '',
                $report->lineNumber ?? 0,
                $report->columnNumber ?? 0,
                (string) $report->disposition,
                (string) $report->documentUri,
                $report->referrer,
                $report->statusCode,
                $report->originalPolicy,
                $report->scriptSample,
                json_encode($report->raw),
                $now,
                $now,
                $now,
                $now,
            ]
        );
    }

    public function recordUnauthorized(string $domain, NormalizedCspReport $report): void
    {
        $now = now();

        DB::statement(
            <<<'SQL'
            INSERT INTO unauthorized_report_domains
                (domain, occurrence_count, first_seen_at, last_seen_at, sample_raw_payload, created_at, updated_at)
            VALUES (?, 1, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                occurrence_count = occurrence_count + 1,
                last_seen_at = VALUES(last_seen_at),
                sample_raw_payload = VALUES(sample_raw_payload),
                updated_at = VALUES(updated_at)
            SQL,
            [
                $domain,
                $now,
                $now,
                json_encode($report->raw),
                $now,
                $now,
            ]
        );
    }
}
```

- [ ] **Step 4: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=CspViolationRecorderTest
```

Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add CspViolationRecorder with atomic dedup upsert"
```

---

## Task 7: `ProcessCspReportJob`

**Files:**
- Create: `app/Jobs/ProcessCspReportJob.php`
- Test: `tests/Feature/Jobs/ProcessCspReportJobTest.php`

**Interfaces:**
- Consumes: `CspReportNormalizer::normalize()`, `CspReportParseException` (Task 5); `HostnameExtractor::extract()` (Task 4); `CspViolationRecorder::recordKnown()` / `recordUnauthorized()` (Task 6); `App\Models\Site`.
- Produces: `ProcessCspReportJob` — a `ShouldQueue` job constructed as `new ProcessCspReportJob(string $rawBody, ?string $contentType)`. Task 8's controller dispatches this.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Jobs/ProcessCspReportJobTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessCspReportJob;
use App\Models\CspViolation;
use App\Models\Site;
use App\Models\UnauthorizedReportDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProcessCspReportJobTest extends TestCase
{
    use RefreshDatabase;

    private function legacyBody(string $documentUri = 'https://example.com/page'): string
    {
        return json_encode([
            'csp-report' => [
                'document-uri' => $documentUri,
                'effective-directive' => 'script-src',
                'blocked-uri' => 'https://evil.example/script.js',
                'disposition' => 'report',
            ],
        ]);
    }

    public function test_known_active_site_writes_to_csp_violations(): void
    {
        Site::create(['domain' => 'example.com', 'is_active' => true]);

        (new ProcessCspReportJob($this->legacyBody(), 'application/csp-report'))->handle(
            app(\App\Services\Csp\CspReportNormalizer::class),
            app(\App\Services\Csp\CspViolationRecorder::class),
        );

        $this->assertDatabaseCount('csp_violations', 1);
        $this->assertDatabaseCount('unauthorized_report_domains', 0);
    }

    public function test_unknown_domain_writes_to_unauthorized_report_domains(): void
    {
        (new ProcessCspReportJob($this->legacyBody(), 'application/csp-report'))->handle(
            app(\App\Services\Csp\CspReportNormalizer::class),
            app(\App\Services\Csp\CspViolationRecorder::class),
        );

        $this->assertDatabaseCount('csp_violations', 0);
        $this->assertDatabaseCount('unauthorized_report_domains', 1);
        $this->assertSame('example.com', UnauthorizedReportDomain::first()->domain);
    }

    public function test_inactive_site_is_treated_as_unauthorized(): void
    {
        Site::create(['domain' => 'example.com', 'is_active' => false]);

        (new ProcessCspReportJob($this->legacyBody(), 'application/csp-report'))->handle(
            app(\App\Services\Csp\CspReportNormalizer::class),
            app(\App\Services\Csp\CspViolationRecorder::class),
        );

        $this->assertDatabaseCount('csp_violations', 0);
        $this->assertDatabaseCount('unauthorized_report_domains', 1);
    }

    public function test_malformed_json_is_logged_and_discarded(): void
    {
        Log::shouldReceive('warning')->once();

        (new ProcessCspReportJob('{not valid json', 'application/csp-report'))->handle(
            app(\App\Services\Csp\CspReportNormalizer::class),
            app(\App\Services\Csp\CspViolationRecorder::class),
        );

        $this->assertDatabaseCount('csp_violations', 0);
        $this->assertDatabaseCount('unauthorized_report_domains', 0);
    }

    public function test_report_with_no_extractable_hostname_is_logged_and_discarded(): void
    {
        Log::shouldReceive('warning')->once();

        (new ProcessCspReportJob($this->legacyBody(documentUri: ''), 'application/csp-report'))->handle(
            app(\App\Services\Csp\CspReportNormalizer::class),
            app(\App\Services\Csp\CspViolationRecorder::class),
        );

        $this->assertDatabaseCount('csp_violations', 0);
        $this->assertDatabaseCount('unauthorized_report_domains', 0);
    }

    public function test_subdomain_does_not_match_a_differently_registered_domain(): void
    {
        Site::create(['domain' => 'example.com', 'is_active' => true]);

        (new ProcessCspReportJob($this->legacyBody('https://www.example.com/page'), 'application/csp-report'))->handle(
            app(\App\Services\Csp\CspReportNormalizer::class),
            app(\App\Services\Csp\CspViolationRecorder::class),
        );

        $this->assertDatabaseCount('csp_violations', 0);
        $this->assertDatabaseCount('unauthorized_report_domains', 1);
        $this->assertSame('www.example.com', UnauthorizedReportDomain::first()->domain);
    }

    public function test_reporting_api_batch_processes_each_csp_violation_entry(): void
    {
        Site::create(['domain' => 'example.com', 'is_active' => true]);

        $body = json_encode([
            [
                'type' => 'csp-violation',
                'url' => 'https://example.com/page',
                'body' => ['documentURL' => 'https://example.com/page', 'effectiveDirective' => 'script-src', 'blockedURL' => 'https://evil.example/a.js', 'disposition' => 'report'],
            ],
            [
                'type' => 'csp-violation',
                'url' => 'https://example.com/page',
                'body' => ['documentURL' => 'https://example.com/page', 'effectiveDirective' => 'img-src', 'blockedURL' => 'https://tracker.example/p.gif', 'disposition' => 'report'],
            ],
        ]);

        (new ProcessCspReportJob($body, 'application/reports+json'))->handle(
            app(\App\Services\Csp\CspReportNormalizer::class),
            app(\App\Services\Csp\CspViolationRecorder::class),
        );

        $this->assertDatabaseCount('csp_violations', 2);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=ProcessCspReportJobTest
```

Expected: FAIL — `Class "App\Jobs\ProcessCspReportJob" not found`.

- [ ] **Step 3: Implement `ProcessCspReportJob`**

Create `app/Jobs/ProcessCspReportJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Csp\CspReportNormalizer;
use App\Services\Csp\CspReportParseException;
use App\Services\Csp\CspViolationRecorder;
use App\Services\Csp\HostnameExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCspReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly string $rawBody,
        public readonly ?string $contentType,
    ) {
    }

    public function handle(CspReportNormalizer $normalizer, CspViolationRecorder $recorder): void
    {
        try {
            $reports = $normalizer->normalize($this->rawBody, $this->contentType);
        } catch (CspReportParseException $exception) {
            Log::warning('Discarding unparseable CSP report', ['message' => $exception->getMessage()]);

            return;
        }

        foreach ($reports as $report) {
            $hostname = HostnameExtractor::extract($report->documentUri);

            if ($hostname === null) {
                Log::warning('Discarding CSP report with no extractable hostname', [
                    'document_uri' => $report->documentUri,
                ]);

                continue;
            }

            $site = Site::query()->where('domain', $hostname)->where('is_active', true)->first();

            if ($site !== null) {
                $recorder->recordKnown($site, $report);
            } else {
                $recorder->recordUnauthorized($hostname, $report);
            }
        }
    }
}
```

- [ ] **Step 4: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=ProcessCspReportJobTest
```

Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add ProcessCspReportJob"
```

---

## Task 8: Ingestion route, controller, CORS, and throttle

**Files:**
- Create: `routes/api.php`
- Modify: `bootstrap/app.php`
- Create: `config/cors.php`
- Create: `app/Http/Controllers/CspReportController.php`
- Test: `tests/Feature/CspReportIngestionTest.php`

**Interfaces:**
- Consumes: `App\Jobs\ProcessCspReportJob` (Task 7).
- Produces: `POST /csp-report` — the public ingestion endpoint every reporting site's CSP `report-uri`/`report-to` config points at.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CspReportIngestionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessCspReportJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CspReportIngestionTest extends TestCase
{
    private function legacyBody(): string
    {
        return json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/page',
                'effective-directive' => 'script-src',
                'blocked-uri' => 'https://evil.example/script.js',
                'disposition' => 'report',
            ],
        ]);
    }

    public function test_valid_report_dispatches_job_and_returns_204(): void
    {
        Queue::fake();

        $response = $this->call(
            'POST',
            '/csp-report',
            server: ['CONTENT_TYPE' => 'application/csp-report'],
            content: $this->legacyBody(),
        );

        $response->assertNoContent();
        Queue::assertPushed(ProcessCspReportJob::class, function (ProcessCspReportJob $job) {
            return str_contains($job->rawBody, 'evil.example') && $job->contentType === 'application/csp-report';
        });
    }

    public function test_malformed_body_still_returns_204(): void
    {
        Queue::fake();

        $response = $this->call(
            'POST',
            '/csp-report',
            server: ['CONTENT_TYPE' => 'application/csp-report'],
            content: '{not valid json',
        );

        $response->assertNoContent();
        Queue::assertPushed(ProcessCspReportJob::class);
    }

    public function test_options_preflight_returns_wide_open_cors_headers(): void
    {
        $response = $this->call('OPTIONS', '/csp-report', server: [
            'HTTP_ORIGIN' => 'https://example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_queue_dispatch_failure_surfaces_as_server_error(): void
    {
        $this->app->bind(Dispatcher::class, function () {
            return new class implements Dispatcher {
                public function dispatch($command)
                {
                    throw new \RuntimeException('Redis unreachable');
                }

                public function dispatchSync($command, $handler = null) {}

                public function dispatchNow($command, $handler = null) {}

                public function hasCommandHandler($command)
                {
                    return false;
                }

                public function getCommandHandler($command)
                {
                    return false;
                }

                public function pipeThrough(array $pipes)
                {
                    return $this;
                }

                public function map(array $map)
                {
                    return $this;
                }
            };
        });

        $response = $this->call(
            'POST',
            '/csp-report',
            server: ['CONTENT_TYPE' => 'application/csp-report'],
            content: $this->legacyBody(),
        );

        $response->assertServerError();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=CspReportIngestionTest
```

Expected: FAIL — 404 Not Found (no route registered yet).

- [ ] **Step 3: Add `routes/api.php`**

Create `routes/api.php`:

```php
<?php

use App\Http\Controllers\CspReportController;
use Illuminate\Support\Facades\Route;

Route::post('/csp-report', [CspReportController::class, 'store'])
    ->middleware('throttle:120,1');
```

- [ ] **Step 4: Wire `routes/api.php` into `bootstrap/app.php`**

Open `bootstrap/app.php`. It currently reads (default Laravel 12 skeleton):

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
```

Change the `withRouting(...)` call to add the `api` entry:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
```

This registers `routes/api.php` under the `api` middleware group (stateless — no session, no CSRF) without running `install:api`, so no Sanctum scaffolding is pulled in.

- [ ] **Step 5: Add `config/cors.php`**

Create `config/cors.php`:

```php
<?php

return [
    'paths' => ['csp-report'],
    'allowed_methods' => ['POST'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
```

This is read by Laravel's built-in `Illuminate\Http\Middleware\HandleCors` (part of the framework's default global middleware stack), which answers `OPTIONS` preflights for any path listed here before routing even occurs — no extra middleware registration needed.

- [ ] **Step 6: Implement `CspReportController`**

Create `app/Http/Controllers/CspReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCspReportJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CspReportController extends Controller
{
    public function store(Request $request): Response
    {
        ProcessCspReportJob::dispatch(
            $request->getContent(),
            $request->header('Content-Type'),
        );

        return response()->noContent();
    }
}
```

- [ ] **Step 7: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=CspReportIngestionTest
```

Expected: PASS (4 tests).

- [ ] **Step 8: Run the full test suite**

```bash
./vendor/bin/sail artisan test
```

Expected: all tests pass.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "Add CSP report ingestion route, controller, and CORS config"
```

---

## Task 9: Retention — daily prune command

**Files:**
- Create: `app/Console/Commands/PruneCspViolations.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/PruneCspViolationsTest.php`

**Interfaces:**
- Consumes: `App\Models\CspViolation` (Task 3).
- Produces: `php artisan csp:prune` — deletes `csp_violations` rows with `last_seen_at` older than 30 days. Scheduled to run daily.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/PruneCspViolationsTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\CspViolation;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneCspViolationsTest extends TestCase
{
    use RefreshDatabase;

    private function makeViolation(Site $site, \Illuminate\Support\Carbon $lastSeenAt): CspViolation
    {
        return CspViolation::create([
            'site_id' => $site->id,
            'effective_directive' => 'script-src',
            'blocked_uri' => 'https://evil.example/'.uniqid().'.js',
            'source_file' => '',
            'line_number' => 0,
            'column_number' => 0,
            'disposition' => 'report',
            'document_uri' => 'https://example.com/page',
            'raw_sample' => json_encode([]),
            'occurrence_count' => 1,
            'first_seen_at' => $lastSeenAt,
            'last_seen_at' => $lastSeenAt,
        ]);
    }

    public function test_prunes_violations_older_than_30_days(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);
        $old = $this->makeViolation($site, now()->subDays(31));
        $recent = $this->makeViolation($site, now()->subDays(10));

        $this->artisan('csp:prune')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=PruneCspViolationsTest
```

Expected: FAIL — `There are no commands defined in the "csp" namespace.`

- [ ] **Step 3: Implement the command**

```bash
./vendor/bin/sail artisan make:command PruneCspViolations
```

Replace `app/Console/Commands/PruneCspViolations.php` with:

```php
<?php

namespace App\Console\Commands;

use App\Models\CspViolation;
use Illuminate\Console\Command;

class PruneCspViolations extends Command
{
    protected $signature = 'csp:prune';

    protected $description = 'Delete aggregated CSP violations not seen in the last 30 days';

    public function handle(): int
    {
        $deleted = CspViolation::query()
            ->where('last_seen_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Pruned {$deleted} CSP violation(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test again**

```bash
./vendor/bin/sail artisan test --filter=PruneCspViolationsTest
```

Expected: PASS (1 test).

- [ ] **Step 5: Schedule it daily**

Open `routes/console.php` and add, alongside any existing content:

```php
use App\Console\Commands\PruneCspViolations;
use Illuminate\Support\Facades\Schedule;

Schedule::command(PruneCspViolations::class)->daily();
```

Verify:

```bash
./vendor/bin/sail artisan schedule:list
```

Expected: shows `csp:prune` scheduled to run daily.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add daily CSP violation pruning command"
```

---

## Task 10: Filament `SiteResource`

**Files:**
- Create: `app/Filament/Resources/SiteResource.php`
- Create: `app/Filament/Resources/SiteResource/Pages/ListSites.php`
- Create: `app/Filament/Resources/SiteResource/Pages/CreateSite.php`
- Create: `app/Filament/Resources/SiteResource/Pages/EditSite.php`
- Test: `tests/Feature/Filament/SiteResourceTest.php`

**Interfaces:**
- Consumes: `App\Models\Site` (Task 3).
- Produces: the `/admin/sites` CRUD UI. Task 12's "Register as Site" action creates `Site` records the same way this resource's create form does.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Filament/SiteResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SiteResource\Pages\CreateSite;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SiteResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_lists_sites_with_violation_count(): void
    {
        $site = Site::create(['domain' => 'example.com', 'name' => 'Example', 'is_active' => true]);

        Livewire::test(ListSites::class)
            ->assertCanSeeTableRecords([$site]);
    }

    public function test_can_create_a_site(): void
    {
        Livewire::test(CreateSite::class)
            ->fillForm([
                'domain' => 'example.com',
                'name' => 'Example',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('sites', ['domain' => 'example.com']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=SiteResourceTest
```

Expected: FAIL — `Class "App\Filament\Resources\SiteResource\Pages\ListSites" not found`.

- [ ] **Step 3: Implement `SiteResource`**

Create `app/Filament/Resources/SiteResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Models\Site;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('domain')
                ->required()
                ->unique(ignoreRecord: true)
                ->helperText('Exact hostname, e.g. www.example.com'),
            TextInput::make('name')
                ->helperText('Friendly label, optional'),
            Toggle::make('is_active')
                ->default(true)
                ->helperText('Inactive sites\' reports are treated as unauthorized'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('domain')->searchable()->sortable(),
                TextColumn::make('name'),
                ToggleColumn::make('is_active'),
                TextColumn::make('violations_count')
                    ->counts('violations')
                    ->label('Violations'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
```

Create `app/Filament/Resources/SiteResource/Pages/ListSites.php`:

```php
<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
```

Create `app/Filament/Resources/SiteResource/Pages/CreateSite.php`:

```php
<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;
}
```

Create `app/Filament/Resources/SiteResource/Pages/EditSite.php`:

```php
<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use Filament\Resources\Pages\EditRecord;

class EditSite extends EditRecord
{
    protected static string $resource = SiteResource::class;
}
```

- [ ] **Step 4: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=SiteResourceTest
```

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add Filament SiteResource"
```

---

## Task 11: Filament `CspViolationResource`

**Files:**
- Create: `app/Filament/Resources/CspViolationResource.php`
- Create: `app/Filament/Resources/CspViolationResource/Pages/ListCspViolations.php`
- Test: `tests/Feature/Filament/CspViolationResourceTest.php`

**Interfaces:**
- Consumes: `App\Models\CspViolation`, `App\Models\Site` (Task 3).
- Produces: the `/admin/csp-violations` read-focused table (no create/edit pages — these rows are system-generated).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Filament/CspViolationResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CspViolationResource\Pages\ListCspViolations;
use App\Models\CspViolation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CspViolationResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function makeViolation(Site $site, array $overrides = []): CspViolation
    {
        return CspViolation::create(array_merge([
            'site_id' => $site->id,
            'effective_directive' => 'script-src',
            'blocked_uri' => 'https://evil.example/script.js',
            'source_file' => '',
            'line_number' => 0,
            'column_number' => 0,
            'disposition' => 'report',
            'document_uri' => 'https://example.com/page',
            'raw_sample' => ['csp-report' => ['blocked-uri' => 'https://evil.example/script.js']],
            'occurrence_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ], $overrides));
    }

    public function test_lists_violations(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);
        $violation = $this->makeViolation($site);

        Livewire::test(ListCspViolations::class)
            ->assertCanSeeTableRecords([$violation]);
    }

    public function test_can_filter_by_site(): void
    {
        $siteA = Site::create(['domain' => 'a.example.com', 'is_active' => true]);
        $siteB = Site::create(['domain' => 'b.example.com', 'is_active' => true]);
        $violationA = $this->makeViolation($siteA);
        $violationB = $this->makeViolation($siteB, ['blocked_uri' => 'https://evil.example/other.js']);

        Livewire::test(ListCspViolations::class)
            ->filterTable('site_id', $siteA->id)
            ->assertCanSeeTableRecords([$violationA])
            ->assertCanNotSeeTableRecords([$violationB]);
    }

    public function test_can_filter_by_effective_directive(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);
        $scriptViolation = $this->makeViolation($site);
        $imgViolation = $this->makeViolation($site, [
            'effective_directive' => 'img-src',
            'blocked_uri' => 'https://tracker.example/pixel.gif',
        ]);

        Livewire::test(ListCspViolations::class)
            ->filterTable('effective_directive', 'img-src')
            ->assertCanSeeTableRecords([$imgViolation])
            ->assertCanNotSeeTableRecords([$scriptViolation]);
    }

    public function test_can_filter_by_blocked_uri_contains(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);
        $match = $this->makeViolation($site, ['blocked_uri' => 'https://evil.example/payload.js']);
        $nonMatch = $this->makeViolation($site, ['blocked_uri' => 'https://safe.example/lib.js']);

        Livewire::test(ListCspViolations::class)
            ->filterTable('blocked_uri', ['value' => 'evil.example'])
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$nonMatch]);
    }

    public function test_can_filter_by_disposition(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);
        $enforced = $this->makeViolation($site, [
            'disposition' => 'enforce',
            'blocked_uri' => 'https://evil.example/enforced.js',
        ]);
        $reported = $this->makeViolation($site, [
            'disposition' => 'report',
            'blocked_uri' => 'https://evil.example/reported.js',
        ]);

        Livewire::test(ListCspViolations::class)
            ->filterTable('disposition', 'enforce')
            ->assertCanSeeTableRecords([$enforced])
            ->assertCanNotSeeTableRecords([$reported]);
    }

    public function test_can_filter_by_last_seen_at_date_range(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);
        $old = $this->makeViolation($site, [
            'blocked_uri' => 'https://evil.example/old.js',
            'last_seen_at' => now()->subDays(20),
        ]);
        $recent = $this->makeViolation($site, [
            'blocked_uri' => 'https://evil.example/recent.js',
            'last_seen_at' => now(),
        ]);

        Livewire::test(ListCspViolations::class)
            ->filterTable('last_seen_at', ['from' => now()->subDays(2)->toDateString()])
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old]);
    }

    public function test_deactivating_a_site_leaves_its_historical_violations_visible(): void
    {
        $site = Site::create(['domain' => 'example.com', 'is_active' => true]);
        $violation = $this->makeViolation($site);

        $site->update(['is_active' => false]);

        Livewire::test(ListCspViolations::class)
            ->assertCanSeeTableRecords([$violation]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=CspViolationResourceTest
```

Expected: FAIL — `Class "App\Filament\Resources\CspViolationResource\Pages\ListCspViolations" not found`.

- [ ] **Step 3: Implement `CspViolationResource`**

Create `app/Filament/Resources/CspViolationResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CspViolationResource\Pages;
use App\Models\CspViolation;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CspViolationResource extends Resource
{
    protected static ?string $model = CspViolation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    private const DIRECTIVES = [
        'base-uri' => 'base-uri',
        'child-src' => 'child-src',
        'connect-src' => 'connect-src',
        'default-src' => 'default-src',
        'font-src' => 'font-src',
        'form-action' => 'form-action',
        'frame-ancestors' => 'frame-ancestors',
        'frame-src' => 'frame-src',
        'img-src' => 'img-src',
        'manifest-src' => 'manifest-src',
        'media-src' => 'media-src',
        'object-src' => 'object-src',
        'script-src' => 'script-src',
        'script-src-elem' => 'script-src-elem',
        'script-src-attr' => 'script-src-attr',
        'style-src' => 'style-src',
        'style-src-elem' => 'style-src-elem',
        'style-src-attr' => 'style-src-attr',
        'worker-src' => 'worker-src',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('site.domain')->label('Site')->sortable()->searchable(),
                TextColumn::make('effective_directive')->label('Directive')->sortable(),
                TextColumn::make('blocked_uri')->label('Blocked URI')->limit(60)->searchable(),
                TextColumn::make('disposition')->badge(),
                TextColumn::make('occurrence_count')->label('Count')->sortable(),
                TextColumn::make('first_seen_at')->dateTime()->sortable(),
                TextColumn::make('last_seen_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'domain')
                    ->searchable(),
                SelectFilter::make('effective_directive')
                    ->label('Directive')
                    ->options(self::DIRECTIVES),
                SelectFilter::make('disposition')
                    ->options([
                        'enforce' => 'Enforce',
                        'report' => 'Report',
                    ]),
                Filter::make('blocked_uri')
                    ->schema([
                        TextInput::make('value')->label('Blocked URI contains'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, string $value) => $q->where('blocked_uri', 'like', "%{$value}%"),
                    )),
                Filter::make('last_seen_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('last_seen_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, string $d) => $q->whereDate('last_seen_at', '<=', $d))),
            ])
            ->recordActions([
                Action::make('viewRaw')
                    ->label('View raw')
                    ->color('gray')
                    ->modalHeading('Raw CSP report')
                    ->schema([
                        TextEntry::make('raw_sample')
                            ->label('Raw payload')
                            ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT))
                            ->columnSpanFull(),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCspViolations::route('/'),
        ];
    }
}
```

Create `app/Filament/Resources/CspViolationResource/Pages/ListCspViolations.php`:

```php
<?php

namespace App\Filament\Resources\CspViolationResource\Pages;

use App\Filament\Resources\CspViolationResource;
use Filament\Resources\Pages\ListRecords;

class ListCspViolations extends ListRecords
{
    protected static string $resource = CspViolationResource::class;
}
```

- [ ] **Step 4: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=CspViolationResourceTest
```

Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add Filament CspViolationResource with filters"
```

---

## Task 12: Filament `UnauthorizedReportDomainResource` with "Register as Site" action

**Files:**
- Create: `app/Filament/Resources/UnauthorizedReportDomainResource.php`
- Create: `app/Filament/Resources/UnauthorizedReportDomainResource/Pages/ListUnauthorizedReportDomains.php`
- Test: `tests/Feature/Filament/UnauthorizedReportDomainResourceTest.php`

**Interfaces:**
- Consumes: `App\Models\UnauthorizedReportDomain`, `App\Models\Site` (Task 3).
- Produces: the `/admin/unauthorized-report-domains` review queue.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Filament/UnauthorizedReportDomainResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\UnauthorizedReportDomainResource\Pages\ListUnauthorizedReportDomains;
use App\Models\Site;
use App\Models\UnauthorizedReportDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UnauthorizedReportDomainResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function makeRow(string $domain = 'unknown.example'): UnauthorizedReportDomain
    {
        return UnauthorizedReportDomain::create([
            'domain' => $domain,
            'occurrence_count' => 3,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'sample_raw_payload' => ['csp-report' => []],
        ]);
    }

    public function test_lists_unauthorized_domains(): void
    {
        $row = $this->makeRow();

        Livewire::test(ListUnauthorizedReportDomains::class)
            ->assertCanSeeTableRecords([$row]);
    }

    public function test_register_as_site_action_creates_a_site(): void
    {
        $row = $this->makeRow('unknown.example');

        Livewire::test(ListUnauthorizedReportDomains::class)
            ->callTableAction('registerAsSite', $row, data: ['name' => 'Unknown Site']);

        $this->assertDatabaseHas('sites', [
            'domain' => 'unknown.example',
            'name' => 'Unknown Site',
            'is_active' => true,
        ]);
        // The historical unauthorized entry is not migrated/deleted.
        $this->assertDatabaseHas('unauthorized_report_domains', ['domain' => 'unknown.example']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=UnauthorizedReportDomainResourceTest
```

Expected: FAIL — `Class "App\Filament\Resources\UnauthorizedReportDomainResource\Pages\ListUnauthorizedReportDomains" not found`.

- [ ] **Step 3: Implement the resource**

Create `app/Filament/Resources/UnauthorizedReportDomainResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UnauthorizedReportDomainResource\Pages;
use App\Models\Site;
use App\Models\UnauthorizedReportDomain;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnauthorizedReportDomainResource extends Resource
{
    protected static ?string $model = UnauthorizedReportDomain::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Unauthorized Domains';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('domain')->searchable()->sortable(),
                TextColumn::make('occurrence_count')->label('Count')->sortable(),
                TextColumn::make('first_seen_at')->dateTime()->sortable(),
                TextColumn::make('last_seen_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('registerAsSite')
                    ->label('Register as Site')
                    ->icon('heroicon-o-check-circle')
                    ->schema([
                        TextInput::make('name')->label('Site name (optional)'),
                    ])
                    ->action(function (UnauthorizedReportDomain $record, array $data): void {
                        Site::create([
                            'domain' => $record->domain,
                            'name' => $data['name'] ?? null,
                            'is_active' => true,
                        ]);
                    })
                    ->visible(fn (UnauthorizedReportDomain $record) => ! Site::where('domain', $record->domain)->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnauthorizedReportDomains::route('/'),
        ];
    }
}
```

Create `app/Filament/Resources/UnauthorizedReportDomainResource/Pages/ListUnauthorizedReportDomains.php`:

```php
<?php

namespace App\Filament\Resources\UnauthorizedReportDomainResource\Pages;

use App\Filament\Resources\UnauthorizedReportDomainResource;
use Filament\Resources\Pages\ListRecords;

class ListUnauthorizedReportDomains extends ListRecords
{
    protected static string $resource = UnauthorizedReportDomainResource::class;
}
```

- [ ] **Step 4: Run the tests again**

```bash
./vendor/bin/sail artisan test --filter=UnauthorizedReportDomainResourceTest
```

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add Filament UnauthorizedReportDomainResource with Register as Site action"
```

---

## Task 13: Horizon supervisor config and dashboard authorization

**Files:**
- Modify: `config/horizon.php`
- Modify: `app/Providers/HorizonServiceProvider.php`
- Test: `tests/Feature/HorizonAuthorizationTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (config-only + a Gate definition).
- Produces: the `csp-reports` queue supervisor Horizon manages, and a `viewHorizon` gate restricting the `/horizon` dashboard to authenticated users.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/HorizonAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonAuthorizationTest extends TestCase
{
    public function test_guest_fails_the_view_horizon_gate(): void
    {
        $this->assertFalse(Gate::check('viewHorizon'));
    }

    public function test_authenticated_user_passes_the_view_horizon_gate(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Gate::forUser($user)->check('viewHorizon'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/sail artisan test --filter=HorizonAuthorizationTest
```

Expected: FAIL — the stock generated gate (`return in_array($user->email, [])`) makes both assertions fail (empty array never matches, and a guest call errors trying to read `$user->email` on `null`).

- [ ] **Step 3: Update the `viewHorizon` gate**

Open `app/Providers/HorizonServiceProvider.php` and replace the generated `gate()` method body:

```php
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user = null) {
        return $user !== null;
    });
}
```

(Single-admin app — any authenticated user is the admin, matching the Filament panel's own access model.)

- [ ] **Step 4: Run the test again**

```bash
./vendor/bin/sail artisan test --filter=HorizonAuthorizationTest
```

Expected: PASS (2 tests).

- [ ] **Step 5: Configure the `csp-reports` supervisor**

Open `config/horizon.php` and, in the `environments` array, set each environment's `supervisor-1` (both `production` and `local`) to:

```php
'supervisor-1' => [
    'connection' => 'redis',
    'queue' => ['csp-reports'],
    'balance' => 'auto',
    'minProcesses' => 1,
    'maxProcesses' => 10,
    'tries' => 3,
],
```

- [ ] **Step 6: Route the ingestion job onto the `csp-reports` queue**

Open `app/Jobs/ProcessCspReportJob.php` and add a `queue` property so dispatched jobs land on the supervisor configured above:

```php
public string $queue = 'csp-reports';
```

Add this as a public property alongside the existing `$tries`/`$backoff` properties.

- [ ] **Step 7: Run the full test suite**

```bash
./vendor/bin/sail artisan test
```

Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Configure Horizon csp-reports supervisor and dashboard authorization"
```
