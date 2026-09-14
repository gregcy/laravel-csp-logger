<?php

namespace App\Services\Csp;

final class NormalizedCspReport
{
    public function __construct(
        public readonly ?string $documentUri,
        public readonly ?string $referrer,
        public readonly ?string $violatedDirective,
        public readonly ?string $effectiveDirective,
        public readonly ?string $originalPolicy,
        public readonly ?string $disposition,
        public readonly ?string $blockedUri,
        public readonly ?int $statusCode,
        public readonly ?string $scriptSample,
        public readonly ?string $sourceFile,
        public readonly ?int $lineNumber,
        public readonly ?int $columnNumber,
        public readonly array $raw,
    ) {
    }
}
