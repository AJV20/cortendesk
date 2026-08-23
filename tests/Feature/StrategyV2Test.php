<?php

use App\Livewire\StrategyList;
use App\Models\ApiToken;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\StrategyRevision;
use App\Models\StrategyRollout;
use App\Models\StrategyRolloutDevice;
use App\Models\User;
use App\Services\StrategyCompliance;
use App\Services\StrategyImpact;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function v2Device(string $id, array $attributes = []): Device
{
    return Device::create(array_merge([
        'rustdesk_id' => $id,
        'uuid' => 'strategy-v2-'.$id,
        'hostname' => 'host-'.$id,
    ], $attributes));
}

function v2CliHeaders(array $permissions): array
{
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'strategy-v2-cli-'.uniqid(), $permissions);

    return ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
}

it('installs durable strategy revision and rollout storage', function () {
    expect(Schema::hasTable('strategy_revisions'))->toBeTrue()
        ->and(Schema::hasTable('strategy_rollouts'))->toBeTrue()
        ->and(Schema::hasTable('strategy_rollout_devices'))->toBeTrue()
        ->and(Schema::hasColumn('strategies', 'active_revision_id'))->toBeTrue();
});

it('captures an immutable revision when an operator saves a strategy', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'Lab policy')
        ->set('formNote', 'Initial policy')
        ->set('formEnforce', true)
        ->set('formOptions.enable-file-transfer', 'N')
        ->call('save')
        ->call('confirmSave')
        ->assertHasNoErrors();

    $strategy = Strategy::where('name', 'Lab policy')->firstOrFail();
    $revision = DB::table('strategy_revisions')->where('strategy_id', $strategy->id)->first();

    expect($revision)->not->toBeNull()
        ->and($revision->revision)->toBe(1)
        ->and($revision->created_by)->toBe($admin->id)
        ->and($strategy->active_revision_id)->toBe($revision->id)
        ->and(json_decode($revision->snapshot, true))->toMatchArray([
            'name' => 'Lab policy',
            'note' => 'Initial policy',
            'enabled' => true,
            'is_default' => false,
            'enforce' => true,
            'options' => ['enable-file-transfer' => 'N'],
        ]);
});

it('restores an old snapshot as a new immutable revision', function () {
    $admin = User::factory()->admin()->create();

    $component = Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'Rollback policy')
        ->set('formOptions.enable-file-transfer', 'N')
        ->call('save')
        ->call('confirmSave');

    $strategy = Strategy::where('name', 'Rollback policy')->firstOrFail();
    $first = StrategyRevision::where('strategy_id', $strategy->id)->where('revision', 1)->firstOrFail();

    $component
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-file-transfer', 'Y')
        ->call('save')
        ->call('confirmSave')
        ->assertHasNoErrors();

    expect($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'Y']);

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('restoreRevision', $first->id)
        ->assertHasNoErrors();

    $strategy->refresh();
    $revisions = StrategyRevision::where('strategy_id', $strategy->id)->orderBy('revision')->get();

    expect($strategy->optionMap())->toBe(['enable-file-transfer' => 'N'])
        ->and($revisions->pluck('revision')->all())->toBe([1, 2, 3])
        ->and($revisions[0]->snapshot['options'])->toBe(['enable-file-transfer' => 'N'])
        ->and($revisions[1]->snapshot['options'])->toBe(['enable-file-transfer' => 'Y'])
        ->and($revisions[2]->snapshot['options'])->toBe(['enable-file-transfer' => 'N'])
        ->and($strategy->active_revision_id)->toBe($revisions[2]->id)
        ->and(ConsoleAudit::where('action', 'strategy.rollback')->where('target_id', 'Rollback policy')->exists())->toBeTrue();
});

it('rejects updates and direct deletes of immutable strategy revisions', function () {
    $strategy = Strategy::create(['name' => 'Immutable policy', 'enabled' => true]);
    $author = User::factory()->admin()->create();
    $revision = StrategyRevision::capture($strategy, $author->id, 'Initial');
    $authorName = $author->username;
    $author->delete();

    expect($revision->fresh()->created_by)->toBeNull()
        ->and($revision->fresh()->created_by_name)->toBe($authorName);

    expect(fn () => $revision->update(['change_note' => 'Tampered']))
        ->toThrow(LogicException::class)
        ->and(fn () => $revision->delete())
        ->toThrow(LogicException::class);
});

