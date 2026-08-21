<?php

use App\Livewire\SettingsPage;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DevicePresenceNotificationState;
use App\Models\DevicePresenceSnooze;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Setting::put('online_window', '60');
    Setting::put('apprise_offline_grace_minutes', '10');
    Setting::put('apprise_enabled', '1');
    Setting::put('apprise_event_device_offline', '1');
    Setting::put('apprise_event_device_online', '1');
    Setting::put('apprise_delivery_mode', 'config');
    Setting::put('apprise_endpoint', Crypt::encryptString('https://apprise.test'));
    Setting::put('apprise_config_key', Crypt::encryptString('alerts'));
    Setting::put('apprise_cooldown_minutes', '0');

    Http::fake(['https://apprise.test/notify/alerts' => Http::response([], 200)]);
});

function presenceDevice(array $attributes = []): Device
{
    return Device::query()->create(array_merge([
        'rustdesk_id' => 'device-'.uniqid(),
        'uuid' => 'uuid-'.uniqid(),
        'status' => Device::STATUS_ACTIVE,
        'hostname' => 'Test device',
        'last_online_at' => now()->subMinutes(11),
    ], $attributes));
}

test('offline delivery waits for the configured grace period before recording the outage', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $device = presenceDevice(['last_online_at' => now()->subMinutes(9)->subSeconds(60)]);

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertNothingSent();
    expect(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeFalse();

    Carbon::setTestNow(now()->addMinutes(2));
    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertSentCount(1);
    expect(DevicePresenceNotificationState::query()->where('device_id', $device->id)->value('offline_notified_at'))->not->toBeNull();
});

test('an active group snooze suppresses presence notifications and expires at its configured time', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $group = DeviceGroup::query()->create(['name' => 'Planned maintenance']);
    $device = presenceDevice(['device_group_id' => $group->id]);

    DevicePresenceSnooze::snoozeGroup($group, now()->addHours(2));
    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertNothingSent();
    expect(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeFalse();

    Carbon::setTestNow(now()->addHours(2)->addSecond());
    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertSentCount(1);
});

test('recovery is sent only for an outage whose offline delivery succeeded', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $delivered = presenceDevice(['rustdesk_id' => 'delivered-outage']);
    $undelivered = presenceDevice(['rustdesk_id' => 'undelivered-outage']);

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();
    expect(DevicePresenceNotificationState::query()->where('device_id', $delivered->id)->value('offline_notified_at'))->not->toBeNull();

    DevicePresenceNotificationState::query()->where('device_id', $undelivered->id)->delete();
    $delivered->update(['last_online_at' => now()]);
    $undelivered->update(['last_online_at' => now()]);

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertSentCount(3);
    expect(DevicePresenceNotificationState::query()->whereIn('device_id', [$delivered->id, $undelivered->id])->exists())->toBeFalse();
});

test('a recovery marker has exactly one atomic consumer', function (): void {
    $device = presenceDevice();
    DevicePresenceNotificationState::query()->create([
        'device_id' => $device->id,
        'offline_notified_at' => now(),
    ]);

    expect(DevicePresenceNotificationState::consumeFor($device))->toBeTrue()
        ->and(DevicePresenceNotificationState::consumeFor($device))->toBeFalse()
        ->and(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeFalse();
});

test('an administrator can create a bounded group maintenance snooze', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $group = DeviceGroup::query()->create(['name' => 'Weekend work']);
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(SettingsPage::class)
        ->set('presenceSnoozeTargetType', 'group')
        ->set('presenceSnoozeTargetId', $group->id)
        ->set('presenceSnoozeMinutes', 90)
        ->call('createPresenceSnooze')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('device_presence_snoozes', [
        'target_type' => 'group',
        'target_id' => $group->id,
        'expires_at' => now()->addMinutes(90),
    ]);
});

function delegatedSettingsManager(): User
{
    $role = Role::query()->create([
        'name' => 'Settings manager',
        'permissions' => ['setting' => 'rw'],
    ]);

    return User::factory()->create(['role_id' => $role->id]);
}

test('a delegated settings manager cannot create a presence snooze', function (): void {
    $group = DeviceGroup::query()->create(['name' => 'Private maintenance']);
    $manager = delegatedSettingsManager();

    Livewire::actingAs($manager)
        ->test(SettingsPage::class)
        ->set('presenceSnoozeTargetType', 'group')
        ->set('presenceSnoozeTargetId', $group->id)
        ->call('createPresenceSnooze')
        ->assertForbidden();

    expect(DevicePresenceSnooze::query()->count())->toBe(0);
});

test('a delegated settings manager cannot end a presence snooze', function (): void {
    $device = presenceDevice();
    $snooze = DevicePresenceSnooze::snoozeDevice($device, now()->addHour());
    $manager = delegatedSettingsManager();

    Livewire::actingAs($manager)
        ->test(SettingsPage::class)
        ->call('clearPresenceSnooze', $snooze->id)
        ->assertForbidden();

    expect(DevicePresenceSnooze::query()->whereKey($snooze->id)->exists())->toBeTrue();
});

test('a delegated settings manager cannot see presence maintenance controls or targets', function (): void {
    $group = DeviceGroup::query()->create(['name' => 'Secret fleet group']);
    $device = presenceDevice(['hostname' => 'Secret fleet device']);
    DevicePresenceSnooze::snoozeGroup($group, now()->addHour());
    $manager = delegatedSettingsManager();

    Livewire::actingAs($manager)
        ->test(SettingsPage::class)
        ->assertDontSee('Presence alert maintenance')
        ->assertDontSee('Secret fleet group')
        ->assertDontSee('Secret fleet device')
        ->assertDontSee('Snooze alerts')
        ->assertDontSee('End now');
});
