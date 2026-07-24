<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention: prune old audit/log rows nightly (no-op when retention is 0).
Schedule::command('cortendesk:prune-logs')->dailyAt('04:10');
