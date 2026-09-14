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