it('soft deletes a strategy while retaining its immutable revision evidence', function () {
    $admin = User::factory()->admin()->create();
    $component = Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'Disposable revision owner')
        ->call('save')
        ->call('confirmSave');
    $strategy = Strategy::where('name', 'Disposable revision owner')->firstOrFail();
    $revisionId = $strategy->active_revision_id;

    $component->call('deleteStrategy', $strategy->id)->assertHasNoErrors();

    expect(Strategy::find($strategy->id))->toBeNull()
        ->and(Strategy::withTrashed()->find($strategy->id)?->trashed())->toBeTrue()
        ->and(StrategyRevision::find($revisionId))->not->toBeNull();
});

it('records the strategy displaced when a rollback restores default status', function () {
    $admin = User::factory()->admin()->create();
    $first = Strategy::create(['name' => 'Historical default', 'enabled' => true, 'is_default' => true]);
    $firstRevision = StrategyRevision::capture($first, $admin->id, 'Was default');
    $first->forceFill(['active_revision_id' => $firstRevision->id])->saveQuietly();
    $second = Strategy::create(['name' => 'Current default', 'enabled' => true, 'is_default' => true]);
    $secondRevision = StrategyRevision::capture($second, $admin->id, 'Current');
    $second->forceFill(['active_revision_id' => $secondRevision->id])->saveQuietly();

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('restoreRevision', $firstRevision->id)
        ->assertHasNoErrors();

    $displacedRevision = StrategyRevision::where('strategy_id', $second->id)->latest('revision')->firstOrFail();
    expect($first->fresh()->is_default)->toBeTrue()
        ->and($second->fresh()->is_default)->toBeFalse()
        ->and($displacedRevision->snapshot['is_default'])->toBeFalse()
        ->and($second->fresh()->active_revision_id)->toBe($displacedRevision->id);
});

it('previews affected devices and dangerous option changes before saving', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create([
        'name' => 'Preview policy',
        'enabled' => true,
        'options' => ['enable-file-transfer' => 'N'],
    ]);
    $first = v2Device('981000001');
    $second = v2Device('981000002');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $first->id, $strategy->id);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $second->id, $strategy->id);

    $component = Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-file-transfer', 'Y')
        ->set('formOptions.enable-terminal', 'N')
        ->call('previewSave')
        ->assertSet('previewing', true)
        ->assertSet('impactPreview.affected_count', 2)
        ->assertSet('impactPreview.affected_devices.0.winning_level', Strategy::LEVEL_DEVICE)
        ->assertSet('impactPreview.option_changes.enable-file-transfer.before', 'N')
        ->assertSet('impactPreview.option_changes.enable-file-transfer.after', 'Y')
        ->assertSet('impactPreview.dangerous.0.key', 'enable-terminal');

    expect($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'N']);

    $component->set('revisionNote', 'Enable transfer, disable terminal')
        ->call('confirmSave')->assertHasNoErrors()->assertSet('previewing', false);

    expect($strategy->fresh()->optionMap())->toBe([
        'enable-file-transfer' => 'Y',
        'enable-terminal' => 'N',
    ])->and(StrategyRevision::where('strategy_id', $strategy->id)->latest('revision')->value('change_note'))
        ->toBe('Enable transfer, disable terminal')
        ->and(StrategyRevision::where('strategy_id', $strategy->id)->latest('revision')->value('affected_devices'))
        ->toBe(2);
});

it('previews the exact fleet impact of creating a default strategy', function () {
    $admin = User::factory()->admin()->create();
    v2Device('981000006');
    v2Device('981000007');

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'New default')
        ->set('formEnabled', true)
        ->set('formIsDefault', true)
        ->set('formOptions.enable-audio', 'N')
        ->call('previewSave')
        ->assertSet('impactPreview.affected_count', 2);

    expect(Strategy::where('name', 'New default')->exists())->toBeFalse();
});

it('keeps impact preview queries and display rows bounded for a larger fleet', function () {
    foreach (range(1, 120) as $number) {
        v2Device((string) (983000000 + $number));
    }
    $proposed = [
        'name' => 'Fleet default',
        'note' => null,
        'enabled' => true,
        'is_default' => true,
        'enforce' => false,
        'confirmation_timeout_minutes' => 15,
        'options' => ['enable-audio' => 'N'],
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();
    $preview = StrategyImpact::preview(null, $proposed);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($preview['affected_count'])->toBe(120)
        ->and(count($preview['affected_devices']))->toBe(50)
        ->and($queryCount)->toBeLessThanOrEqual(8);
});

it('does not allow the legacy save action to bypass impact review', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'Must review')
        ->call('save')
        ->assertSet('previewing', true);

    expect(Strategy::where('name', 'Must review')->exists())->toBeFalse();
});

