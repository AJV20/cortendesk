<?php

namespace Tests\Feature;

use App\Services\FleetDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DiagnosticsHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_heartbeat_command_updates_the_cache(): void
    {
        Cache::forget('cortendesk:diagnostics:scheduler-heartbeat');

        $this->artisan('cortendesk:diagnostics-heartbeat')->assertExitCode(0);

        $this->assertNotNull(Cache::get('cortendesk:diagnostics:scheduler-heartbeat'));
    }

    public function test_invalid_scheduler_heartbeat_is_treated_as_stale(): void
    {
        Cache::put('cortendesk:diagnostics:scheduler-heartbeat', 'not-a-date');

        $report = app(FleetDiagnostics::class)->report();

        $this->assertFalse($report['scheduler']['ok']);
        $this->assertNull($report['scheduler']['last_seen_at']);
    }
}
