<?php

use App\Livewire\StrategyList;
use App\Models\Device;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\StrategyRevision;
use App\Models\StrategyRollout;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('installs the additive rollout foundation schema', function () {
    expect(Schema::hasTable('strategy_rollouts'))->toBeTrue()
        ->and(Schema::hasTable('strategy_rollout_devices'))->toBeTrue()
        ->and(Schema::hasColumn('strategies', 'confirmation_timeout_minutes'))->toBeTrue()
        ->and(Schema::hasColumn('devices', 'strategy_rollout_ack_pending'))->toBeTrue()
        ->and(Schema::hasColumn('strategy_rollout_devices', 'timed_out_at'))->toBeTrue();
});

it('rolls the additive rollout foundation back in dependency-safe order', function () {
    $migration = require database_path('migrations/2026_08_24_000020_add_strategy_rollout_foundation.php');

    $migration->down();

    expect(Schema::hasTable('strategy_rollout_devices'))->toBeFalse()
        ->and(Schema::hasTable('strategy_rollouts'))->toBeFalse()
        ->and(Schema::hasColumn('devices', 'strategy_rollout_ack_pending'))->toBeFalse()
        ->and(Schema::hasColumn('strategies', 'confirmation_timeout_minutes'))->toBeFalse();
});

it('schedules a reviewed frozen rollout without applying the candidate globally', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create([
        'name' => 'Reviewed rollout',
        'enabled' => true,
        'options' => ['enable-file-transfer' => 'N'],
    ]);
    $baseline = StrategyRevision::capture($strategy, $admin->id, 'Baseline');
    $strategy->forceFill(['active_revision_id' => $baseline->id])->saveQuietly();
    $device = Device::create(['rustdesk_id' => 'reviewed-rollout-device', 'uuid' => 'reviewed-rollout-device', 'status' => Device::STATUS_ACTIVE]);
    $secondDevice = Device::create(['rustdesk_id' => 'reviewed-rollout-device-2', 'uuid' => 'reviewed-rollout-device-2', 'status' => Device::STATUS_ACTIVE]);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $secondDevice->id, $strategy->id);

    Livewire::actingAs($admin)->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-file-transfer', 'Y')
        ->call('save')
        ->assertSet('previewing', true)
        ->set('rolloutBatchSize', 1)
        ->set('rolloutIntervalMinutes', 5)
        ->call('scheduleRollout')
        ->assertHasNoErrors();

    $rollout = StrategyRollout::query()->with('revision')->sole();
    expect($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'N'])
        ->and($rollout->target_count)->toBe(2)
        ->and($rollout->targets()->whereNotNull('released_at')->count())->toBe(1)
        ->and($rollout->revision->snapshot['options'])->toBe(['enable-file-transfer' => 'Y'])
        ->and($strategy->fresh()->active_revision_id)->not->toBe($rollout->revision->id);
});

it('rejects apply-now policy changes while a rollout is open', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create(['name' => 'Locked policy', 'enabled' => true, 'options' => ['enable-file-transfer' => 'N']]);
    $candidate = StrategyRevision::captureSnapshot($strategy, [...$strategy->snapshot(), 'options' => ['enable-file-transfer' => 'Y']], $admin->id, 'Candidate');
    $device = Device::create(['rustdesk_id' => 'locked-policy-device', 'uuid' => 'locked-policy-device', 'status' => Device::STATUS_ACTIVE]);
    StrategyRollout::schedule($strategy, $candidate, [$device->id], now()->addHour(), 1, 5, $admin->id);

    Livewire::actingAs($admin)->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-file-transfer', 'Y')
        ->call('save')
        ->assertSet('previewing', true)
        ->call('confirmSave')
        ->assertHasErrors('strategy');

    expect($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'N']);
});

