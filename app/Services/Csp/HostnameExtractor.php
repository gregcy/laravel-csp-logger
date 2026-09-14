<?php

namespace App\Services\Csp;

class HostnameExtractor
{
    public static function extract(?string $uri): ?string
    {
        if ($uri === null || trim($uri) === '') {
            return null;
        }

        $host = parse_url($uri, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }
}
