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