it('rejects apply-now mutations of a different strategy while a rollout is open', function () {
    $admin = User::factory()->admin()->create();
    $rolling = Strategy::create(['name' => 'Rolling policy', 'enabled' => true]);
    $candidate = StrategyRevision::capture($rolling, $admin->id, 'Candidate');
    $target = Device::create([
        'rustdesk_id' => 'cross-policy-target',
        'uuid' => 'cross-policy-target',
        'status' => Device::STATUS_ACTIVE,
    ]);
    StrategyRollout::schedule($rolling, $candidate, [$target->id], now()->addHour(), 1, 5, $admin->id);

    $other = Strategy::create([
        'name' => 'Different precedence policy',
        'enabled' => false,
        'options' => ['enable-file-transfer' => 'N'],
    ]);

    Livewire::actingAs($admin)->test(StrategyList::class)
        ->call('edit', $other->id)
        ->set('formEnabled', true)
        ->call('save')
        ->assertSet('previewing', true)
        ->call('confirmSave')
        ->assertHasErrors('strategy');

    expect($other->fresh()->enabled)->toBeFalse();
});

it('rejects implicitly active device creation while a rollout is open', function () {
    $strategy = Strategy::create(['name' => 'Frozen default-active fleet', 'enabled' => true]);
    $candidate = StrategyRevision::capture($strategy, null, 'Candidate');
    $target = Device::create([
        'rustdesk_id' => 'existing-active-target',
        'uuid' => 'existing-active-target',
        'status' => Device::STATUS_ACTIVE,
    ]);
    StrategyRollout::schedule($strategy, $candidate, [$target->id], now()->addHour(), 1, 5, null);

    expect(fn () => Device::updateWithStrategyContext(new Device, [
        'rustdesk_id' => 'implicit-active-device',
        'uuid' => '',
    ]))->toThrow(ValidationException::class);

    expect(Device::query()->where('rustdesk_id', 'implicit-active-device')->exists())->toBeFalse();
});

it('keeps fleet-wide rollout controls admin-only', function () {
    $role = Role::create(['name' => 'Strategy editor', 'permissions' => ['strategy' => 'rw']]);
    $editor = User::factory()->create(['is_admin' => false, 'role_id' => $role->id]);
    $strategy = Strategy::create(['name' => 'Hidden rollout controls', 'enabled' => true]);
    $candidate = StrategyRevision::capture($strategy, null, 'Candidate');
    $device = Device::create(['rustdesk_id' => 'admin-rollout-device', 'uuid' => 'admin-rollout-device', 'status' => Device::STATUS_ACTIVE]);
    StrategyRollout::schedule($strategy, $candidate, [$device->id], now()->addHour(), 1, 5, null);

    Livewire::actingAs($editor)->test(StrategyList::class)
        ->assertDontSee('Recent staged rollouts')
        ->call('scheduleRollout')
        ->assertForbidden();
});

it('freezes scheduled rollout targets in stable device order', function () {
    $strategy = Strategy::create(['name' => 'Frozen policy', 'enabled' => true]);
    $revision = StrategyRevision::capture($strategy, null, 'Staged candidate');
    $second = Device::create(['rustdesk_id' => 'rollout-2', 'uuid' => 'rollout-2']);
    $first = Device::create(['rustdesk_id' => 'rollout-1', 'uuid' => 'rollout-1']);

    $rollout = StrategyRollout::schedule(
        $strategy,
        $revision,
        [$second->id, $first->id, $second->id],
        Carbon::now()->addMinute(),
        25,
        15,
        null,
    );

    expect($rollout->status)->toBe(StrategyRollout::STATUS_SCHEDULED)
        ->and($rollout->targets()->orderBy('position')->pluck('device_id')->all())->toBe([$second->id, $first->id])
        ->and($rollout->targets()->orderBy('position')->pluck('device_rustdesk_id')->all())->toBe(['rollout-2', 'rollout-1']);
});

