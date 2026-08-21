<?php

use App\Livewire\DeviceList;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Livewire\Livewire;

test('a device manager bulk moves only visible selected devices and reports moved and unchanged counts', function () {
    $manager = User::factory()->create();
    $source = DeviceGroup::create(['name' => 'Source']);
    $target = DeviceGroup::create(['name' => 'Target']);
    $hiddenGroup = DeviceGroup::create(['name' => 'Hidden']);
    $manager->deviceGroups()->attach([$source->id, $target->id]);

    $moved = Device::create([
        'rustdesk_id' => '100',
        'uuid' => 'move',
        'status' => Device::STATUS_ACTIVE,
        'device_group_id' => $source->id,
    ]);
    $unchanged = Device::create([
        'rustdesk_id' => '101',
        'uuid' => 'same',
        'status' => Device::STATUS_ACTIVE,
        'device_group_id' => $target->id,
    ]);
    $hidden = Device::create([
        'rustdesk_id' => '102',
        'uuid' => 'hidden',
        'status' => Device::STATUS_ACTIVE,
        'device_group_id' => $hiddenGroup->id,
    ]);

    $this->actingAs($manager);

    Livewire::test(DeviceList::class)
        ->set('selected', [(string) $moved->id, (string) $unchanged->id, (string) $hidden->id])
        ->set('moveGroupId', $target->id)
        ->call('moveSelectedToGroup')
        ->assertSet('selected', [])
        ->assertSet('bulkResult', 'Moved 1 device to Target. 1 unchanged.');

    expect($moved->fresh()->device_group_id)->toBe($target->id)
        ->and($unchanged->fresh()->device_group_id)->toBe($target->id)
        ->and($hidden->fresh()->device_group_id)->toBe($hiddenGroup->id);

    $this->assertDatabaseHas('console_audits', [
        'user_id' => $manager->id,
        'action' => 'device.group-move',
        'target_type' => 'device-group',
        'target_id' => (string) $target->id,
        'summary' => 'Moved 1 device to Target; 1 unchanged.',
    ]);
    expect(ConsoleAudit::count())->toBe(1);
});

test('a device manager cannot bulk move a selected device from another page', function () {
    $manager = User::factory()->create();
    $source = DeviceGroup::create(['name' => 'Source']);
    $target = DeviceGroup::create(['name' => 'Target']);
    $manager->deviceGroups()->attach([$source->id, $target->id]);

    $firstPage = Device::create([
        'rustdesk_id' => '300',
        'uuid' => 'first-page',
        'status' => Device::STATUS_ACTIVE,
        'device_group_id' => $source->id,
    ]);
    $currentPage = Device::create([
        'rustdesk_id' => '301',
        'uuid' => 'current-page',
        'status' => Device::STATUS_ACTIVE,
        'device_group_id' => $source->id,
    ]);

    $this->actingAs($manager);

    Livewire::test(DeviceList::class)
        ->set('perPage', 1)
        ->call('gotoPage', 2)
        ->set('selected', [(string) $firstPage->id, (string) $currentPage->id])
        ->set('moveGroupId', $target->id)
        ->call('moveSelectedToGroup')
        ->assertSet('bulkResult', 'Moved 1 device to Target. 0 unchanged.');

    expect($firstPage->fresh()->device_group_id)->toBe($source->id)
        ->and($currentPage->fresh()->device_group_id)->toBe($target->id);
});

test('a device manager cannot bulk move a selected device excluded by the active filter', function () {
    $manager = User::factory()->create();
    $source = DeviceGroup::create(['name' => 'Source']);
    $target = DeviceGroup::create(['name' => 'Target']);
    $manager->deviceGroups()->attach([$source->id, $target->id]);

    $matching = Device::create([
        'rustdesk_id' => '400',
        'uuid' => 'matching-filter',
        'hostname' => 'included',
        'status' => Device::STATUS_ACTIVE,
        'device_group_id' => $source->id,
    ]);
    $excluded = Device::create([
        'rustdesk_id' => '401',
        'uuid' => 'excluded-filter',
        'hostname' => 'excluded',
        'status' => Device::STATUS_ACTIVE,
        'device_group_id' => $source->id,
    ]);

    $this->actingAs($manager);

    Livewire::test(DeviceList::class)
        ->set('search', 'included')
        ->set('selected', [(string) $excluded->id])
        ->set('moveGroupId', $target->id)
        ->call('moveSelectedToGroup')
        ->assertSet('selected', [])
        ->assertSet('bulkResult', '');

    expect($matching->fresh()->device_group_id)->toBe($source->id)
        ->and($excluded->fresh()->device_group_id)->toBe($source->id)
        ->and(ConsoleAudit::count())->toBe(0);
});

test('a device manager bulk move with an empty selection does nothing', function () {
    $manager = User::factory()->create();
    $source = DeviceGroup::create(['name' => 'Source']);
    $manager->deviceGroups()->attach($source->id);
    $device = Device::create([
        'rustdesk_id' => '500',
        'uuid' => 'empty-selection',
        'status' => Device::STATUS_ACTIVE,
        'device_group_id' => $source->id,
    ]);

    $this->actingAs($manager);

    Livewire::test(DeviceList::class)
        ->call('moveSelectedToGroup')
        ->assertHasNoErrors()
        ->assertSet('selected', [])
        ->assertSet('bulkResult', '');

    expect($device->fresh()->device_group_id)->toBe($source->id)
        ->and(ConsoleAudit::count())->toBe(0);
});

test('a device manager can bulk remove visible selected devices from their group', function () {
    $manager = User::factory()->create();
    $source = DeviceGroup::create(['name' => 'Source']);
    $manager->deviceGroups()->attach($source->id);
    $device = Device::create([
        'rustdesk_id' => '200',
        'uuid' => 'remove-group',
        'status' => Device::STATUS_ACTIVE,
        'device_group_id' => $source->id,
    ]);

    $this->actingAs($manager);

    Livewire::test(DeviceList::class)
        ->set('selected', [(string) $device->id])
        ->set('moveGroupId', 0)
        ->call('moveSelectedToGroup')
        ->assertSet('bulkResult', 'Moved 1 device to No group. 0 unchanged.');

    expect($device->fresh()->device_group_id)->toBeNull();
});
