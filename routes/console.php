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

// Sessions on devices that have gone silent (issue #10). Frequent, not nightly:
// this drives what the dashboard reports as happening right now.
Schedule::command('cortendesk:close-stale-sessions')->everyFiveMinutes()->withoutOverlapping();

// Presence transitions for configurable Apprise notifications.
Schedule::command('cortendesk:check-device-notifications')->everyMinute()->withoutOverlapping();

// Advance scheduled strategy rollout batches with one host/process at a time.
Schedule::command('cortendesk:advance-strategy-rollouts')->everyMinute()->withoutOverlapping();

// Proves the scheduler is alive, for the diagnostics page.
Schedule::command('cortendesk:diagnostics-heartbeat')->everyMinute()->withoutOverlapping();
