<?php

namespace Tests\Unit\Services\Csp;

use App\Services\Csp\HostnameExtractor;
use PHPUnit\Framework\TestCase;

class HostnameExtractorTest extends TestCase
{
    public function test_extracts_lowercase_hostname(): void
    {
        $this->assertSame('example.com', HostnameExtractor::extract('https://EXAMPLE.com/page'));
    }

    public function test_strips_port(): void
    {
        $this->assertSame('example.com', HostnameExtractor::extract('https://example.com:8080/page'));
    }

    public function test_returns_null_for_missing_uri(): void
    {
        $this->assertNull(HostnameExtractor::extract(null));
    }

    public function test_returns_null_for_empty_string(): void
    {
        $this->assertNull(HostnameExtractor::extract(''));
    }

    public function test_returns_null_for_unparseable_uri(): void
    {
        $this->assertNull(HostnameExtractor::extract('not a url at all ::::'));
    }

    public function test_returns_null_when_uri_has_no_host(): void
    {
        $this->assertNull(HostnameExtractor::extract('/relative/path'));
    }
}
