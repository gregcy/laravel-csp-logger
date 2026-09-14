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

    public function test_legacy_falls_back_to_bare_directive_name_from_violated_directive(): void
    {
        $body = json_encode([
            'csp-report' => [
                'document-uri' => 'https://example.com/page',
                'violated-directive' => "script-src 'self' https://cdn.example",
                'blocked-uri' => 'https://evil.example/script.js',
                'disposition' => 'report',
            ],
        ]);

        $reports = $this->normalizer->normalize($body, 'application/csp-report');

        $this->assertCount(1, $reports);
        $this->assertSame('script-src', $reports[0]->effectiveDirective);
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
