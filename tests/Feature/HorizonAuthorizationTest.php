<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonAuthorizationTest extends TestCase
{
    public function test_guest_fails_the_view_horizon_gate(): void
    {
        $this->assertFalse(Gate::check('viewHorizon'));
    }

    public function test_authenticated_user_passes_the_view_horizon_gate(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Gate::forUser($user)->check('viewHorizon'));
    }
}
