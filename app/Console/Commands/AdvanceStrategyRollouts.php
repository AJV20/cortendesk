<?php

namespace App\Console\Commands;

use App\Models\StrategyRollout;
use Illuminate\Console\Command;

class AdvanceStrategyRollouts extends Command
{
    protected $signature = 'cortendesk:advance-strategy-rollouts';

    protected $description = 'Start and advance due staged strategy rollouts';

    public function handle(): int
    {
        $advanced = StrategyRollout::advanceDue();
        $this->components->info("Advanced {$advanced} strategy rollout(s).");

        return self::SUCCESS;
    }
}
