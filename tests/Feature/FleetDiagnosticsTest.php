<?php

namespace Tests\Feature;

use App\Contracts\TcpProbe;
use App\Models\Device;
use App\Models\Setting;
use App\Models\User;
use App\Services\FleetDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FleetDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostics_is_permission_gated_and_uses_bounded_injected_tcp_probes(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        Setting::put('id_server', '10.0.0.5:21116');
        Setting::put('relay_server', 'relay.secret.test:21117');

        $fake = new class implements TcpProbe
        {
            public array $calls = [];

            public function check(string $host, int $port, float $timeout): array
            {
                $this->calls[] = [$host, $port, $timeout];

                return ['ok' => true, 'latency_ms' => 3, 'error' => null];
            }
        };
        $this->app->instance(TcpProbe::class, $fake);

        $this->actingAs($admin)->get('/diagnostics')->assertOk()->assertSee('Fleet diagnostics');
        $this->actingAs($user)->get('/diagnostics')->assertRedirect('/');

        $this->assertSame([
            ['10.0.0.5', 21116, 1.0],
            ['relay.secret.test', 21117, 1.0],
        ], $fake->calls);
    }

    public function test_report_includes_fleet_scheduler_mail_and_version_signals(): void
    {
        $this->assertNull(app(FleetDiagnostics::class)->report()['smtp']['healthy']);

        $admin = User::factory()->admin()->create();
        Setting::put('smtp_enabled', '1');
        Setting::put('smtp_host', 'smtp.secret.test');
        Setting::put('smtp_from_address', 'desk@example.test');
        Cache::forever('cortendesk:diagnostics:scheduler-heartbeat', now()->toIso8601String());

        Device::create(['rustdesk_id' => '1', 'uuid' => 'one', 'status' => Device::STATUS_ACTIVE, 'version' => '1.4.0', 'last_online_at' => now()]);
        Device::create(['rustdesk_id' => '2', 'uuid' => 'two', 'status' => Device::STATUS_ACTIVE, 'version' => '1.3.0', 'last_online_at' => now()->subDays(2)]);

        $this->actingAs($admin)->get('/diagnostics')
            ->assertOk()
            ->assertSee('Scheduler')
            ->assertSee('SMTP')
            ->assertSee('Configured, but no send result has been observed.')
            ->assertSee('1.4.0')
            ->assertSee('Behind newest fleet version');

        $report = app(FleetDiagnostics::class)->report();
        $this->assertNull($report['smtp']['healthy']);
        $this->assertSame(1, $report['fleet']['silent_over_24h']);
        $this->assertFalse($report['services']['websocket_bridge']['ok']);
    }

    public function test_sanitized_export_excludes_hosts_ips_urls_and_keys(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::put('id_server', '10.0.0.5:21116');
        Setting::put('relay_server', 'relay.secret.test:21117');
        Setting::put('public_key', 'VERY_SECRET_PUBLIC_KEY');
        config(['app.url' => 'https://desk.secret.test']);

        $response = $this->actingAs($admin)->get('/diagnostics/export')->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $json = $response->streamedContent();

        $this->assertStringNotContainsString('10.0.0.5', $json);
        $this->assertStringNotContainsString('relay.secret.test', $json);
        $this->assertStringNotContainsString('desk.secret.test', $json);
        $this->assertStringNotContainsString('VERY_SECRET_PUBLIC_KEY', $json);
        $this->assertStringContainsString('id_server', $json);
        $this->assertStringContainsString('configured', $json);
    }
}
