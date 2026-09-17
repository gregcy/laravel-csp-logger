#!/usr/bin/env php
<?php

/**
 * Simulates CSP violation-report traffic against a deployed instance of this app.
 *
 * Models a site getting N page-hits/month, of which only a small fraction
 * actually trigger a CSP violation report (real browsers only report when a
 * policy is violated, not on every page load). Sends a mix of legacy
 * `report-uri` and Reporting API payloads, drawn from a weighted pool of
 * realistic violations, compressed into a short time window so results show
 * up in Filament immediately instead of trickling in over a real month.
 *
 * Usage:
 *   php scripts/simulate-csp-traffic.php [options]
 *
 * Options:
 *   --url=URL           Ingestion endpoint (default: https://csp.greg.cy/csp-report)
 *   --host=HOST         Hostname to put in document-uri/documentURL (default: localhost)
 *                        Must exactly match an active Site's registered domain.
 *   --hits=N            Monthly page-hit volume to base the simulation on (default: 500000)
 *   --report-rate=F     Fraction of hits that produce a CSP report, 0-1 (default: 0.02)
 *   --minutes=N         Compress the simulated month into this many minutes (default: 15)
 *   --concurrency=N     Max in-flight requests (default: 10)
 *   --reporting-api=F   Fraction of requests sent as Reporting API vs legacy, 0-1 (default: 0.3)
 *   --dry-run           Print sample payloads instead of sending any requests
 *   --help              Show this message
 */

function usage(): never
{
    fwrite(STDERR, <<<'TXT'
Usage: php scripts/simulate-csp-traffic.php [options]

  --url=URL           Ingestion endpoint (default: https://csp.greg.cy/csp-report)
  --host=HOST         Hostname for document-uri (default: localhost) - must match
                       an active Site's registered domain exactly
  --hits=N            Monthly page-hit volume to base the simulation on (default: 500000)
  --report-rate=F     Fraction of hits producing a report, 0-1 (default: 0.02)
  --minutes=N         Compress the simulated month into this many minutes (default: 15)
  --concurrency=N     Max in-flight requests (default: 10)
  --reporting-api=F   Fraction sent as Reporting API vs legacy format (default: 0.3)
  --dry-run           Print sample payloads instead of sending requests
  --help              Show this message

TXT
    );

    exit(1);
}

$options = getopt('', [
    'url::', 'host::', 'hits::', 'report-rate::', 'minutes::',
    'concurrency::', 'reporting-api::', 'dry-run', 'help',
]);

if (isset($options['help'])) {
    usage();
}

$url = $options['url'] ?? 'https://csp.greg.cy/csp-report';
$host = $options['host'] ?? 'localhost';
$hits = (int) ($options['hits'] ?? 500000);
$reportRate = (float) ($options['report-rate'] ?? 0.02);
$minutes = (float) ($options['minutes'] ?? 15);
$concurrency = max(1, (int) ($options['concurrency'] ?? 10));
$reportingApiFraction = (float) ($options['reporting-api'] ?? 0.3);
$dryRun = isset($options['dry-run']);

$totalReports = max(1, (int) round($hits * $reportRate));
$durationSeconds = max(1.0, $minutes * 60);

$policy = "default-src 'self'; script-src 'self' https://www.google-analytics.com https://connect.facebook.net; "
    ."style-src 'self' https://fonts.googleapis.com; img-src 'self' data: https://www.google-analytics.com; "
    ."font-src 'self' https://fonts.gstatic.com; connect-src 'self' https://api.stripe.com https://o450.ingest.sentry.io; "
    ."frame-src 'self' https://js.stripe.com https://www.youtube.com; report-uri {$url}";

$pages = [
    '/', '/about', '/pricing', '/blog/2026/09/shipping-update', '/products', '/products/123',
    '/cart', '/checkout', '/account', '/search?q=running+shoes', '/contact', '/faq',
];

$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Edg/128.0.0.0',
];

/**
 * Weighted pool of realistic CSP violations. `sourceFromDoc` = true means the
 * source-file is the page itself (typical of inline script/style violations);
 * false means it's an empty source-file (typical of a blocked external
 * resource load). Combined with the app's dedup key
 * (site, effective_directive, blocked_uri, source_file), inline entries
 * naturally fan out into one row per page while external entries collapse
 * into a single row that just accumulates occurrence_count.
 */