it('starts a due rollout and releases one bounded batch', function () {
    Carbon::setTestNow('2026-08-24 12:00:00');
    $strategy = Strategy::create(['name' => 'Batched policy', 'enabled' => true]);
    $revision = StrategyRevision::capture($strategy, null, 'Candidate');
    $devices = collect(range(1, 3))->map(fn (int $id) => Device::create(['rustdesk_id' => "batch-{$id}", 'uuid' => "batch-{$id}"]));
    $rollout = StrategyRollout::schedule($strategy, $revision, $devices->pluck('id')->all(), now()->addMinute(), 2, 10, null);

    Carbon::setTestNow(now()->addMinute());
    expect(StrategyRollout::advanceDue())->toBe(1)
        ->and($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_ACTIVE)
        ->and($rollout->targets()->whereNotNull('released_at')->count())->toBe(2)
        ->and($rollout->fresh()->next_release_at?->format('Y-m-d H:i:s'))->toBe('2026-08-24 12:11:00');

    Carbon::setTestNow();
});

it('releases the first bounded batch immediately when a rollout starts now', function () {
    Carbon::setTestNow('2026-08-24 12:30:00');
    $strategy = Strategy::create(['name' => 'Immediate policy', 'enabled' => true]);
    $revision = StrategyRevision::capture($strategy, null, 'Candidate');
    $devices = collect(range(1, 3))->map(fn (int $id) => Device::create(['rustdesk_id' => "immediate-{$id}", 'uuid' => "immediate-{$id}"]));

    $rollout = StrategyRollout::schedule($strategy, $revision, $devices->pluck('id')->all(), now(), 2, 10, null);

    expect($rollout->status)->toBe(StrategyRollout::STATUS_ACTIVE)
        ->and($rollout->targets()->whereNotNull('released_at')->count())->toBe(2)
        ->and($rollout->fresh()->next_release_at?->format('Y-m-d H:i:s'))->toBe('2026-08-24 12:40:00');
    Carbon::setTestNow();
});

it('gates each batch and completion on confirmation or persisted timeout', function () {
    Carbon::setTestNow('2026-08-24 12:45:00');
    $strategy = Strategy::create(['name' => 'Confirmation-gated policy', 'enabled' => true, 'confirmation_timeout_minutes' => 15]);
    $revision = StrategyRevision::capture($strategy, null, 'Candidate');
    $devices = collect(range(1, 2))->map(fn (int $id) => Device::create(['rustdesk_id' => "gated-{$id}", 'uuid' => "gated-{$id}"]));
    $rollout = StrategyRollout::schedule($strategy, $revision, $devices->pluck('id')->all(), now(), 1, 1, null);

    Carbon::setTestNow(now()->addMinute());
    expect(StrategyRollout::advanceDue())->toBe(0)
        ->and($rollout->targets()->whereNotNull('released_at')->count())->toBe(1)
        ->and($rollout->targets()->whereNotNull('timed_out_at')->count())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(14));
    expect(StrategyRollout::advanceDue())->toBe(1)
        ->and($rollout->targets()->whereNotNull('released_at')->count())->toBe(2)
        ->and($rollout->targets()->whereNotNull('timed_out_at')->count())->toBe(1)
        ->and($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_ACTIVE);

    Carbon::setTestNow(now()->addMinutes(15));
    expect(StrategyRollout::advanceDue())->toBe(1)
        ->and($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_COMPLETED)
        ->and($rollout->targets()->whereNotNull('timed_out_at')->count())->toBe(2);
    Carbon::setTestNow();
});

