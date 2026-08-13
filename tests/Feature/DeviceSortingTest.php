<?php

namespace Tests\Feature;

use App\Livewire\DeviceList;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceSortingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sorting_by_alias_toggles_direction_and_keeps_nulls_last(): void
    {
        $admin = User::factory()->admin()->create();
        $this->device('100', alias: 'Zulu', seenAt: now()->subMinute());
        $this->device('200', alias: null, seenAt: now());
        $this->device('300', alias: 'Alpha', seenAt: now()->subMinutes(2));

        $component = Livewire::actingAs($admin)
            ->test(DeviceList::class)
            ->call('sortBy', 'alias')
            ->assertSet('sortField', 'alias')
            ->assertSet('sortDirection', 'asc')
            ->assertViewHas('devices', fn ($devices) => $devices->pluck('rustdesk_id')->all() === ['300', '100', '200']);

        $component
            ->call('sortBy', 'alias')
            ->assertSet('sortDirection', 'desc')
            ->assertViewHas('devices', fn ($devices) => $devices->pluck('rustdesk_id')->all() === ['100', '300', '200']);
    }

    public function test_sorting_supports_group_owner_status_and_last_seen(): void
    {
        $admin = User::factory()->admin()->create();
        $alphaGroup = DeviceGroup::create(['name' => 'Alpha']);
        $zuluGroup = DeviceGroup::create(['name' => 'Zulu']);
        $amy = User::factory()->create(['username' => 'amy']);
        $zoe = User::factory()->create(['username' => 'zoe']);

        $this->device('100', group: $zuluGroup, owner: $amy, seenAt: now()->subMinutes(10));
        $this->device('200', group: $alphaGroup, owner: $zoe, seenAt: now());
        $this->device('300', group: null, owner: null, seenAt: null);

        Livewire::actingAs($admin)
            ->test(DeviceList::class)
            ->call('sortBy', 'group')
            ->assertViewHas('devices', fn ($devices) => $devices->pluck('rustdesk_id')->all() === ['200', '100', '300'])
            ->call('sortBy', 'owner')
            ->assertViewHas('devices', fn ($devices) => $devices->pluck('rustdesk_id')->all() === ['100', '200', '300'])
            ->call('sortBy', 'status')
            ->assertViewHas('devices', fn ($devices) => $devices->pluck('rustdesk_id')->all() === ['100', '300', '200'])
            ->call('sortBy', 'status')
            ->assertViewHas('devices', fn ($devices) => $devices->pluck('rustdesk_id')->all() === ['200', '100', '300'])
            ->call('sortBy', 'last_seen')
            ->assertViewHas('devices', fn ($devices) => $devices->pluck('rustdesk_id')->all() === ['100', '200', '300']);
    }

    public function test_sort_preference_survives_a_fresh_component_mount(): void
    {
        $admin = User::factory()->admin()->create();
        $this->device('100', alias: 'Zulu');
        $this->device('200', alias: 'Alpha');

        Livewire::actingAs($admin)
            ->test(DeviceList::class)
            ->call('sortBy', 'alias')
            ->call('sortBy', 'alias');

        $admin->refresh();
        $this->assertSame('alias', $admin->devices_sort);
        $this->assertSame('desc', $admin->devices_sort_direction);

        Livewire::actingAs($admin)
            ->test(DeviceList::class)
            ->assertSet('sortField', 'alias')
            ->assertSet('sortDirection', 'desc')
            ->assertViewHas('devices', fn ($devices) => $devices->pluck('rustdesk_id')->all() === ['100', '200']);
    }

    public function test_default_sort_remains_last_seen_descending_and_export_uses_the_same_order(): void
    {
        $admin = User::factory()->admin()->create();
        $this->device('100', seenAt: now()->subMinutes(2));
        $this->device('200', seenAt: now());
        $this->device('300', seenAt: null);

        $component = Livewire::actingAs($admin)
            ->test(DeviceList::class)
            ->assertViewHas('devices', fn ($devices) => $devices->pluck('rustdesk_id')->all() === ['200', '100', '300']);

        $component->call('sortBy', 'id');
        $response = $component->instance()->exportCsv();
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();
        $this->assertStringContainsString('1,100,', $csv);
        $this->assertLessThan(strpos($csv, ',200,'), strpos($csv, ',100,'));
    }

    public function test_non_admin_cannot_select_owner_sort_and_unknown_fields_are_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(DeviceList::class)
            ->call('sortBy', 'owner')
            ->assertSet('sortField', 'last_seen')
            ->call('sortBy', 'drop table devices')
            ->assertSet('sortField', 'last_seen');
    }

    private function device(
        string $id,
        ?string $alias = null,
        ?DeviceGroup $group = null,
        ?User $owner = null,
        $seenAt = null,
    ): Device {
        return Device::create([
            'rustdesk_id' => $id,
            'uuid' => 'uuid-'.$id,
            'alias' => $alias,
            'device_group_id' => $group?->id,
            'user_id' => $owner?->id,
            'last_online_at' => $seenAt,
        ]);
    }
}
