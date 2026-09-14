<?php

namespace Tests\Feature\Models;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_must_be_unique(): void
    {
        Site::create(['domain' => 'example.com', 'name' => 'Example', 'is_active' => true]);

        $this->expectException(QueryException::class);

        Site::create(['domain' => 'example.com', 'name' => 'Duplicate', 'is_active' => true]);
    }

    public function test_is_active_defaults_to_true(): void
    {
        $site = Site::create(['domain' => 'example.com', 'name' => 'Example']);

        $this->assertTrue($site->fresh()->is_active);
    }

    public function test_domain_is_stored_lowercase(): void
    {
        $site = Site::create(['domain' => 'Example.COM', 'is_active' => true]);

        $this->assertSame('example.com', $site->fresh()->domain);
    }
}