$violationPool = [
    ['directive' => 'script-src', 'blocked' => 'inline', 'sourceFromDoc' => true, 'weight' => 30],
    ['directive' => 'script-src', 'blocked' => 'https://www.google-analytics.com/analytics.js', 'sourceFromDoc' => false, 'weight' => 20],
    ['directive' => 'script-src', 'blocked' => 'https://connect.facebook.net/en_US/fbevents.js', 'sourceFromDoc' => false, 'weight' => 12],
    ['directive' => 'script-src', 'blocked' => 'eval', 'sourceFromDoc' => true, 'weight' => 5],
    ['directive' => 'script-src', 'blocked' => 'https://cdn.jsdelivr.net/npm/some-widget@2/dist/widget.min.js', 'sourceFromDoc' => false, 'weight' => 4],
    ['directive' => 'style-src', 'blocked' => 'inline', 'sourceFromDoc' => true, 'weight' => 15],
    ['directive' => 'style-src', 'blocked' => 'https://fonts.googleapis.com/css?family=Roboto', 'sourceFromDoc' => false, 'weight' => 8],
    ['directive' => 'img-src', 'blocked' => 'https://www.google-analytics.com/collect', 'sourceFromDoc' => false, 'weight' => 10],
    ['directive' => 'img-src', 'blocked' => 'data:', 'sourceFromDoc' => false, 'weight' => 4],
    ['directive' => 'connect-src', 'blocked' => 'https://api.stripe.com/v1/payment_intents', 'sourceFromDoc' => false, 'weight' => 6],
    ['directive' => 'connect-src', 'blocked' => 'https://o450.ingest.sentry.io/api/1/envelope/', 'sourceFromDoc' => false, 'weight' => 6],
    ['directive' => 'font-src', 'blocked' => 'https://fonts.gstatic.com/s/roboto/v30/font.woff2', 'sourceFromDoc' => false, 'weight' => 5],
    ['directive' => 'frame-src', 'blocked' => 'https://js.stripe.com/v3/', 'sourceFromDoc' => false, 'weight' => 4],
    ['directive' => 'frame-src', 'blocked' => 'https://www.youtube.com/embed/dQw4w9WgXcQ', 'sourceFromDoc' => false, 'weight' => 3],
];

$totalWeight = array_sum(array_column($violationPool, 'weight'));

function weightedPick(array $pool, int $totalWeight): array
{
    $r = mt_rand(1, $totalWeight);
    $cumulative = 0;

    foreach ($pool as $entry) {
        $cumulative += $entry['weight'];

        if ($r <= $cumulative) {
            return $entry;
        }
    }

    return $pool[array_key_last($pool)];
}

/** Exponential inter-arrival time for a Poisson-ish, non-bursty average rate. */
function nextInterval(float $mean): float
{
    $u = mt_rand(1, PHP_INT_MAX) / PHP_INT_MAX;

    return -log($u) * $mean;
}

function buildReport(array $violation, string $host, array $pages, array $userAgents, string $policy): array
{
    $page = $pages[array_rand($pages)];
    $documentUri = "http://{$host}{$page}";
    $ua = $userAgents[array_rand($userAgents)];

    $sourceFile = $violation['sourceFromDoc'] ? $documentUri : '';
    $lineNumber = $violation['sourceFromDoc'] ? mt_rand(1, 400) : 0;
    $columnNumber = $violation['sourceFromDoc'] ? mt_rand(1, 120) : 0;

    return [
        'documentUri' => $documentUri,
        'referrer' => mt_rand(0, 4) === 0 ? '' : "https://www.google.com/",
        'violatedDirective' => "{$violation['directive']} 'self'",
        'effectiveDirective' => $violation['directive'],
        'originalPolicy' => $policy,
        'disposition' => 'report',
        'blockedUri' => $violation['blocked'],
        'statusCode' => 200,
        'sourceFile' => $sourceFile,
        'lineNumber' => $lineNumber,
        'columnNumber' => $columnNumber,
        'userAgent' => $ua,
    ];
}

function encodeLegacy(array $r): array
{
    $body = [
        'csp-report' => [
            'document-uri' => $r['documentUri'],
            'referrer' => $r['referrer'],
            'violated-directive' => $r['violatedDirective'],
            'effective-directive' => $r['effectiveDirective'],
            'original-policy' => $r['originalPolicy'],
            'disposition' => $r['disposition'],
            'blocked-uri' => $r['blockedUri'],
            'status-code' => $r['statusCode'],
            'source-file' => $r['sourceFile'],
            'line-number' => $r['lineNumber'],
            'column-number' => $r['columnNumber'],
        ],
    ];

    return [json_encode($body, JSON_UNESCAPED_SLASHES), 'application/csp-report', $r['userAgent']];
}

