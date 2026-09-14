<?php

namespace App\Services\Csp;

class CspReportNormalizer
{
    /**
     * @return NormalizedCspReport[]
     */
    public function normalize(string $rawBody, ?string $contentType): array
    {
        $decoded = json_decode($rawBody, associative: true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CspReportParseException('Request body is not valid JSON: '.json_last_error_msg());
        }

        $isReportingApi = $contentType !== null && str_contains($contentType, 'reports+json');
        $isLegacy = $contentType !== null && str_contains($contentType, 'csp-report');

        if (! $isReportingApi && ! $isLegacy) {
            // No usable Content-Type — fall back to sniffing the decoded body's shape.
            $isReportingApi = is_array($decoded) && array_is_list($decoded);
            $isLegacy = is_array($decoded) && array_key_exists('csp-report', $decoded);
        }

        if ($isLegacy) {
            return [$this->normalizeLegacy($decoded)];
        }

        if ($isReportingApi) {
            return $this->normalizeReportingApiBatch($decoded);
        }

        throw new CspReportParseException('Unable to determine CSP report format from Content-Type or body shape.');
    }

    private function normalizeLegacy(mixed $decoded): NormalizedCspReport
    {
        if (! is_array($decoded) || ! array_key_exists('csp-report', $decoded) || ! is_array($decoded['csp-report'])) {
            throw new CspReportParseException('Legacy report body is missing the "csp-report" object.');
        }

        $report = $decoded['csp-report'];

        return new NormalizedCspReport(
            documentUri: $report['document-uri'] ?? null,
            referrer: $report['referrer'] ?? null,
            violatedDirective: $report['violated-directive'] ?? null,
            effectiveDirective: $report['effective-directive'] ?? $report['violated-directive'] ?? null,
            originalPolicy: $report['original-policy'] ?? null,
            disposition: $report['disposition'] ?? null,
            blockedUri: $report['blocked-uri'] ?? null,
            statusCode: isset($report['status-code']) ? (int) $report['status-code'] : null,
            scriptSample: $report['script-sample'] ?? null,
            sourceFile: $report['source-file'] ?? null,
            lineNumber: isset($report['line-number']) ? (int) $report['line-number'] : null,
            columnNumber: isset($report['column-number']) ? (int) $report['column-number'] : null,
            raw: $decoded,
        );
    }

    /**
     * @return NormalizedCspReport[]
     */
    private function normalizeReportingApiBatch(mixed $decoded): array
    {
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new CspReportParseException('Reporting API body must be a JSON array.');
        }

        $reports = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'csp-violation') {
                continue;
            }

            $body = is_array($entry['body'] ?? null) ? $entry['body'] : [];

            $reports[] = new NormalizedCspReport(
                documentUri: $body['documentURL'] ?? $entry['url'] ?? null,
                referrer: $body['referrer'] ?? null,
                violatedDirective: $body['violatedDirective'] ?? null,
                effectiveDirective: $body['effectiveDirective'] ?? $body['violatedDirective'] ?? null,
                originalPolicy: $body['originalPolicy'] ?? null,
                disposition: $body['disposition'] ?? null,
                blockedUri: $body['blockedURL'] ?? null,
                statusCode: isset($body['statusCode']) ? (int) $body['statusCode'] : null,
                scriptSample: $body['sample'] ?? null,
                sourceFile: $body['sourceFile'] ?? null,
                lineNumber: isset($body['lineNumber']) ? (int) $body['lineNumber'] : null,
                columnNumber: isset($body['columnNumber']) ? (int) $body['columnNumber'] : null,
                raw: $entry,
            );
        }

        return $reports;
    }
}
