<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_admin_panel_in_production_environment(): void
    {
        config(['app.env' => 'production']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
    }

    public function test_guest_is_still_redirected_to_login_in_production_environment(): void
    {
        config(['app.env' => 'production']);

        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }
}
