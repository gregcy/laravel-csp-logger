<?php

namespace App\Services\Csp;

use App\Models\Site;
use Illuminate\Support\Facades\DB;

class CspViolationRecorder
{
    public function recordKnown(Site $site, NormalizedCspReport $report): void
    {
        $now = now();

        DB::statement(
            <<<'SQL'
            INSERT INTO csp_violations
                (site_id, effective_directive, blocked_uri, source_file, line_number, column_number,
                 disposition, document_uri, referrer, status_code, original_policy, script_sample,
                 raw_sample, occurrence_count, first_seen_at, last_seen_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                occurrence_count = occurrence_count + 1,
                last_seen_at = VALUES(last_seen_at),
                raw_sample = VALUES(raw_sample),
                updated_at = VALUES(updated_at)
            SQL,
            [
                $site->id,
                (string) $report->effectiveDirective,
                (string) $report->blockedUri,
                $report->sourceFile ?? '',
                $report->lineNumber ?? 0,
                $report->columnNumber ?? 0,
                (string) $report->disposition,
                (string) $report->documentUri,
                $report->referrer,
                $report->statusCode,
                $report->originalPolicy,
                $report->scriptSample,
                json_encode($report->raw),
                $now,
                $now,
                $now,
                $now,
            ]
        );
    }

    public function recordUnauthorized(string $domain, NormalizedCspReport $report): void
    {
        $now = now();

        DB::statement(
            <<<'SQL'
            INSERT INTO unauthorized_report_domains
                (domain, occurrence_count, first_seen_at, last_seen_at, sample_raw_payload, created_at, updated_at)
            VALUES (?, 1, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                occurrence_count = occurrence_count + 1,
                last_seen_at = VALUES(last_seen_at),
                sample_raw_payload = VALUES(sample_raw_payload),
                updated_at = VALUES(updated_at)
            SQL,
            [
                $domain,
                $now,
                $now,
                json_encode($report->raw),
                $now,
                $now,
            ]
        );
    }
}
