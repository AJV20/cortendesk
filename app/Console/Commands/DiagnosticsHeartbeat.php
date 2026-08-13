<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DiagnosticsHeartbeat extends Command
{
    protected $signature = 'cortendesk:diagnostics-heartbeat';

    protected $description = 'Record scheduler freshness for Fleet Diagnostics';

    public function handle(): int
    {
        Cache::forever('cortendesk:diagnostics:scheduler-heartbeat', now()->toIso8601String());

        return self::SUCCESS;
    }
}
