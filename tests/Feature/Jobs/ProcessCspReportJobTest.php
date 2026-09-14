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
