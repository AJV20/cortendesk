<?php

use App\Http\Controllers\Api\DeviceCliController;
use App\Livewire\StrategyList;
use App\Models\ApiToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\StrategyRevision;
use App\Models\StrategyRollout;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL concurrency coverage runs in the dedicated CI job.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * @param  array<int,Closure():array<string,mixed>>  $operations
 * @return array<int,array<string,mixed>>
 */
function runStrategyMysqlRace(array $operations): array
{
    $prefix = sys_get_temp_dir().'/cortendesk-mysql-race-'.bin2hex(random_bytes(8));
    $barrier = $prefix.'.go';
    $resultPaths = [];
    $pids = [];

    // Never inherit a live PDO socket across fork(). Each child reconnects.
    DB::disconnect();

    foreach ($operations as $index => $operation) {
        $resultPath = "{$prefix}.{$index}.json";
        $resultPaths[] = $resultPath;
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork MySQL race worker.');
        }
        if ($pid === 0) {
            try {
                DB::purge();
                DB::reconnect();
                while (! file_exists($barrier)) {
                    usleep(1000);
                }
                $result = ['ok' => true, 'value' => $operation()];
            } catch (Throwable $exception) {
                $result = [
                    'ok' => false,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }

            file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
            exit($result['ok'] ? 0 : 1);
        }

        $pids[] = $pid;
    }

    touch($barrier);
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    DB::purge();
    DB::reconnect();
    $results = array_map(
        fn (string $path): array => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
        $resultPaths,
    );

    @unlink($barrier);
    foreach ($resultPaths as $path) {
        @unlink($path);
    }

    return $results;
}

/** @return array{rollout:StrategyRollout,strategy:Strategy,other:Strategy,devices:array<int,Device>} */
function mysqlStrategyRollout(int $deviceCount = 8): array
{
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create([
        'name' => 'MySQL concurrent rollout',
        'enabled' => true,
        'is_default' => true,
        'options' => ['enable-audio' => 'N'],
    ]);
    $other = Strategy::create(['name' => 'MySQL competing assignment', 'enabled' => true]);
    $candidate = StrategyRevision::captureSnapshot($strategy, [
        ...$strategy->snapshot(),
        'options' => ['enable-audio' => 'Y'],
    ], $admin->id, 'MySQL concurrency candidate');

    $devices = [];
    for ($index = 1; $index <= $deviceCount; $index++) {
        $device = Device::create([
            'rustdesk_id' => 'mysql-race-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'uuid' => 'mysql-race-uuid-'.$index,
        ]);
        Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
        $devices[] = $device;
    }

    $rollout = StrategyRollout::schedule(
        $strategy,
        $candidate,
        array_map(fn (Device $device): int => (int) $device->id, $devices),
        now(),
        2,
        30,
        $admin->id,
    );

    return compact('rollout', 'strategy', 'other', 'devices');
}

it('releases exactly one due batch when two mysql schedulers race', function () {
    ['rollout' => $rollout] = mysqlStrategyRollout();
    DB::table('strategy_rollouts')->where('id', $rollout->id)->update(['next_release_at' => now()->subSecond()]);

    $results = runStrategyMysqlRace([
        fn (): array => ['released' => StrategyRollout::findOrFail($rollout->id)->releaseNextBatch()],
        fn (): array => ['released' => StrategyRollout::findOrFail($rollout->id)->releaseNextBatch()],
    ]);

    expect(collect($results)->every(fn (array $result): bool => $result['ok']))->toBeTrue()
        ->and(collect($results)->where('value.released', true)->count())->toBe(1)
        ->and($rollout->fresh()->targets()->whereNotNull('released_at')->count())->toBe(4)
        ->and($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_ACTIVE);
});

