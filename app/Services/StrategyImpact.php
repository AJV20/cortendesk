<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Strategy;
use Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class StrategyImpact
{
    /** @param array<string,mixed> $proposed */
    public static function preview(?Strategy $strategy, array $proposed): array
    {
        $before = self::before($strategy);
        $beforeOptions = is_array($before['options'] ?? null) ? $before['options'] : [];
        $afterOptions = is_array($proposed['options'] ?? null) ? $proposed['options'] : [];
        $optionChanges = [];

        foreach (array_unique([...array_keys($beforeOptions), ...array_keys($afterOptions)]) as $key) {
            $old = Arr::get($beforeOptions, $key);
            $new = Arr::get($afterOptions, $key);
            if ($old !== $new) {
                $optionChanges[$key] = ['before' => $old, 'after' => $new];
            }
        }
        ksort($optionChanges);

        $dangerousKeys = [
            'access-mode' => ['view'],
            'enable-keyboard' => ['N'],
            'enable-file-transfer' => ['N'],
            'enable-terminal' => ['N'],
            'allow-remote-config-modification' => ['Y'],
        ];
        $dangerous = [];
        foreach ($optionChanges as $key => $change) {
            if (isset($dangerousKeys[$key]) && in_array($change['after'], $dangerousKeys[$key], true)) {
                $dangerous[] = ['key' => $key, 'before' => $change['before'], 'after' => $change['after']];
            }
        }

        $policyChanged = self::policyChanged($before, $proposed, $optionChanges);
        $scan = self::scan($strategy, $proposed, $policyChanged, true);

        return [
            'option_changes' => $optionChanges,
            'dangerous' => $dangerous,
            'resets' => collect($optionChanges)
                ->filter(fn (array $change) => $change['before'] !== null && $change['after'] === null)
                ->keys()->values()->all(),
            'affected_count' => $scan['count'],
            'affected_devices' => $scan['sample'],
            'metadata_changes' => collect(['name', 'note', 'enabled', 'is_default', 'enforce', 'confirmation_timeout_minutes'])
                ->filter(fn (string $key) => ($before[$key] ?? null) !== ($proposed[$key] ?? null))
                ->mapWithKeys(fn (string $key) => [$key => [
                    'before' => $before[$key] ?? null,
                    'after' => $proposed[$key] ?? null,
                ]])->all(),
            'fingerprint' => self::fingerprintFromDigest($strategy, $proposed, $scan['digest']),
        ];
    }

    /** @param array<string,mixed> $snapshot */
    public static function fingerprint(?Strategy $strategy, array $snapshot): string
    {
        $before = self::before($strategy);
        $scan = self::scan($strategy, $snapshot, self::policyChanged($before, $snapshot), false);

        return self::fingerprintFromDigest($strategy, $snapshot, $scan['digest']);
    }

    /** @param array<string,mixed> $proposed @return Generator<int,int> */
    public static function affectedDeviceIds(?Strategy $strategy, array $proposed): Generator
    {
        foreach (self::impactStream($strategy, $proposed) as [$device]) {
            yield (int) $device->id;
        }
    }

    /** @return array<string,mixed> */
    private static function before(?Strategy $strategy): array
    {
        return $strategy?->snapshot() ?? [
            'name' => null,
            'note' => null,
            'enabled' => false,
            'is_default' => false,
            'enforce' => false,
            'confirmation_timeout_minutes' => 15,
            'options' => [],
        ];
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $proposed */
    private static function policyChanged(array $before, array $proposed, ?array $optionChanges = null): bool
    {
        if ($optionChanges === null) {
            $old = is_array($before['options'] ?? null) ? Strategy::sanitizeOptions($before['options']) : [];
            $new = is_array($proposed['options'] ?? null) ? Strategy::sanitizeOptions($proposed['options']) : [];
            $optionChanges = $old === $new ? [] : ['changed'];
        }

        return $optionChanges !== []
            || (bool) ($before['enabled'] ?? false) !== (bool) ($proposed['enabled'] ?? false)
            || (bool) ($before['is_default'] ?? false) !== (bool) ($proposed['is_default'] ?? false)
            || (bool) ($before['enforce'] ?? false) !== (bool) ($proposed['enforce'] ?? false)
            || (int) ($before['confirmation_timeout_minutes'] ?? 15) !== (int) ($proposed['confirmation_timeout_minutes'] ?? 15);
    }

    /** @return array{count:int,sample:array<int,array<string,mixed>>,digest:string} */
    private static function scan(?Strategy $strategy, array $proposed, bool $policyChanged, bool $withSample): array
    {
        $hash = hash_init('sha256');
        $count = 0;
        $sample = [];

        if ($policyChanged) {
            foreach (self::impactStream($strategy, $proposed) as [$device, $level]) {
                $count++;
                hash_update($hash, $device->id.':'.($level ?? 'none').';');
                if ($withSample && count($sample) < 50) {
                    $sample[] = [
                        'id' => $device->id,
                        'rustdesk_id' => $device->rustdesk_id,
                        'label' => $device->alias ?: ($device->hostname ?: $device->rustdesk_id),
                        'winning_level' => $level,
                    ];
                }
            }
        }

        usort($sample, fn (array $a, array $b) => strcmp($a['rustdesk_id'], $b['rustdesk_id']));

        return ['count' => $count, 'sample' => $sample, 'digest' => hash_final($hash)];
    }

    private static function fingerprintFromDigest(?Strategy $strategy, array $snapshot, string $digest): string
    {
        return hash('sha256', json_encode([
            'strategy_id' => $strategy?->id,
            'updated_at' => $strategy?->updated_at?->toJSON(),
            'snapshot' => $snapshot,
            'impact_digest' => $digest,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return Generator<int,array{0:Device,1:string|null}> */
    private static function impactStream(?Strategy $strategy, array $proposed): Generator
    {
        $targetId = $strategy?->id ?? -1;
        $enabled = Strategy::query()->pluck('enabled', 'id')->map(fn ($value) => (bool) $value)->all();
        $enabled[$targetId] = (bool) ($proposed['enabled'] ?? false);

        $defaultId = (($proposed['enabled'] ?? false) && ($proposed['is_default'] ?? false))
            ? $targetId
            : Strategy::query()
                ->where('enabled', true)
                ->where('is_default', true)
                ->when($strategy !== null, fn ($query) => $query->whereKeyNot($strategy->id))
                ->value('id');

        $lastId = 0;
        do {
            $devices = Device::query()
                ->where('status', Device::STATUS_ACTIVE)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(500)
                ->get(['id', 'rustdesk_id', 'alias', 'hostname', 'user_id', 'device_group_id', 'strategy_id_resolved']);
            if ($devices->isEmpty()) {
                break;
            }

            $deviceIds = $devices->pluck('id')->all();
            $userIds = $devices->pluck('user_id')->filter()->unique()->all();
            $groupIds = $devices->pluck('device_group_id')->filter()->unique()->all();
            $direct = DB::table('device_strategy')->whereIn('device_id', $deviceIds)->pluck('strategy_id', 'device_id')->all();
            $owners = $userIds === [] ? [] : DB::table('strategy_user')->whereIn('user_id', $userIds)->pluck('strategy_id', 'user_id')->all();
            $groups = $groupIds === [] ? [] : DB::table('device_group_strategy')->whereIn('device_group_id', $groupIds)->pluck('strategy_id', 'device_group_id')->all();

            foreach ($devices as $device) {
                $winner = null;
                $winningLevel = null;
                foreach ([
                    Strategy::LEVEL_DEVICE => $direct[$device->id] ?? null,
                    Strategy::LEVEL_USER => $device->user_id === null ? null : ($owners[$device->user_id] ?? null),
                    Strategy::LEVEL_DEVICE_GROUP => $device->device_group_id === null ? null : ($groups[$device->device_group_id] ?? null),
                    'default' => $defaultId,
                ] as $level => $candidate) {
                    if ($candidate !== null && ($enabled[(int) $candidate] ?? false)) {
                        $winner = (int) $candidate;
                        $winningLevel = $level;
                        break;
                    }
                }

                $current = $device->strategy_id_resolved === null ? null : (int) $device->strategy_id_resolved;
                if ($winner !== $current || ($strategy !== null && $winner === $targetId)) {
                    yield [$device, $winningLevel];
                }
            }

            $lastId = (int) $devices->last()->id;
        } while ($devices->count() === 500);
    }
}
