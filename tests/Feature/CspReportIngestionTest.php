<?php

namespace Tests\Feature;

use App\Jobs\ProcessCspReportJob;
use App\Models\CspViolation;
use App\Models\Site;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CspReportIngestionTest extends TestCase
{
    use RefreshDatabase;

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
        Queue::assertPushedOn('csp-reports', ProcessCspReportJob::class);
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

    public function test_end_to_end_legacy_report_for_known_site_is_persisted_with_truncated_uri(): void
    {
        Site::create(['domain' => 'example.com', 'is_active' => true]);

        $longBlockedUri = 'https://cdn.example/assets/script.js?'.str_repeat('a', 300);

        $body = json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/page',
                'effective-directive' => 'script-src',
                'blocked-uri' => $longBlockedUri,
                'disposition' => 'report',
            ],
        ]);

        $this->assertGreaterThan(255, strlen($longBlockedUri));

        $response = $this->call(
            'POST',
            '/csp-report',
            server: ['CONTENT_TYPE' => 'application/csp-report'],
            content: $body,
        );

        $response->assertNoContent();
        $this->assertDatabaseCount('csp_violations', 1);
        $violation = CspViolation::first();
        $this->assertSame(mb_substr($longBlockedUri, 0, 255), $violation->blocked_uri);
        $this->assertSame(255, strlen($violation->blocked_uri));
    }
}
