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