it('revalidates an immediate save against the locked assignment source', function () {
    $admin = User::factory()->admin()->create();
    $reviewed = Strategy::create([
        'name' => 'Reviewed source',
        'enabled' => true,
        'options' => ['enable-audio' => 'N'],
    ]);
    $replacement = Strategy::create(['name' => 'Replacement source', 'enabled' => true]);
    $device = v2Device('981000015');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $reviewed->id);

    $component = Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $reviewed->id)
        ->set('formOptions.enable-audio', 'Y')
        ->call('previewSave')
        ->assertSet('previewing', true);

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $replacement->id);

    $component->call('confirmSave')->assertHasErrors('preview');

    expect($reviewed->fresh()->optionMap())->toBe(['enable-audio' => 'N'])
        ->and(StrategyRevision::where('strategy_id', $reviewed->id)->count())->toBe(0);
});

it('records zero affected devices for metadata-only revisions', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create(['name' => 'Metadata policy', 'note' => 'Before', 'enabled' => true]);
    $device = v2Device('981000014');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formNote', 'After')
        ->call('previewSave')
        ->assertSet('impactPreview.affected_count', 0)
        ->call('confirmSave')
        ->assertHasNoErrors();

    expect(StrategyRevision::where('strategy_id', $strategy->id)->latest('revision')->value('affected_devices'))
        ->toBe(0);
});

it('validates the revision note again at the confirmation boundary', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'Note validation')
        ->call('previewSave')
        ->set('revisionNote', str_repeat('x', 501))
        ->call('confirmSave')
        ->assertHasErrors('revisionNote');

    expect(Strategy::where('name', 'Note validation')->exists())->toBeFalse();
});

it('classifies strategy compliance as confirmed pending stale offline or overridden', function () {
    $strategy = Strategy::create([
        'name' => 'Compliance policy',
        'enabled' => true,
        'confirmation_timeout_minutes' => 15,
        'options' => ['enable-file-transfer' => 'N'],
    ]);
    $other = Strategy::create(['name' => 'Override', 'enabled' => true]);

    $confirmed = v2Device('981000010', ['last_online_at' => now()]);
    $confirmed->forceFill([
        'strategy_options' => ['enable-file-transfer' => 'N'],
        'strategy_acked_options' => ['enable-file-transfer' => 'N'],
        'strategy_sent_at' => now()->subMinutes(2),
        'strategy_acked_at' => now()->subMinute(),
    ])->saveQuietly();
    $pending = v2Device('981000011', ['last_online_at' => now()]);
    $pending->forceFill([
        'strategy_options' => ['enable-file-transfer' => 'N'],
        'strategy_sent_at' => now()->subMinutes(5),
    ])->saveQuietly();
    $stale = v2Device('981000012', ['last_online_at' => now()]);
    $stale->forceFill([
        'strategy_options' => ['enable-file-transfer' => 'N'],
        'strategy_sent_at' => now()->subMinutes(30),
    ])->saveQuietly();
    $offline = v2Device('981000013', ['last_online_at' => now()->subHour()]);

    foreach ([$confirmed, $pending, $stale, $offline] as $device) {
        Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    }

    $group = DeviceGroup::create(['name' => 'Overridden group']);
    $overridden = v2Device('981000014', ['device_group_id' => $group->id]);
    Strategy::assignTo(Strategy::LEVEL_DEVICE_GROUP, $group->id, $strategy->id);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $overridden->id, $other->id);

    $summary = app(StrategyCompliance::class)->summary($strategy->fresh());

    expect($summary['counts'])->toBe([
        'confirmed' => 1,
        'pending' => 1,
        'stale' => 1,
        'offline' => 1,
        'overridden' => 1,
    ])->and($summary['devices']['stale'][0]['rustdesk_id'])->toBe('981000012');
});

it('releases a staged rollout in batches and can pause resume and complete it', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create([
        'name' => 'Staged policy',
        'enabled' => true,
        'options' => ['enable-file-transfer' => 'N'],
    ]);
    $active = StrategyRevision::capture($strategy, $admin->id, 'Current');
    $strategy->forceFill(['active_revision_id' => $active->id])->saveQuietly();

    $devices = collect([
        v2Device('981000020'),
        v2Device('981000021'),
        v2Device('981000022'),
    ]);
    $devices->each(fn (Device $device) => Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id));

    $candidate = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'options' => ['enable-file-transfer' => 'Y'],
    ], $admin->id, 'Candidate');

    $rollout = StrategyRollout::schedule(
        $strategy,
        $candidate,
        $devices->pluck('id')->all(),
        now(),
        1,
        10,
        $admin->id,
    );

    $releasedIds = $rollout->devices()->whereNotNull('strategy_rollout_devices.released_at')->pluck('devices.id')->all();

    expect($candidate->snapshot['options'])->toBe(['enable-file-transfer' => 'Y'])
        ->and($releasedIds)->toBe([$devices[0]->id])
        ->and($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_ACTIVE)
        ->and($rollout->devices()->whereNotNull('strategy_rollout_devices.released_at')->count())->toBe(1)
        ->and($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'N'])
        ->and($strategy->desiredOptionsFor($devices[0]->fresh()))->toBe(['enable-file-transfer' => 'Y'])
        ->and($strategy->desiredOptionsFor($devices[1]->fresh()))->toBe(['enable-file-transfer' => 'N']);

    $rollout->pause($admin->id);
    $rollout->releaseNextBatch();
    expect($rollout->devices()->whereNotNull('released_at')->count())->toBe(1);

    $rollout->resume();
    expect($rollout->devices()->whereNotNull('released_at')->count())->toBe(2);

    $rollout->releaseNextBatch();
    expect($rollout->devices()->whereNotNull('released_at')->count())->toBe(2)
        ->and($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_ACTIVE);

    $this->travel(10)->minutes();
    $rollout->releaseNextBatch();

    expect($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_COMPLETED)
        ->and($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'Y'])
        ->and($strategy->fresh()->active_revision_id)->toBe($candidate->id);
});

