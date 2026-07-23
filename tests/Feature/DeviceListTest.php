<?php

use App\Livewire\DeviceList;
use App\Models\Device;
use App\Models\User;
use Livewire\Livewire;

function actingAdmin(): User
{
    $user = User::create([
        'username' => 'admin-test',
        'password' => 'secret-password',
        'is_admin' => true,
    ]);
    test()->actingAs($user);

    return $user;
}

function makeDevice(array $attrs = []): Device
{
    static $n = 0;
    $n++;

    return Device::create(array_merge([
        'rustdesk_id' => "10000000$n",
        'uuid' => "uuid-$n",
        'hostname' => "host-$n",
        'os' => 'Windows 10',
        'username' => 'user',
        'version' => '1.4.2',
    ], $attrs));
}

it('renders the device list', function () {
    actingAdmin();
    makeDevice(['alias' => 'Front Desk']);

    Livewire::test(DeviceList::class)
        ->assertSee('Front Desk')
        ->assertSee('100000001');
});

it('filters by search', function () {
    actingAdmin();
    makeDevice(['alias' => 'Front Desk']);
    makeDevice(['alias' => 'Server Rack', 'hostname' => 'rack01']);

    Livewire::test(DeviceList::class)
        ->set('search', 'rack')
        ->assertSee('Server Rack')
        ->assertDontSee('Front Desk');
});

it('filters online devices by heartbeat window', function () {
    actingAdmin();
    makeDevice(['alias' => 'Fresh', 'last_online_at' => now()->subSeconds(10)]);
    makeDevice(['alias' => 'Stale', 'last_online_at' => now()->subMinutes(10)]);

    Livewire::test(DeviceList::class)
        ->set('status', 'online')
        ->assertSee('Fresh')
        ->assertDontSee('Stale');
});

it('edits alias and note', function () {
    actingAdmin();
    $device = makeDevice();

    Livewire::test(DeviceList::class)
        ->call('edit', $device->id)
        ->set('formAlias', 'Renamed')
        ->set('formNote', 'important box')
        ->call('save')
        ->assertHasNoErrors();

    expect($device->fresh())->alias->toBe('Renamed')->note->toBe('important box');
});

it('pre-registers a device by id', function () {
    actingAdmin();

    Livewire::test(DeviceList::class)
        ->call('create')
        ->set('formRustdeskId', '555444333')
        ->set('formAlias', 'Incoming laptop')
        ->call('save')
        ->assertHasNoErrors();

    expect(Device::where('rustdesk_id', '555444333')->exists())->toBeTrue();
});

it('rejects duplicate rustdesk ids on create', function () {
    actingAdmin();
    makeDevice(['rustdesk_id' => '777777777']);

    Livewire::test(DeviceList::class)
        ->call('create')
        ->set('formRustdeskId', '777777777')
        ->call('save')
        ->assertHasErrors('formRustdeskId');
});

it('soft deletes into the recycle bin, restores, and destroys', function () {
    actingAdmin();
    $device = makeDevice();

    $component = Livewire::test(DeviceList::class)
        ->call('deleteDevice', $device->id);

    expect(Device::count())->toBe(0)
        ->and(Device::onlyTrashed()->count())->toBe(1);

    $component->set('trashed', true)
        ->assertSee($device->rustdesk_id)
        ->call('restoreDevice', $device->id);

    expect(Device::count())->toBe(1);

    $component->call('deleteDevice', $device->id)
        ->call('forceDeleteDevice', $device->id);

    expect(Device::withTrashed()->count())->toBe(0);
});

it('shows a Web Client link carrying the device id when configured', function () {
    config(['cortendesk.webclient_url' => 'http://example.test/webclient/']);
    actingAdmin();
    $device = makeDevice(['rustdesk_id' => '987654321']);

    Livewire::test(DeviceList::class)
        ->assertSee('http://example.test/webclient/?id=987654321', false)
        ->assertSee('Web Client');
});

it('hides the Web Client link when no webclient_url is configured', function () {
    config(['cortendesk.webclient_url' => '']);
    actingAdmin();
    makeDevice();

    Livewire::test(DeviceList::class)->assertDontSee('Web Client');
});