it('keeps a valid paused state when mysql scheduler and pause race', function () {
    ['rollout' => $rollout] = mysqlStrategyRollout();
    DB::table('strategy_rollouts')->where('id', $rollout->id)->update(['next_release_at' => now()->subSecond()]);

    $results = runStrategyMysqlRace([
        fn (): array => ['released' => StrategyRollout::findOrFail($rollout->id)->releaseNextBatch()],
        fn (): array => ['paused' => StrategyRollout::findOrFail($rollout->id)->pause(null)],
    ]);

    $fresh = $rollout->fresh();
    expect(collect($results)->every(fn (array $result): bool => $result['ok']))->toBeTrue()
        ->and($fresh->status)->toBe(StrategyRollout::STATUS_PAUSED)
        ->and($fresh->next_release_at)->toBeNull()
        ->and($fresh->targets()->whereNotNull('released_at')->count())->toBeIn([2, 4]);
});

it('blocks mysql cancel and assignment mutations racing a due scheduler', function () {
    ['rollout' => $rollout, 'other' => $other, 'devices' => $devices] = mysqlStrategyRollout();
    DB::table('strategy_rollouts')->where('id', $rollout->id)->update(['next_release_at' => now()->subSecond()]);

    $results = runStrategyMysqlRace([
        fn (): array => ['released' => StrategyRollout::findOrFail($rollout->id)->releaseNextBatch()],
        function () use ($rollout): array {
            try {
                StrategyRollout::findOrFail($rollout->id)->cancel();

                return ['blocked' => false];
            } catch (ValidationException) {
                return ['blocked' => true];
            }
        },
        function () use ($devices, $other): array {
            try {
                Strategy::assignTo(Strategy::LEVEL_DEVICE, $devices[0]->id, $other->id);

                return ['blocked' => false];
            } catch (ValidationException) {
                return ['blocked' => true];
            }
        },
    ]);

    expect(collect($results)->every(fn (array $result): bool => $result['ok']))->toBeTrue()
        ->and($results[1]['value']['blocked'])->toBeTrue()
        ->and($results[2]['value']['blocked'])->toBeTrue()
        ->and($rollout->fresh()->status)->toBe(StrategyRollout::STATUS_ACTIVE)
        ->and($rollout->fresh()->targets()->whereNotNull('released_at')->count())->toBe(4)
        ->and(Strategy::assignedStrategyId(Strategy::LEVEL_DEVICE, $devices[0]->id))->toBe($rollout->strategy_id);
});

it('serializes mysql immediate save with a default-restoring rollback', function () {
    $admin = User::factory()->admin()->create();
    $currentDefault = Strategy::create([
        'name' => 'MySQL current default',
        'enabled' => true,
        'is_default' => true,
        'note' => 'before race',
    ]);
    $currentRevision = StrategyRevision::capture($currentDefault, $admin->id, 'Current default');
    $currentDefault->forceFill(['active_revision_id' => $currentRevision->id])->saveQuietly();

    $restoredStrategy = Strategy::create([
        'name' => 'MySQL restored default',
        'enabled' => true,
        'is_default' => false,
    ]);
    $defaultRevision = StrategyRevision::captureSnapshot($restoredStrategy, [
        ...$restoredStrategy->snapshot(),
        'is_default' => true,
    ], $admin->id, 'Historical default');

    $results = runStrategyMysqlRace([
        function () use ($admin, $currentDefault): array {
            Livewire::actingAs(User::findOrFail($admin->id))
                ->test(StrategyList::class)
                ->call('edit', $currentDefault->id)
                ->set('formNote', 'saved during race')
                ->call('previewSave')
                ->call('confirmSave');

            return ['saved' => true];
        },
        function () use ($admin, $defaultRevision): array {
            Livewire::actingAs(User::findOrFail($admin->id))
                ->test(StrategyList::class)
                ->call('restoreRevision', $defaultRevision->id);

            return ['restored' => true];
        },
    ]);

    expect(collect($results)->every(fn (array $result): bool => $result['ok']))->toBeTrue()
        ->and(Strategy::query()->where('is_default', true)->count())->toBe(1)
        ->and(StrategyRevision::query()->where('strategy_id', $restoredStrategy->id)->count())->toBeGreaterThan(1);
});