function encodeReportingApi(array $reports): array
{
    $entries = array_map(static fn (array $r) => [
        'age' => mt_rand(0, 5000),
        'type' => 'csp-violation',
        'url' => $r['documentUri'],
        'user_agent' => $r['userAgent'],
        'body' => [
            'documentURL' => $r['documentUri'],
            'referrer' => $r['referrer'],
            'violatedDirective' => $r['violatedDirective'],
            'effectiveDirective' => $r['effectiveDirective'],
            'originalPolicy' => $r['originalPolicy'],
            'disposition' => $r['disposition'],
            'blockedURL' => $r['blockedUri'],
            'statusCode' => $r['statusCode'],
            'sourceFile' => $r['sourceFile'],
            'lineNumber' => $r['lineNumber'],
            'columnNumber' => $r['columnNumber'],
        ],
    ], $reports);

    return [json_encode($entries, JSON_UNESCAPED_SLASHES), 'application/reports+json', $reports[0]['userAgent']];
}

function nextPayload(
    array $violationPool,
    int $totalWeight,
    string $host,
    array $pages,
    array $userAgents,
    string $policy,
    float $reportingApiFraction,
): array {
    $useReportingApi = (mt_rand() / mt_getrandmax()) < $reportingApiFraction;

    if (! $useReportingApi) {
        $violation = weightedPick($violationPool, $totalWeight);
        $report = buildReport($violation, $host, $pages, $userAgents, $policy);

        return encodeLegacy($report);
    }

    $batchSize = mt_rand(1, 3);
    $reports = [];

    for ($i = 0; $i < $batchSize; $i++) {
        $violation = weightedPick($violationPool, $totalWeight);
        $reports[] = buildReport($violation, $host, $pages, $userAgents, $policy);
    }

    return encodeReportingApi($reports);
}

if ($dryRun) {
    fwrite(STDERR, "Dry run - printing 5 sample payloads, not sending anything.\n\n");

    for ($i = 0; $i < 5; $i++) {
        [$body, $contentType] = nextPayload($violationPool, $totalWeight, $host, $pages, $userAgents, $policy, $reportingApiFraction);
        echo "Content-Type: {$contentType}\n{$body}\n\n";
    }

    exit(0);
}

fwrite(STDERR, sprintf(
    "Simulating %s hits/month at a %.1f%% report rate = %s reports, compressed into %.1f minute(s) (~%.2f req/s avg), concurrency=%d\nTarget: %s (host=%s)\n\n",
    number_format($hits),
    $reportRate * 100,
    number_format($totalReports),
    $minutes,
    $totalReports / $durationSeconds,
    $concurrency,
    $url,
    $host,
));

$mean = $durationSeconds / $totalReports;
$multi = curl_multi_init();
$active = [];
$sent = 0;
$completed = 0;
$statusCounts = [];
$startTime = microtime(true);
$nextScheduled = 0.0;

$makeHandle = static function () use ($url, $violationPool, $totalWeight, $host, $pages, $userAgents, $policy, $reportingApiFraction) {
    [$body, $contentType, $ua] = nextPayload($violationPool, $totalWeight, $host, $pages, $userAgents, $policy, $reportingApiFraction);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: '.$contentType,
            'User-Agent: '.$ua,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    return $ch;
};

while ($sent < $totalReports || count($active) > 0) {
    $elapsed = microtime(true) - $startTime;

    while ($sent < $totalReports && count($active) < $concurrency && $elapsed >= $nextScheduled) {
        $ch = $makeHandle();
        curl_multi_add_handle($multi, $ch);
        $active[(int) $ch] = $ch;
        $sent++;
        $nextScheduled += nextInterval($mean);
        $elapsed = microtime(true) - $startTime;
    }

    curl_multi_exec($multi, $running);

    if ($running) {
        curl_multi_select($multi, 0.1);
    }

    while ($info = curl_multi_info_read($multi)) {
        $ch = $info['handle'];
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $statusCounts[$code] = ($statusCounts[$code] ?? 0) + 1;
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        unset($active[(int) $ch]);
        $completed++;

        if ($completed % 500 === 0 || $completed === $totalReports) {
            fwrite(STDERR, sprintf("  %s / %s sent (%.0fs elapsed)\n", number_format($completed), number_format($totalReports), microtime(true) - $startTime));
        }
    }

    if ($sent >= $totalReports && count($active) === 0) {
        break;
    }

    if ($sent < $totalReports && ! $running) {
        $wait = $nextScheduled - (microtime(true) - $startTime);

        if ($wait > 0) {
            usleep((int) min(50000, $wait * 1_000_000));
        }
    }
}

curl_multi_close($multi);

fwrite(STDERR, "\nDone. Status code breakdown:\n");

foreach ($statusCounts as $code => $count) {
    fwrite(STDERR, sprintf("  %s: %s\n", $code, number_format($count)));
}
