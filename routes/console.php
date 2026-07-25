<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention: prune old audit/log rows nightly (no-op when retention is 0).
Schedule::command('cortendesk:prune-logs')->dailyAt('04:10');

// Hygiene: dead invitation links and expired device trust (PLAN D1). Separate
// from prune-logs, which no-ops when log retention is 0.
Schedule::command('cortendesk:prune-invitations')->dailyAt('04:20');