it('keeps a scheduled rollout dormant until the scheduler reaches its start time', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create([
        'name' => 'Scheduled policy',
        'enabled' => true,
        'options' => ['enable-audio' => 'N'],
    ]);
    $candidate = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'options' => ['enable-audio' => 'Y'],
    ], $admin->id, 'Scheduled candidate');
    $device = v2Device('981000030');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    $startsAt = now()->addHour();
    $rollout = StrategyRollout::schedule(
        $strategy, $candidate, [$device->id], $startsAt, 1, 5, $admin->id,
    );

    expect($rollout->status)->toBe(StrategyRollout::STATUS_SCHEDULED)
        ->and($rollout->devices()->whereNotNull('strategy_rollout_devices.released_at')->count())->toBe(0)
        ->and($strategy->desiredOptionsFor($device->fresh()))->toBe(['enable-audio' => 'N']);

    $this->travelTo($startsAt->copy()->addMinute());
    $this->artisan('cortendesk:advance-strategy-rollouts')->assertSuccessful();

    expect($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_COMPLETED)
        ->and($strategy->fresh()->optionMap())->toBe(['enable-audio' => 'Y']);
});

it('schedules a staged rollout from the reviewed impact preview', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create([
        'name' => 'Editor rollout',
        'enabled' => true,
        'options' => ['enable-audio' => 'N'],
    ]);
    $active = StrategyRevision::capture($strategy, $admin->id, 'Current');
    $strategy->forceFill(['active_revision_id' => $active->id])->saveQuietly();
    foreach ([v2Device('981000040'), v2Device('981000041')] as $device) {
        Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    }

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-audio', 'Y')
        ->call('previewSave')
        ->set('rolloutBatchSize', 1)
        ->set('rolloutIntervalMinutes', 30)
        ->set('revisionNote', 'Pilot audio change')
        ->call('scheduleRollout')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    $rollout = StrategyRollout::query()->where('strategy_id', $strategy->id)->latest('id')->firstOrFail();
    expect($rollout->status)->toBe(StrategyRollout::STATUS_ACTIVE)
        ->and($rollout->batch_size)->toBe(1)
        ->and($rollout->devices()->count())->toBe(2)
        ->and($rollout->devices()->whereNotNull('strategy_rollout_devices.released_at')->count())->toBe(1)
        ->and($rollout->revision->change_note)->toBe('Pilot audio change')
        ->and($strategy->fresh()->optionMap())->toBe(['enable-audio' => 'N'])
        ->and(ConsoleAudit::where('action', 'strategy.rollout.schedule')->exists())->toBeTrue();
});

it('marks a released rollout target confirmed when the device acknowledges it', function () {
    $strategy = Strategy::create([
        'name' => 'Ack rollout',
        'enabled' => true,
        'options' => ['enable-audio' => 'N'],
    ]);
    $revision = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'options' => ['enable-audio' => 'Y'],
    ], null, 'Candidate');
    $first = v2Device('981000050');
    $second = v2Device('981000051');
    foreach ([$first, $second] as $device) {
        Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    }
    $rollout = StrategyRollout::schedule(
        $strategy, $revision, [$first->id, $second->id], now(), 1, 30, null,
    );

    $message = Strategy::deliveryFor($first->fresh(), 0);
    expect($message)->not->toBeNull();
    Strategy::deliveryFor($first->fresh(), $message['modified_at']);

    expect(DB::table('strategy_rollout_devices')
        ->where('strategy_rollout_id', $rollout->id)
        ->where('device_id', $first->id)
        ->whereNotNull('confirmed_at')
        ->exists())->toBeTrue();
});

