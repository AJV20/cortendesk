<?php

use App\Livewire\StrategyList;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\StrategyRevision;
use App\Models\StrategyRollout;
use App\Models\User;
use App\Services\StrategyCompliance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function complianceDevice(string $id, array $attributes = []): Device
{
    return Device::create([
        'rustdesk_id' => $id,
        'uuid' => 'compliance-'.$id,
        'hostname' => 'host-'.$id,
        'status' => Device::STATUS_ACTIVE,
        ...$attributes,
    ]);
}

it('adds and cleanly rolls back only the delivery sent timestamp required for reporting', function () {
    expect(Schema::hasColumn('devices', 'strategy_sent_at'))->toBeTrue();

    $migration = require database_path('migrations/2026_08_24_000030_add_strategy_sent_at_to_devices.php');
    $migration->down();
    expect(Schema::hasColumn('devices', 'strategy_sent_at'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('devices', 'strategy_sent_at'))->toBeTrue();
});

it('classifies strategy state as confirmed pending stale offline or overridden', function () {
    $strategy = Strategy::create([
        'name' => 'Compliance policy',
        'enabled' => true,
        'confirmation_timeout_minutes' => 15,
        'options' => ['enable-file-transfer' => 'N'],
    ]);
    $other = Strategy::create(['name' => 'Override', 'enabled' => true]);

    $confirmed = complianceDevice('984000010', ['last_online_at' => now()->subHour()]);
    $confirmed->forceFill([
        'strategy_options' => ['enable-file-transfer' => 'N'],
        'strategy_acked_options' => ['enable-file-transfer' => 'N'],
        'strategy_sent_at' => now()->subMinutes(2),
        'strategy_acked_at' => now()->subMinute(),
    ])->saveQuietly();
    $pending = complianceDevice('984000011', ['last_online_at' => now()->subHour()]);
    $pending->forceFill([
        'strategy_options' => ['enable-file-transfer' => 'N'],
        'strategy_sent_at' => now()->subMinutes(5),
    ])->saveQuietly();
    $stale = complianceDevice('984000012', ['last_online_at' => now()]);
    $stale->forceFill([
        'strategy_options' => ['enable-file-transfer' => 'N'],
        'strategy_sent_at' => now()->subMinutes(30),
    ])->saveQuietly();
    $offline = complianceDevice('984000013', ['last_online_at' => now()->subHour()]);
    $offline->forceFill([
        'strategy_options' => ['enable-file-transfer' => 'N'],
        'strategy_sent_at' => now()->subMinutes(30),
    ])->saveQuietly();

    foreach ([$confirmed, $pending, $stale, $offline] as $device) {
        Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    }

    $group = DeviceGroup::create(['name' => 'Overridden compliance group']);
    $overridden = complianceDevice('984000014', ['device_group_id' => $group->id, 'last_online_at' => now()]);
    Strategy::assignTo(Strategy::LEVEL_DEVICE_GROUP, $group->id, $strategy->id);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $overridden->id, $other->id);

    $summary = app(StrategyCompliance::class)->summary($strategy->fresh());

    expect($summary['counts'])->toBe([
        'confirmed' => 1,
        'pending' => 1,
        'stale' => 1,
        'offline' => 1,
        'overridden' => 1,
    ])->and($summary['devices']['stale'][0]['rustdesk_id'])->toBe('984000012');
});

it('starts a fresh grace window when desired policy changes before delivery', function () {
    $strategy = Strategy::create([
        'name' => 'Fresh desired policy',
        'enabled' => true,
        'confirmation_timeout_minutes' => 15,
        'options' => ['enable-audio' => 'N'],
    ]);
    $device = complianceDevice('984000016', ['last_online_at' => now()]);
    $device->forceFill([
        'strategy_options' => ['enable-audio' => 'N'],
        'strategy_acked_options' => ['enable-audio' => 'N'],
        'strategy_sent_at' => now()->subHour(),
        'strategy_acked_at' => now()->subMinutes(59),
    ])->saveQuietly();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    $strategy->setOptions(['enable-audio' => 'Y']);
    $strategy->save();
    $summary = app(StrategyCompliance::class)->summary($strategy->fresh());

    expect($summary['counts']['pending'])->toBe(1)
        ->and($summary['counts']['stale'])->toBe(0);
});

