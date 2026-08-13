<?php

namespace Tests\Feature;

use App\Console\Commands\CheckDeviceNotifications;
use App\Livewire\SettingsPage;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Models\User;
use App\Services\AppriseNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class AppriseNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_configured_stateful_apprise_notification_and_records_a_safe_delivery_log(): void
    {
        $this->configureApprise(['security_alarm']);

        Http::fake([
            'https://api.example.test/apprise/notify/office-alerts?access_token=secret' => Http::response(['result' => 'success']),
        ]);

        $delivery = app(AppriseNotifications::class)->send(
            'security.alarm',
            'Security alarm',
            'Device 123 reported an alarm.',
            'alarm:123:1',
        );

        $this->assertNotNull($delivery);
        $this->assertSame('sent', $delivery->status, $delivery->error ?? 'Delivery did not send.');
        $this->assertDatabaseHas('notification_deliveries', [
            'event' => 'security.alarm',
            'subject' => 'alarm:123:1',
            'status' => 'sent',
        ]);
        $this->assertStringNotContainsString('secret', (string) $delivery->error);
        $this->assertDatabaseMissing('notification_deliveries', [
            'error' => 'https://api.example.test/apprise?access_token=secret',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.example.test/apprise/notify/office-alerts?access_token=secret'
                && $request['title'] === 'Security alarm'
                && $request['body'] === 'Device 123 reported an alarm.'
                && $request['type'] === 'warning';
        });
    }

    public function test_it_honors_cooldown_without_creating_delivery_log_noise(): void
    {
        $this->configureApprise(['device_offline']);
        Http::fake(['*' => Http::response(['result' => 'success'])]);

        $notifications = app(AppriseNotifications::class);
        $this->assertSame('sent', $notifications->send('device.offline', 'Device offline', 'Host is offline.', 'device:1')?->status);
        $this->assertNull($notifications->send('device.offline', 'Device offline', 'Host is offline.', 'device:1'));

        Http::assertSentCount(1);
        $this->assertSame(1, NotificationDelivery::query()->count());
    }

    public function test_device_events_can_be_scoped_to_selected_groups_or_devices(): void
    {
        $this->configureApprise(['device_offline']);
        Http::fake(['*' => Http::response(['result' => 'success'])]);
        $group = DeviceGroup::create(['name' => 'Servers']);
        $includedByGroup = Device::create(['rustdesk_id' => '101', 'uuid' => 'grouped', 'status' => Device::STATUS_ACTIVE, 'device_group_id' => $group->id]);
        $includedDirectly = Device::create(['rustdesk_id' => '102', 'uuid' => 'direct', 'status' => Device::STATUS_ACTIVE]);
        $excluded = Device::create(['rustdesk_id' => '103', 'uuid' => 'excluded', 'status' => Device::STATUS_ACTIVE]);

        Setting::put('apprise_scope_device_offline', 'selected');
        Setting::put('apprise_scope_groups_device_offline', json_encode([$group->id]));
        Setting::put('apprise_scope_devices_device_offline', json_encode([$includedDirectly->id]));

        $notifications = app(AppriseNotifications::class);
        $this->assertSame('sent', $notifications->send('device.offline', 'Offline', 'Grouped', 'device:101', $includedByGroup)?->status);
        $this->assertSame('sent', $notifications->send('device.offline', 'Offline', 'Direct', 'device:102', $includedDirectly)?->status);
        $this->assertNull($notifications->send('device.offline', 'Offline', 'Excluded', 'device:103', $excluded));
        Http::assertSentCount(2);
    }

    public function test_it_uses_stateless_urls_without_exposing_them_in_the_delivery_log(): void
    {
        $this->configureApprise(['security_alarm'], 'urls');
        Setting::put('apprise_urls', Crypt::encryptString(json_encode([
            'jsons://hooks.example.test/secret-token',
        ])));
        Http::fake(['https://api.example.test/apprise/notify?access_token=secret' => Http::response(['result' => 'success'])]);

        $delivery = app(AppriseNotifications::class)->send('security.alarm', 'Security alarm', 'Body', 'alarm:4');

        $this->assertSame('sent', $delivery?->status);
        Http::assertSent(fn ($request) => $request['urls'] === ['jsons://hooks.example.test/secret-token']);
        $this->assertStringNotContainsString('secret-token', (string) $delivery?->error);
        $this->assertStringNotContainsString('hooks.example.test', (string) $delivery?->error);
        $this->assertSame('Delivery to [redacted URL] failed', AppriseNotifications::redact('Delivery to jsons://hooks.example.test/secret-token failed'));
    }

    public function test_settings_save_encrypts_write_only_apprise_transport_fields(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(SettingsPage::class)
            ->set('appriseEndpoint', 'https://apprise.example.test?access_token=secret')
            ->set('appriseConfigKey', 'office-alerts')
            ->set('appriseEnabled', true)
            ->set('appriseEvents.security_alarm', true)
            ->set('appriseScopes.device_offline', 'selected')
            ->set('appriseScopeGroups.device_offline', [12])
            ->set('appriseScopeDevices.device_offline', [34])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('appriseEndpoint', '')
            ->assertSet('appriseConfigKey', '');

        $storedEndpoint = (string) Setting::get('apprise_endpoint', '');
        $this->assertStringNotContainsString('apprise.example.test', $storedEndpoint);
        $this->assertSame('https://apprise.example.test?access_token=secret', Crypt::decryptString($storedEndpoint));
        $this->assertSame('office-alerts', Crypt::decryptString((string) Setting::get('apprise_config_key', '')));
        $this->assertSame('1', Setting::get('apprise_event_security_alarm'));
        $this->assertSame('selected', Setting::get('apprise_scope_device_offline'));
        $this->assertSame([12], json_decode((string) Setting::get('apprise_scope_groups_device_offline'), true));
        $this->assertSame([34], json_decode((string) Setting::get('apprise_scope_devices_device_offline'), true));
    }

    public function test_reliable_api_and_console_paths_emit_their_enabled_events(): void
    {
        $this->configureApprise([
            'device_pending_approval',
            'console_login_failed',
            'security_alarm',
            'remote_connection_failure',
        ]);
        Setting::put('require_device_approval', '1');
        Http::fake(['*' => Http::response(['result' => 'success'])]);

        $this->postJson('/api/sysinfo', [
            'id' => '123456789',
            'uuid' => 'new-device',
            'hostname' => 'Awaiting approval',
        ])->assertOk();

        $this->post('/login', ['username' => 'nobody', 'password' => 'wrong'])
            ->assertSessionHasErrors('username');

        $this->postJson('/api/audit/alarm', [
            'id' => '123456789',
            'uuid' => 'new-device',
            'typ' => 1,
            'info' => '{"detail":"many failures"}',
        ])->assertOk();

        $sentBodies = collect(Http::recorded())->map(fn ($pair) => (string) $pair[0]['body'])->all();
        $this->assertFalse(collect($sentBodies)->contains(fn ($body) => str_contains($body, 'nobody') || str_contains($body, 'wrong')));
        $this->assertDatabaseHas('notification_deliveries', ['event' => 'device.pending_approval', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_deliveries', ['event' => 'console.login_failed', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_deliveries', ['event' => 'security.alarm', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_deliveries', ['event' => 'remote_connection.failure', 'status' => 'sent']);
    }

    public function test_delivery_exceptions_are_redacted_and_never_escape(): void
    {
        $this->configureApprise(['security_alarm']);
        Log::spy();
        Http::fake(fn () => throw new \RuntimeException('token=top-secret https://private.example.test/hook'));

        $delivery = app(AppriseNotifications::class)->send('security.alarm', 'Alarm', 'Body', 'alarm:5');

        $this->assertSame('failed', $delivery?->status);
        $this->assertStringNotContainsString('top-secret', (string) $delivery?->error);
        $this->assertStringNotContainsString('private.example.test', (string) $delivery?->error);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Apprise notification delivery failed.'
            && ! str_contains((string) $context['error'], 'top-secret')
            && ! str_contains((string) $context['error'], 'private.example.test'));
    }

    public function test_presence_command_detects_offline_and_recovery_once_each(): void
    {
        $this->configureApprise(['device_offline', 'device_online']);
        Setting::put('online_window', '60');
        Http::fake(['*' => Http::response(['result' => 'success'])]);

        $device = Device::create([
            'rustdesk_id' => '123456789',
            'uuid' => 'device-one',
            'hostname' => 'Workstation',
            'status' => Device::STATUS_ACTIVE,
            'last_online_at' => now()->subMinutes(2),
        ]);

        $this->artisan(CheckDeviceNotifications::class)->assertExitCode(0);
        $this->assertDatabaseHas('notification_deliveries', [
            'event' => 'device.offline',
            'subject' => 'device:'.$device->rustdesk_id,
            'status' => 'sent',
        ]);

        $device->update(['last_online_at' => now()]);
        $this->artisan(CheckDeviceNotifications::class)->assertExitCode(0);
        $this->assertDatabaseHas('notification_deliveries', [
            'event' => 'device.online',
            'subject' => 'device:'.$device->rustdesk_id,
            'status' => 'sent',
        ]);

        $this->assertSame(2, NotificationDelivery::query()->count());
    }

    /** @param list<string> $events */
    private function configureApprise(array $events, string $mode = 'config'): void
    {
        Cache::clear();
        Setting::put('apprise_enabled', '1');
        Setting::put('apprise_endpoint', Crypt::encryptString('https://api.example.test/apprise?access_token=secret'));
        Setting::put('apprise_delivery_mode', $mode);
        Setting::put('apprise_config_key', Crypt::encryptString('office-alerts'));
        Setting::put('apprise_cooldown_minutes', '15');

        foreach (AppriseNotifications::EVENTS as $event => $_) {
            Setting::put('apprise_event_'.str_replace('.', '_', $event), in_array(str_replace('.', '_', $event), $events, true) ? '1' : '0');
        }
    }
}
