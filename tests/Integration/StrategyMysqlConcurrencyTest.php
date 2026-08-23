<?php

use App\Models\Device;
use App\Models\Strategy;
use App\Models\StrategyRevision;
use App\Models\StrategyRollout;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