it('records acknowledgements that arrive after the final rollout batch completes', function () {
    $strategy = Strategy::create([
        'name' => 'Completed acknowledgement',
        'enabled' => true,
        'options' => ['enable-audio' => 'N'],
    ]);
    $device = v2Device('981000052');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    $candidate = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'options' => ['enable-audio' => 'Y'],
    ], null, 'Candidate');
    $rollout = StrategyRollout::schedule($strategy, $candidate, [$device->id], now(), 1, 15, null);
    expect($rollout->status)->toBe(StrategyRollout::STATUS_COMPLETED);

    $message = Strategy::deliveryFor($device->fresh(), 0);
    Strategy::deliveryFor($device->fresh(), $message['modified_at']);

    expect(DB::table('strategy_rollout_devices')
        ->where('strategy_rollout_id', $rollout->id)
        ->where('device_id', $device->id)
        ->whereNotNull('confirmed_at')
        ->exists())->toBeTrue();
});

it('attributes a delayed acknowledgement to its exact historical rollout target', function () {
    $strategy = Strategy::create([
        'name' => 'Token history',
        'enabled' => true,
        'options' => ['enable-audio' => 'N'],
    ]);
    $device = v2Device('981000054');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    $firstRevision = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'options' => ['enable-audio' => 'Y'],
    ], null, 'First candidate');
    $firstRollout = StrategyRollout::schedule($strategy, $firstRevision, [$device->id], now(), 1, 15, null);
    $firstMessage = Strategy::deliveryFor($device->fresh(), 0);

    $secondRevision = StrategyRevision::captureSnapshot($strategy->fresh(), [
        ...$strategy->fresh()->snapshot(),
        'options' => ['enable-camera' => 'Y'],
    ], null, 'Second candidate');
    $secondRollout = StrategyRollout::schedule($strategy->fresh(), $secondRevision, [$device->id], now(), 1, 15, null);

    $secondMessage = Strategy::deliveryFor($device->fresh(), $firstMessage['modified_at']);

    expect(DB::table('strategy_rollout_devices')->where('strategy_rollout_id', $firstRollout->id)->value('confirmed_at'))->not->toBeNull()
        ->and(DB::table('strategy_rollout_devices')->where('strategy_rollout_id', $secondRollout->id)->value('confirmed_at'))->toBeNull();

    Strategy::deliveryFor($device->fresh(), $secondMessage['modified_at']);
    expect(DB::table('strategy_rollout_devices')->where('strategy_rollout_id', $secondRollout->id)->value('confirmed_at'))->not->toBeNull();
});

it('retains rollout target evidence when a device is permanently deleted', function () {
    $strategy = Strategy::create(['name' => 'Target evidence', 'enabled' => true]);
    $device = v2Device('981000053');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    $revision = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'options' => ['enable-audio' => 'Y'],
    ], null, 'Evidence candidate');
    $rollout = StrategyRollout::schedule($strategy, $revision, [$device->id], now(), 1, 15, null);

    $device->forceDelete();

    $target = DB::table('strategy_rollout_devices')->where('strategy_rollout_id', $rollout->id)->first();
    expect($target)->not->toBeNull()
        ->and($target->device_id)->toBeNull()
        ->and($target->device_rustdesk_id)->toBe('981000053');

    expect(fn () => $rollout->delete())->toThrow(LogicException::class)
        ->and(fn () => StrategyRolloutDevice::findOrFail($target->id)->delete())->toThrow(LogicException::class);
});

it('cancels a rollout by returning released devices to the active revision', function () {
    $strategy = Strategy::create([
        'name' => 'Cancelable policy',
        'enabled' => true,
        'options' => ['enable-audio' => 'N'],
    ]);
    $first = v2Device('981000045');
    $second = v2Device('981000046');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $first->id, $strategy->id);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $second->id, $strategy->id);
    $candidate = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'options' => ['enable-audio' => 'Y'],
    ], null, 'Candidate');
    $rollout = StrategyRollout::schedule($strategy, $candidate, [$first->id, $second->id], now(), 1, 15, null);

    expect($strategy->desiredOptionsFor($first))->toBe(['enable-audio' => 'Y'])
        ->and(fn () => $rollout->cancel())->toThrow(ValidationException::class);
    $rollout->pause(null);
    $rollout->cancel();

    expect($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_CANCELLED)
        ->and($strategy->fresh()->desiredOptionsFor($first))->toBe(['enable-audio' => 'N'])
        ->and($strategy->fresh()->optionMap())->toBe(['enable-audio' => 'N']);
});