it('allows cancellation only before the first batch is released', function () {
    Carbon::setTestNow('2026-08-24 13:00:00');
    $strategy = Strategy::create(['name' => 'Controlled rollout', 'enabled' => true]);
    $revision = StrategyRevision::capture($strategy, null, 'Candidate');
    $devices = collect(range(1, 2))->map(fn (int $id) => Device::create(['rustdesk_id' => "control-{$id}", 'uuid' => "control-{$id}"]));
    $scheduled = StrategyRollout::schedule($strategy, $revision, $devices->pluck('id')->all(), now()->addHour(), 1, 10, null);
    expect($scheduled->cancel())->toBeTrue()
        ->and($scheduled->fresh()->status)->toBe(StrategyRollout::STATUS_CANCELLED);

    $rollout = StrategyRollout::schedule($strategy, $revision, $devices->pluck('id')->all(), now(), 1, 10, null);
    expect(fn () => $rollout->cancel())->toThrow(ValidationException::class)
        ->and($rollout->pause(null))->toBeTrue()
        ->and($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_PAUSED)
        ->and(fn () => $rollout->cancel())->toThrow(ValidationException::class);
    Carbon::setTestNow();
});

it('resumes a paused rollout by releasing exactly the next batch', function () {
    Carbon::setTestNow('2026-08-24 13:15:00');
    $strategy = Strategy::create(['name' => 'Resumable rollout', 'enabled' => true]);
    $revision = StrategyRevision::capture($strategy, null, 'Candidate');
    $devices = collect(range(1, 3))->map(fn (int $id) => Device::create(['rustdesk_id' => "resume-{$id}", 'uuid' => "resume-{$id}"]));
    $rollout = StrategyRollout::schedule($strategy, $revision, $devices->pluck('id')->all(), now(), 1, 10, null);
    expect($rollout->pause(null))->toBeTrue();

    Carbon::setTestNow(now()->addHour());

    expect(StrategyRollout::advanceDue())->toBe(0)
        ->and($rollout->targets()->whereNotNull('released_at')->count())->toBe(1)
        ->and($rollout->resume())->toBeTrue()
        ->and($rollout->targets()->whereNotNull('released_at')->count())->toBe(2)
        ->and($rollout->targets()->whereNotNull('timed_out_at')->count())->toBe(1);
    Carbon::setTestNow();
});

it('freezes active membership and every precedence writer while a rollout is open', function () {
    $strategy = Strategy::create(['name' => 'Frozen fleet policy', 'enabled' => true]);
    $revision = StrategyRevision::capture($strategy, null, 'Candidate');
    $target = Device::create(['rustdesk_id' => 'frozen-target', 'uuid' => 'frozen-target', 'status' => Device::STATUS_ACTIVE]);
    StrategyRollout::schedule($strategy, $revision, [$target->id], now()->addHour(), 1, 15, null);

    $pending = Device::create(['rustdesk_id' => 'pending-approval', 'uuid' => 'pending-approval', 'status' => Device::STATUS_PENDING]);
    expect(fn () => Device::updateWithStrategyContext($pending, ['status' => Device::STATUS_ACTIVE]))
        ->toThrow(ValidationException::class)
        ->and(fn () => Device::deleteWithStrategyContext($target))->toThrow(ValidationException::class);

    $unrelated = Strategy::create(['name' => 'Unrelated precedence policy', 'enabled' => true]);
    $user = User::factory()->create();
    expect(fn () => Strategy::assignTo(Strategy::LEVEL_USER, $user->id, $unrelated->id))
        ->toThrow(ValidationException::class);
});

it('rejects staged changes to strategy identity and resolution metadata', function () {
    $strategy = Strategy::create(['name' => 'Stable identity', 'enabled' => true, 'confirmation_timeout_minutes' => 15]);
    $revision = StrategyRevision::captureSnapshot(
        $strategy,
        [...$strategy->snapshot(), 'name' => 'Renamed candidate'],
        null,
        'Invalid candidate',
    );
    $device = Device::create(['rustdesk_id' => 'identity-1', 'uuid' => 'identity-1']);

    expect(fn () => StrategyRollout::schedule($strategy, $revision, [$device->id], now()->addMinute(), 1, 15, null))
        ->toThrow(ValidationException::class);
});