it('records sent and acknowledged timestamps through the delivery handshake', function () {
    $strategy = Strategy::create([
        'name' => 'Delivered compliance policy',
        'enabled' => true,
        'options' => ['enable-file-transfer' => 'N'],
    ]);
    $device = complianceDevice('984000020', ['last_online_at' => now()]);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    $message = Strategy::deliveryFor($device->fresh(), 0);
    expect($message)->not->toBeNull()
        ->and($device->fresh()->strategy_sent_at)->not->toBeNull();

    Strategy::deliveryFor($device->fresh(), $message['modified_at']);
    $summary = app(StrategyCompliance::class)->summary($strategy);

    expect($device->fresh()->strategy_acked_at)->not->toBeNull()
        ->and($summary['counts']['confirmed'])->toBe(1);

    $upgraded = complianceDevice('984000021', ['last_online_at' => now()]);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $upgraded->id, $strategy->id);
    $upgraded->forceFill([
        'strategy_version' => 123,
        'strategy_options' => ['enable-file-transfer' => 'N'],
        'strategy_sent_at' => null,
    ])->saveQuietly();

    expect(Strategy::deliveryFor($upgraded->fresh(), 0))->not->toBeNull()
        ->and($upgraded->fresh()->strategy_sent_at)->not->toBeNull();
});

it('retains timed-out rollout evidence after the candidate becomes active', function () {
    Carbon::setTestNow('2026-08-24 16:00:00');
    $strategy = Strategy::create([
        'name' => 'Timed rollout compliance',
        'enabled' => true,
        'confirmation_timeout_minutes' => 1,
        'options' => ['enable-audio' => 'N'],
    ]);
    $candidate = StrategyRevision::captureSnapshot(
        $strategy,
        [...$strategy->snapshot(), 'options' => ['enable-audio' => 'Y']],
        null,
        'Timed candidate',
    );
    $device = complianceDevice('984000030', ['last_online_at' => now()]);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    $rollout = StrategyRollout::schedule($strategy, $candidate, [$device->id], now(), 1, 1, null);

    Carbon::setTestNow(now()->addMinute());
    StrategyRollout::advanceDue();
    $device->forceFill(['last_online_at' => now()])->saveQuietly();
    $summary = app(StrategyCompliance::class)->summary($strategy->fresh());

    expect($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_COMPLETED)
        ->and($rollout->targets()->whereNotNull('timed_out_at')->count())->toBe(1)
        ->and($summary['counts']['stale'])->toBe(1);

    $override = Strategy::create(['name' => 'Post-rollout override', 'enabled' => true]);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $override->id);
    $summary = app(StrategyCompliance::class)->summary($strategy->fresh());
    expect($summary['counts']['overridden'])->toBe(1);
    Carbon::setTestNow();
});

it('keeps fleet summaries query-bounded and drill-down payloads capped', function () {
    $first = Strategy::create(['name' => 'Bounded compliance first', 'enabled' => true]);
    $second = Strategy::create(['name' => 'Bounded compliance second', 'enabled' => true]);
    $first->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
    $second->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    foreach (range(1, 30) as $index) {
        $device = complianceDevice((string) (984100000 + $index), ['last_online_at' => now()->subHour()]);
        Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $index % 2 === 0 ? $first->id : $second->id);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $summaries = app(StrategyCompliance::class)->summaries(collect([$first, $second]));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($summaries[$first->id]['counts']['offline'])->toBe(15)
        ->and($summaries[$second->id]['counts']['offline'])->toBe(15)
        ->and($queryCount)->toBeLessThanOrEqual(6);

    $ids = [];
    foreach (range(1, 205) as $number) {
        $ids[] = complianceDevice((string) (984200000 + $number), ['last_online_at' => now()->subHour()])->id;
    }
    DB::table('devices')->whereIn('id', $ids)->update(['strategy_id_resolved' => $first->id]);
    $summary = app(StrategyCompliance::class)->summary($first);

    expect($summary['counts']['offline'])->toBe(220)
        ->and(count($summary['devices']['offline']))->toBe(StrategyCompliance::DETAIL_LIMIT);
});

it('shows bounded compliance drill-downs to admins only', function () {
    $strategy = Strategy::create(['name' => 'Compliance UI', 'enabled' => true]);
    $device = complianceDevice('984300001', ['last_online_at' => now()->subHour()]);
    $device->forceFill(['strategy_sent_at' => now()->subHour()])->saveQuietly();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(StrategyList::class)
        ->call('showCompliance', $strategy->id, 'offline')
        ->assertSet('complianceStrategyId', $strategy->id)
        ->assertSet('complianceState', 'offline')
        ->assertSee('Compliance UI compliance')
        ->assertSee('984300001');

    $role = Role::create(['name' => 'Strategy reader', 'permissions' => ['strategy' => 'r']]);
    $reader = User::factory()->create(['is_admin' => false, 'role_id' => $role->id]);
    Livewire::actingAs($reader)->test(StrategyList::class)
        ->assertDontSeeHtml('wire:click="showCompliance')
        ->call('showCompliance', $strategy->id)
        ->assertForbidden();
});