it('rejects staged revisions that would change resolution identity', function () {
    $strategy = Strategy::create(['name' => 'Identity policy', 'enabled' => true]);
    $revision = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'enabled' => false,
    ], null, 'Unsafe candidate');

    expect(fn () => StrategyRollout::schedule($strategy, $revision, [], now(), 1, 15, null))
        ->toThrow(ValidationException::class);
});

it('rejects staged revisions that would change confirmation timing mid-rollout', function () {
    $strategy = Strategy::create([
        'name' => 'Timing policy',
        'enabled' => true,
        'confirmation_timeout_minutes' => 15,
    ]);
    $revision = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'confirmation_timeout_minutes' => 60,
    ], null, 'Unsafe timeout candidate');

    expect(fn () => StrategyRollout::schedule($strategy, $revision, [], now(), 1, 15, null))
        ->toThrow(ValidationException::class);
});

it('blocks an immediate edit while a staged rollout is open', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create([
        'name' => 'Busy rollout',
        'enabled' => true,
        'options' => ['enable-audio' => 'N'],
    ]);
    $revision = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'options' => ['enable-audio' => 'Y'],
    ], $admin->id, 'Candidate');
    $first = v2Device('981000060');
    $second = v2Device('981000061');
    foreach ([$first, $second] as $device) {
        Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    }
    StrategyRollout::schedule($strategy, $revision, [$first->id, $second->id], now(), 1, 30, $admin->id);

    expect(fn () => Strategy::assignTo(Strategy::LEVEL_DEVICE, $first->id, null))
        ->toThrow(ValidationException::class);
    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('openAssign', $strategy->id)
        ->assertHasErrors('assignment');

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-camera', 'N')
        ->call('previewSave')
        ->call('confirmSave')
        ->assertHasErrors('rollout');

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('restoreRevision', $revision->id)
        ->assertHasErrors('history');

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('deleteStrategy', $strategy->id)
        ->assertHasErrors('rollout');

    expect($strategy->fresh()->optionMap())->toBe(['enable-audio' => 'N'])
        ->and(StrategyRevision::where('strategy_id', $strategy->id)->count())->toBe(1)
        ->and(ConsoleAudit::where('action', 'strategy.rollback')->exists())->toBeFalse();
});

it('lets strategy readers inspect history and compliance but blocks every v2 mutator', function () {
    $role = Role::create([
        'name' => 'Strategy reader',
        'permissions' => ['strategy' => 'r'],
    ]);
    $reader = User::factory()->create(['is_admin' => false, 'role_id' => $role->id]);
    $strategy = Strategy::create(['name' => 'Read only policy', 'enabled' => true]);
    $revision = StrategyRevision::capture($strategy, null, 'Baseline');
    $device = v2Device('981000070');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    $rollout = StrategyRollout::schedule(
        $strategy, $revision, [$device->id], now()->addHour(), 1, 15, null,
    );

    Livewire::actingAs($reader)
        ->test(StrategyList::class)
        ->call('showHistory', $strategy->id)
        ->assertSet('historyStrategyId', $strategy->id)
        ->call('showCompliance', $strategy->id, 'all')
        ->assertSet('complianceStrategyId', $strategy->id);

    Livewire::actingAs($reader)
        ->test(StrategyList::class)
        ->set('assigningId', $strategy->id)
        ->assertDontSee($device->rustdesk_id);

    foreach ([
        ['edit', [$strategy->id]],
        ['restoreRevision', [$revision->id]],
        ['pauseRollout', [$rollout->id]],
        ['resumeRollout', [$rollout->id]],
        ['cancelRollout', [$rollout->id]],
        ['scheduleRollout', []],
    ] as [$method, $arguments]) {
        Livewire::actingAs($reader)
            ->test(StrategyList::class)
            ->call($method, ...$arguments)
            ->assertForbidden();
    }
});

