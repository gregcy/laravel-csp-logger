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