it('authorizes cli context changes against the locked mysql strategy state', function () {
    $creator = User::factory()->admin()->create();
    $owner = User::factory()->create(['username' => 'mysql-cli-owner']);
    $fallback = Strategy::create(['name' => 'MySQL CLI fallback', 'enabled' => true, 'is_default' => true]);
    $ownerPolicy = Strategy::create(['name' => 'MySQL CLI owner policy', 'enabled' => true]);
    $device = Device::create([
        'rustdesk_id' => 'mysql-cli-auth-race',
        'uuid' => 'mysql-cli-auth-race-uuid',
    ]);
    [, $plain] = ApiToken::issue($creator, 'mysql-cli-auth-race', [
        'device' => 'rw',
        'strategy' => 'none',
    ]);
    $token = ApiToken::where('token_hash', hash('sha256', $plain))->firstOrFail();
    $prefix = sys_get_temp_dir().'/cortendesk-cli-auth-'.bin2hex(random_bytes(6));
    $assignmentLocked = $prefix.'.locked';
    $cliStarted = $prefix.'.started';

    $results = runStrategyMysqlRace([
        function () use ($owner, $ownerPolicy, $assignmentLocked, $cliStarted): array {
            DB::transaction(function () use ($owner, $ownerPolicy, $assignmentLocked, $cliStarted): void {
                Strategy::assignTo(Strategy::LEVEL_USER, $owner->id, $ownerPolicy->id);
                touch($assignmentLocked);
                while (! file_exists($cliStarted)) {
                    usleep(1000);
                }
            });

            return ['assigned' => true];
        },
        function () use ($device, $owner, $token, $assignmentLocked, $cliStarted): array {
            while (! file_exists($assignmentLocked)) {
                usleep(1000);
            }
            touch($cliStarted);
            $request = Request::create('/api/devices/cli', 'POST', [
                'id' => $device->rustdesk_id,
                'uuid' => $device->uuid,
                'user_name' => $owner->username,
            ]);
            $request->attributes->set('api_token', ApiToken::findOrFail($token->id));
            $response = app(DeviceCliController::class)->assign($request);

            return ['status' => $response->getStatusCode()];
        },
    ]);

    @unlink($assignmentLocked);
    @unlink($cliStarted);
    expect(collect($results)->every(fn (array $result): bool => $result['ok']))->toBeTrue()
        ->and($results[1]['value']['status'])->toBe(403)
        ->and($device->fresh()->user_id)->toBeNull()
        ->and($device->fresh()->strategy_id_resolved)->toBe($fallback->id)
        ->and(Strategy::assignedStrategyId(Strategy::LEVEL_USER, $owner->id))->toBe($ownerPolicy->id);
});

it('streams large mysql owner and group context updates in bounded batches', function () {
    $owner = User::factory()->create();
    $group = DeviceGroup::create(['name' => 'MySQL large context group']);
    $now = now();
    foreach (array_chunk(range(1, 1201), 250) as $numbers) {
        DB::table('devices')->insert(array_map(fn (int $number): array => [
            'rustdesk_id' => 'mysql-large-context-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'uuid' => 'mysql-large-context-uuid-'.$number,
            'status' => Device::STATUS_ACTIVE,
            'user_id' => $owner->id,
            'device_group_id' => $group->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $numbers));
    }

    $maxBindings = 0;
    DB::listen(function ($query) use (&$maxBindings): void {
        $maxBindings = max($maxBindings, count($query->bindings));
    });
    $ownersChanged = Device::bulkUpdateStrategyContext(
        Device::query()->where('user_id', $owner->id),
        ['user_id' => null],
    );
    $groupsChanged = Device::bulkUpdateStrategyContext(
        Device::query()->where('device_group_id', $group->id),
        ['device_group_id' => null],
    );

    expect($ownersChanged)->toBe(1201)
        ->and($groupsChanged)->toBe(1201)
        ->and($maxBindings)->toBeLessThanOrEqual(501)
        ->and(Device::query()->whereNotNull('user_id')->count())->toBe(0)
        ->and(Device::query()->whereNotNull('device_group_id')->count())->toBe(0);
});