it('advances due rollouts through the scheduled command', function () {
    Carbon::setTestNow('2026-08-24 13:30:00');
    $strategy = Strategy::create(['name' => 'Command policy', 'enabled' => true, 'options' => ['enable-audio' => 'N']]);
    $revision = StrategyRevision::captureSnapshot($strategy, [...$strategy->snapshot(), 'options' => ['enable-audio' => 'Y']], null, 'Candidate');
    $device = Device::create(['rustdesk_id' => 'command-1', 'uuid' => 'command-1']);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    $rollout = StrategyRollout::schedule($strategy, $revision, [$device->id], now()->addMinute(), 1, 15, null);

    Carbon::setTestNow(now()->addMinute());

    expect(Artisan::call('cortendesk:advance-strategy-rollouts'))->toBe(0)
        ->and($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_ACTIVE)
        ->and($rollout->targets()->whereNotNull('released_at')->count())->toBe(1);

    $message = Strategy::deliveryFor($device->fresh(), 0);
    Strategy::deliveryFor($device->fresh(), $message['modified_at']);
    Carbon::setTestNow(now()->addMinutes(15));
    Artisan::call('cortendesk:advance-strategy-rollouts');
    expect($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_COMPLETED);
    Carbon::setTestNow();
});

it('delivers a released frozen policy and confirms its echoed token', function () {
    Carbon::setTestNow('2026-08-24 14:00:00');
    $strategy = Strategy::create(['name' => 'Delivered policy', 'enabled' => true, 'options' => ['enable-audio' => 'N']]);
    $revision = StrategyRevision::captureSnapshot($strategy, [...$strategy->snapshot(), 'options' => ['enable-audio' => 'Y']], null, 'Candidate');
    $device = Device::create(['rustdesk_id' => 'delivery-1', 'uuid' => 'delivery-1']);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    $rollout = StrategyRollout::schedule($strategy, $revision, [$device->id], now()->addMinute(), 1, 15, null);

    Carbon::setTestNow(now()->addMinute());
    StrategyRollout::advanceDue();
    $response = Strategy::deliveryFor($device->fresh(), 0);
    $token = $response['modified_at'];

    expect($response['strategy']['config_options'])->toBe(['enable-audio' => 'Y'])
        ->and($rollout->targets()->value('delivered_version'))->toBe($token)
        ->and($device->fresh()->strategy_rollout_ack_pending)->toBeTrue();

    Strategy::deliveryFor($device->fresh(), $token);

    expect($rollout->targets()->value('confirmed_at'))->not->toBeNull()
        ->and($device->fresh()->strategy_rollout_ack_pending)->toBeFalse();
    Carbon::setTestNow();
});

it('captures confirmation timeout in revision snapshots', function () {
    $strategy = Strategy::create(['name' => 'Timed policy', 'enabled' => true, 'confirmation_timeout_minutes' => 42]);

    expect(StrategyRevision::capture($strategy, null, 'Timed')->snapshot['confirmation_timeout_minutes'])->toBe(42);
});

it('fails closed when an assignment would change an open rollout target set', function () {
    $strategy = Strategy::create(['name' => 'Locked assignment', 'enabled' => true]);
    $revision = StrategyRevision::capture($strategy, null, 'Candidate');
    $device = Device::create(['rustdesk_id' => 'locked-1', 'uuid' => 'locked-1']);
    StrategyRollout::schedule($strategy, $revision, [$device->id], now()->addMinute(), 1, 15, null);

    expect(fn () => Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id))
        ->toThrow(ValidationException::class);
});

it('fails closed when an owner mutation could change an open rollout target set', function () {
    $strategy = Strategy::create(['name' => 'Locked owner', 'enabled' => true]);
    $revision = StrategyRevision::capture($strategy, null, 'Candidate');
    $device = Device::create(['rustdesk_id' => 'owner-locked-1', 'uuid' => 'owner-locked-1']);
    StrategyRollout::schedule($strategy, $revision, [$device->id], now()->addMinute(), 1, 15, null);
    $owner = User::factory()->create();

    expect(fn () => Device::updateWithStrategyContext($device, ['user_id' => $owner->id]))
        ->toThrow(ValidationException::class);
});
