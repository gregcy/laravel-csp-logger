<?php

namespace App\Console\Commands;

use App\Models\CspViolation;
use Illuminate\Console\Command;

class PruneCspViolations extends Command
{
    protected $signature = 'csp:prune';

    protected $description = 'Delete aggregated CSP violations not seen in the last 30 days';

    public function handle(): int
    {
        $deleted = CspViolation::query()
            ->where('last_seen_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Pruned {$deleted} CSP violation(s).");

        return self::SUCCESS;
    }
}