it('builds fleet-wide compliance summaries with a bounded query count', function () {
    $first = Strategy::create(['name' => 'Bounded first', 'enabled' => true]);
    $second = Strategy::create(['name' => 'Bounded second', 'enabled' => true]);

    foreach (range(1, 30) as $index) {
        $device = v2Device((string) (982000000 + $index), ['last_online_at' => now()->subHour()]);
        Strategy::assignTo(
            Strategy::LEVEL_DEVICE,
            $device->id,
            $index % 2 === 0 ? $first->id : $second->id,
        );
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $summaries = app(StrategyCompliance::class)->summaries(collect([$first, $second]));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($summaries[$first->id]['counts']['offline'])->toBe(15)
        ->and($summaries[$second->id]['counts']['offline'])->toBe(15)
        ->and($queryCount)->toBeLessThanOrEqual(6);
});

it('keeps compliance drill-down payloads bounded while preserving exact counts', function () {
    $strategy = Strategy::create(['name' => 'Large compliance set', 'enabled' => true]);
    $ids = [];
    foreach (range(1, 205) as $number) {
        $ids[] = v2Device((string) (982000000 + $number))->id;
    }
    DB::table('devices')->whereIn('id', $ids)->update(['strategy_id_resolved' => $strategy->id]);

    $summary = app(StrategyCompliance::class)->summary($strategy);

    expect($summary['counts']['offline'])->toBe(205)
        ->and(count($summary['devices']['offline']))->toBe(StrategyCompliance::DETAIL_LIMIT);
});

it('previews assignment resolution changes before applying them', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create(['name' => 'Assignment candidate', 'enabled' => true]);
    $fallback = Strategy::create(['name' => 'Assignment fallback', 'enabled' => true, 'is_default' => true]);
    $owner = User::factory()->create();
    $first = v2Device('983000001', ['user_id' => $owner->id]);
    $second = v2Device('983000002', ['user_id' => $owner->id]);

    expect($first->fresh()->strategy_id_resolved)->toBe($fallback->id)
        ->and($second->fresh()->strategy_id_resolved)->toBe($fallback->id);

    $component = Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('openAssign', $strategy->id)
        ->set('assignUserIds', [$owner->id])
        ->call('saveAssign')
        ->assertSet('assignPreviewing', true)
        ->assertSet('assignmentImpact.affected_count', 2)
        ->assertSet('assignmentImpact.affected_devices.0.winning_level', Strategy::LEVEL_USER);

    expect($first->fresh()->strategy_id_resolved)->toBe($fallback->id);

    $component->call('confirmAssign')->assertHasNoErrors()->assertSet('assigningId', null);

    expect($first->fresh()->strategy_id_resolved)->toBe($strategy->id)
        ->and($second->fresh()->strategy_id_resolved)->toBe($strategy->id);
});

it('compares any two immutable revisions in the history view', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create([
        'name' => 'Compared policy',
        'enabled' => true,
        'options' => ['enable-audio' => 'N', 'enable-camera' => 'Y'],
    ]);
    $first = StrategyRevision::capture($strategy, $admin->id, 'Before');
    $second = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'options' => ['enable-audio' => 'Y'],
        'enforce' => true,
    ], $admin->id, 'After');

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('showHistory', $strategy->id)
        ->set('compareFromRevisionId', $first->id)
        ->set('compareToRevisionId', $second->id)
        ->assertSee('Revision 1')
        ->assertSee('Revision 2')
        ->assertSet('revisionComparison.0.key', 'enforce')
        ->assertSet('revisionComparison.1.key', 'options.enable-audio')
        ->assertSet('revisionComparison.2.key', 'options.enable-camera');
});

