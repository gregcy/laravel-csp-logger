<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Csp\CspReportNormalizer;
use App\Services\Csp\CspReportParseException;
use App\Services\Csp\CspViolationRecorder;
use App\Services\Csp\HostnameExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCspReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly string $rawBody,
        public readonly ?string $contentType,
    ) {
    }

    public function handle(CspReportNormalizer $normalizer, CspViolationRecorder $recorder): void
    {
        try {
            $reports = $normalizer->normalize($this->rawBody, $this->contentType);
        } catch (CspReportParseException $exception) {
            Log::warning('Discarding unparseable CSP report', ['message' => $exception->getMessage()]);

            return;
        }

        foreach ($reports as $report) {
            $hostname = HostnameExtractor::extract($report->documentUri);

            if ($hostname === null) {
                Log::warning('Discarding CSP report with no extractable hostname', [
                    'document_uri' => $report->documentUri,
                ]);

                continue;
            }

            $site = Site::query()->where('domain', $hostname)->where('is_active', true)->first();

            if ($site !== null) {
                $recorder->recordKnown($site, $report);
            } else {
                $recorder->recordUnauthorized($hostname, $report);
            }
        }
    }
}
