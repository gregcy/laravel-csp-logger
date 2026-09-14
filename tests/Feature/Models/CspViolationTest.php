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
