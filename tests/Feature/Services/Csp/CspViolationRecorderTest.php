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