it('backfills an active baseline revision for strategies that predate v2', function () {
    $migration = require database_path('migrations/2026_08_22_000010_create_strategy_revision_and_rollout_tables.php');
    $migration->down();

    $strategyId = DB::table('strategies')->insertGetId([
        'name' => 'Legacy policy',
        'note' => null,
        'enabled' => true,
        'is_default' => false,
        'enforce' => true,
        'options' => json_encode(['enable-audio' => 'N'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    $strategy = Strategy::findOrFail($strategyId);
    $revision = StrategyRevision::findOrFail($strategy->active_revision_id);
    expect($revision->revision)->toBe(1)
        ->and($revision->change_note)->toBe('Baseline created during Strategies V2 upgrade')
        ->and($revision->snapshot['options'])->toBe(['enable-audio' => 'N'])
        ->and($revision->snapshot['enforce'])->toBeTrue();
});

it('fails baseline backfill before writing evidence when legacy options json is malformed', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        expect(fn () => DB::table('strategies')->insert([
            'name' => 'Malformed current policy',
            'enabled' => true,
            'is_default' => false,
            'enforce' => false,
            'options' => '{not-json',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

        return;
    }

    $migration = require database_path('migrations/2026_08_22_000010_create_strategy_revision_and_rollout_tables.php');
    $migration->down();

    $strategyId = DB::table('strategies')->insertGetId([
        'name' => 'Malformed legacy policy',
        'note' => null,
        'enabled' => true,
        'is_default' => false,
        'enforce' => false,
        'options' => '{not-json',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        $migration->up();
        $this->fail('The migration accepted malformed legacy strategy options.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain("strategy {$strategyId}")
            ->and(DB::table('strategy_revisions')->count())->toBe(0)
            ->and(DB::table('strategies')->where('id', $strategyId)->value('active_revision_id'))->toBeNull();
    }
});

it('rejects falsey legacy option values before writing baseline evidence', function (string $legacyOptions) {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite permits legacy falsey text in JSON-declared columns.');
    }

    $migration = require database_path('migrations/2026_08_22_000010_create_strategy_revision_and_rollout_tables.php');
    $migration->down();
    $strategyId = DB::table('strategies')->insertGetId([
        'name' => 'Falsey legacy policy '.bin2hex(random_bytes(3)),
        'note' => null,
        'enabled' => true,
        'is_default' => false,
        'enforce' => false,
        'options' => $legacyOptions,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        $migration->up();
        $this->fail('The migration accepted a falsey non-object legacy strategy value.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain("strategy {$strategyId}")
            ->and(DB::table('strategy_revisions')->count())->toBe(0)
            ->and(DB::table('strategies')->where('id', $strategyId)->value('active_revision_id'))->toBeNull();
    }
})->with([
    'zero scalar' => '0',
    'empty string' => '',
]);

it('blocks device owner and group resolution changes while a rollout is open', function () {
    $admin = User::factory()->admin()->create();
    $fallback = Strategy::create(['name' => 'Context fallback', 'enabled' => true, 'is_default' => true]);
    $ownerPolicy = Strategy::create(['name' => 'Owner context', 'enabled' => true]);
    $owner = User::factory()->create(['username' => 'context-owner']);
    Strategy::assignTo(Strategy::LEVEL_USER, $owner->id, $ownerPolicy->id);
    $device = v2Device('983000099', ['note' => 'unchanged']);
    $candidate = StrategyRevision::captureSnapshot($fallback, [
        ...$fallback->snapshot(),
        'options' => ['enable-audio' => 'N'],
    ], $admin->id, 'Context lock candidate');
    StrategyRollout::schedule($fallback, $candidate, [$device->id], now()->addHour(), 1, 5, $admin->id);

    expect(fn () => Device::updateWithStrategyContext($device, [
        'user_id' => $owner->id,
        'note' => 'must roll back',
    ]))->toThrow(ValidationException::class);

    expect($device->fresh()->user_id)->toBeNull()
        ->and($device->fresh()->note)->toBe('unchanged')
        ->and($device->fresh()->strategy_id_resolved)->toBe($fallback->id);
});

it('requires strategy permission for cli owner changes that alter resolution', function () {
    $fallback = Strategy::create(['name' => 'CLI fallback', 'enabled' => true, 'is_default' => true]);
    $ownerPolicy = Strategy::create(['name' => 'CLI owner policy', 'enabled' => true]);
    $owner = User::factory()->create(['username' => 'cli-context-owner']);
    Strategy::assignTo(Strategy::LEVEL_USER, $owner->id, $ownerPolicy->id);
    $device = v2Device('983000098');

    test()->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id,
        'uuid' => $device->uuid,
        'user_name' => $owner->username,
    ], v2CliHeaders(['device' => 'rw', 'strategy' => 'none']))->assertForbidden();

    expect($device->fresh()->user_id)->toBeNull()
        ->and($device->fresh()->strategy_id_resolved)->toBe($fallback->id);

    test()->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id,
        'uuid' => $device->uuid,
        'user_name' => $owner->username,
    ], v2CliHeaders(['device' => 'rw', 'strategy' => 'rw']))->assertOk();

    expect($device->fresh()->user_id)->toBe($owner->id)
        ->and($device->fresh()->strategy_id_resolved)->toBe($ownerPolicy->id);
});

it('rolls back cli metadata when an open rollout blocks an owner change', function () {
    $admin = User::factory()->admin()->create();
    $fallback = Strategy::create(['name' => 'CLI rollout fallback', 'enabled' => true, 'is_default' => true]);
    $ownerPolicy = Strategy::create(['name' => 'CLI rollout owner', 'enabled' => true]);
    $owner = User::factory()->create(['username' => 'cli-rollout-owner']);
    Strategy::assignTo(Strategy::LEVEL_USER, $owner->id, $ownerPolicy->id);
    $device = v2Device('983000097', ['note' => 'before']);
    $candidate = StrategyRevision::captureSnapshot($fallback, [
        ...$fallback->snapshot(),
        'options' => ['enable-audio' => 'N'],
    ], $admin->id, 'CLI lock candidate');
    StrategyRollout::schedule($fallback, $candidate, [$device->id], now()->addHour(), 1, 5, $admin->id);

    test()->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id,
        'uuid' => $device->uuid,
        'user_name' => $owner->username,
        'note' => 'must roll back',
    ], v2CliHeaders(['device' => 'rw', 'strategy' => 'rw']))->assertStatus(409);

    expect($device->fresh()->user_id)->toBeNull()
        ->and($device->fresh()->note)->toBe('before')
        ->and($device->fresh()->strategy_id_resolved)->toBe($fallback->id);
});
