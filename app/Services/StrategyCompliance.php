<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Strategy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StrategyCompliance
{
    public const DETAIL_LIMIT = 200;

    /** @return array{counts:array<string,int>,devices:array<string,array<int,array<string,mixed>>>} */
    public function summary(Strategy $strategy): array
    {
        return $this->summaries(collect([$strategy]), $strategy->id)[$strategy->id];
    }

    /**
     * Build exact counts for every requested strategy while retaining at most
     * DETAIL_LIMIT drill-down rows for the selected strategy. Fleet devices,
     * assignments, and rollout targets are read in chunks rather than retained
     * as one unbounded Livewire payload.
     *
     * @param  Collection<int,Strategy>  $strategies
     * @return array<int,array{counts:array<string,int>,devices:array<string,array<int,array<string,mixed>>>}>
     */
    public function summaries(
        Collection $strategies,
        ?int $detailStrategyId = null,
        string $detailState = 'all',
        int $detailLimit = self::DETAIL_LIMIT,
    ): array {
        $strategies = $strategies->keyBy('id');
        if ($strategies->isEmpty()) {
            return [];
        }

        $states = ['confirmed', 'pending', 'stale', 'offline', 'overridden'];
        $result = [];
        foreach ($strategies as $strategy) {
            $result[$strategy->id] = [
                'counts' => array_fill_keys($states, 0),
                'devices' => array_fill_keys($states, []),
            ];
        }

        $defaults = $strategies->filter(fn (Strategy $strategy) => $strategy->is_default)->keys()->map(fn ($id) => (int) $id)->all();
        $storedDetails = 0;
        $detailLimit = max(0, min(1000, $detailLimit));

        Device::query()
            ->where('status', Device::STATUS_ACTIVE)
            ->orderBy('id')
            ->chunkById(500, function (Collection $devices) use (
                $strategies, $defaults, &$result,
                $detailStrategyId, $detailState, $detailLimit, &$storedDetails,
            ): void {
                $deviceIds = $devices->pluck('id')->map(fn ($id) => (int) $id)->all();
                $userIds = $devices->pluck('user_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
                $groupIds = $devices->pluck('device_group_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();

                $direct = DB::table('device_strategy')->whereIn('device_id', $deviceIds)->pluck('strategy_id', 'device_id')->all();
                $owners = $userIds === [] ? [] : DB::table('strategy_user')->whereIn('user_id', $userIds)->pluck('strategy_id', 'user_id')->all();
                $groups = $groupIds === [] ? [] : DB::table('device_group_strategy')->whereIn('device_group_id', $groupIds)->pluck('strategy_id', 'device_group_id')->all();
                $released = $this->releasedSnapshots($deviceIds, $strategies->keys()->map(fn ($id) => (int) $id)->all());

                foreach ($devices as $device) {
                    $resolvedId = $device->strategy_id_resolved === null ? null : (int) $device->strategy_id_resolved;
                    if ($resolvedId !== null && isset($result[$resolvedId])) {
                        $strategy = $strategies[$resolvedId];
                        $snapshot = $released[$resolvedId.':'.$device->id] ?? null;
                        $desired = $snapshot === null
                            ? $strategy->configOptions()
                            : Strategy::sanitizeOptions(is_array($snapshot['options'] ?? null) ? $snapshot['options'] : []);
                        $state = $this->stateFor($device, $strategy, $desired);
                        $this->record($result, $device, $resolvedId, $state, $detailStrategyId, $detailState, $detailLimit, $storedDetails);
                    }

                    $candidateIds = array_unique(array_filter([
                        isset($direct[$device->id]) ? (int) $direct[$device->id] : null,
                        $device->user_id !== null && isset($owners[$device->user_id]) ? (int) $owners[$device->user_id] : null,
                        $device->device_group_id !== null && isset($groups[$device->device_group_id]) ? (int) $groups[$device->device_group_id] : null,
                        ...$defaults,
                    ], fn ($id) => $id !== null));

                    foreach ($candidateIds as $candidateId) {
                        if (! isset($result[$candidateId]) || $resolvedId === $candidateId) {
                            continue;
                        }
                        $this->record($result, $device, $candidateId, 'overridden', $detailStrategyId, $detailState, $detailLimit, $storedDetails);
                    }
                }
            });

        foreach ($result as &$summary) {
            foreach ($summary['devices'] as &$rows) {
                usort($rows, fn (array $a, array $b) => strcmp($a['rustdesk_id'], $b['rustdesk_id']));
            }
            unset($rows);
        }
        unset($summary);

        return $result;
    }

    /** @return array<string,array<string,mixed>> */
    private function releasedSnapshots(array $deviceIds, array $strategyIds): array
    {
        if ($deviceIds === [] || $strategyIds === []) {
            return [];
        }

        return DB::table('strategy_rollouts')
            ->join('strategy_rollout_devices', 'strategy_rollout_devices.strategy_rollout_id', '=', 'strategy_rollouts.id')
            ->join('strategy_revisions', 'strategy_revisions.id', '=', 'strategy_rollouts.strategy_revision_id')
            ->whereIn('strategy_rollouts.strategy_id', $strategyIds)
            ->whereIn('strategy_rollouts.status', ['active', 'paused'])
            ->whereIn('strategy_rollout_devices.device_id', $deviceIds)
            ->whereNotNull('strategy_rollout_devices.released_at')
            ->get(['strategy_rollouts.strategy_id', 'strategy_rollout_devices.device_id', 'strategy_revisions.snapshot'])
            ->mapWithKeys(function ($row): array {
                $snapshot = is_string($row->snapshot) ? json_decode($row->snapshot, true) : $row->snapshot;

                return [$row->strategy_id.':'.$row->device_id => is_array($snapshot) ? $snapshot : []];
            })->all();
    }

    private function record(
        array &$result,
        Device $device,
        int $strategyId,
        string $state,
        ?int $detailStrategyId,
        string $detailState,
        int $detailLimit,
        int &$storedDetails,
    ): void {
        $result[$strategyId]['counts'][$state]++;
        if ($strategyId !== $detailStrategyId
            || ($detailState !== 'all' && $detailState !== $state)
            || $storedDetails >= $detailLimit) {
            return;
        }

        $result[$strategyId]['devices'][$state][] = $this->row($device, $state);
        $storedDetails++;
    }

    private function stateFor(Device $device, Strategy $strategy, array $desired): string
    {
        if (! $device->isOnline()) {
            return 'offline';
        }

        $acked = Strategy::stringMap($device->strategy_acked_options);
        $sentAt = $device->strategy_sent_at;
        $ackedAt = $device->strategy_acked_at;
        if ($ackedAt !== null && $acked === $desired && ($sentAt === null || $ackedAt->gte($sentAt))) {
            return 'confirmed';
        }

        $deadlineFrom = $sentAt ?? $strategy->updated_at;

        return $deadlineFrom !== null
            && $deadlineFrom->lte(now()->subMinutes(max(1, (int) $strategy->confirmation_timeout_minutes)))
                ? 'stale'
                : 'pending';
    }

    /** @return array<string,mixed> */
    private function row(Device $device, string $state): array
    {
        return [
            'id' => $device->id,
            'rustdesk_id' => $device->rustdesk_id,
            'label' => $device->alias ?: $device->hostname ?: 'Unlabelled',
            'state' => $state,
            'last_online_at' => $device->last_online_at,
            'sent_at' => $device->strategy_sent_at,
            'acked_at' => $device->strategy_acked_at,
        ];
    }
}
