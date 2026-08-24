<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Strategy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StrategyCompliance
{
    public const DETAIL_LIMIT = 200;

    private const STATES = ['confirmed', 'pending', 'stale', 'offline', 'overridden'];

    /** @return array{counts:array<string,int>,devices:array<string,array<int,array<string,mixed>>>} */
    public function summary(Strategy $strategy): array
    {
        return $this->summaries(collect([$strategy]), $strategy->id)[$strategy->id];
    }

    /**
     * Classify the fleet with one set-based streaming query. The joins expose
     * precedence assignments and immutable rollout evidence without a query per
     * 500-device chunk; only one device's duplicate rollout rows are retained at
     * a time. Detail rows remain capped independently from exact counts.
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

        $result = [];
        foreach ($strategies as $strategy) {
            $result[$strategy->id] = [
                'counts' => array_fill_keys(self::STATES, 0),
                'devices' => array_fill_keys(self::STATES, []),
            ];
        }
        $defaults = $strategies->filter(fn (Strategy $strategy) => $strategy->is_default)
            ->keys()->map(fn ($id) => (int) $id)->all();
        $detailLimit = max(0, min(1000, $detailLimit));
        $storedDetails = 0;
        $currentDevice = null;
        $rolloutEvidence = [];

        $flush = function () use (
            $strategies, $defaults, &$result, $detailStrategyId, $detailState,
            $detailLimit, &$storedDetails, &$currentDevice, &$rolloutEvidence,
        ): void {
            if (! $currentDevice instanceof Device) {
                return;
            }

            $resolvedId = $currentDevice->strategy_id_resolved === null
                ? null
                : (int) $currentDevice->strategy_id_resolved;
            if ($resolvedId !== null && isset($result[$resolvedId])) {
                $strategy = $strategies[$resolvedId];
                $release = $rolloutEvidence[$resolvedId] ?? null;
                $snapshot = $release['snapshot'] ?? null;
                $desired = $snapshot === null
                    ? $strategy->configOptions()
                    : Strategy::sanitizeOptions(is_array($snapshot['options'] ?? null) ? $snapshot['options'] : []);
                $state = $this->stateFor(
                    $currentDevice,
                    $strategy,
                    $desired,
                    (bool) ($release['timed_out'] ?? false),
                    $release['released_at'] ?? null,
                );
                $this->record($result, $currentDevice, $resolvedId, $state, $detailStrategyId, $detailState, $detailLimit, $storedDetails);
            }

            $candidateIds = array_unique(array_filter([
                $currentDevice->getAttribute('direct_strategy_id'),
                $currentDevice->getAttribute('owner_strategy_id'),
                $currentDevice->getAttribute('group_strategy_id'),
                ...$defaults,
                ...array_keys($rolloutEvidence),
            ], fn ($id) => $id !== null));
            foreach ($candidateIds as $candidateId) {
                $candidateId = (int) $candidateId;
                if (! isset($result[$candidateId]) || $resolvedId === $candidateId) {
                    continue;
                }
                $this->record($result, $currentDevice, $candidateId, 'overridden', $detailStrategyId, $detailState, $detailLimit, $storedDetails);
            }
        };

        $rows = Device::query()
            ->leftJoin('device_strategy as ds', 'ds.device_id', '=', 'devices.id')
            ->leftJoin('strategy_user as su', 'su.user_id', '=', 'devices.user_id')
            ->leftJoin('device_group_strategy as dgs', 'dgs.device_group_id', '=', 'devices.device_group_id')
            ->leftJoin('strategy_rollout_devices as srd', 'srd.device_id', '=', 'devices.id')
            ->leftJoin('strategy_rollouts as sr', 'sr.id', '=', 'srd.strategy_rollout_id')
            ->leftJoin('strategy_revisions as rev', 'rev.id', '=', 'sr.strategy_revision_id')
            ->leftJoin('strategies as rollout_strategy', 'rollout_strategy.id', '=', 'sr.strategy_id')
            ->where('devices.status', Device::STATUS_ACTIVE)
            ->orderBy('devices.id')
            ->orderBy('sr.id')
            ->select([
                'devices.*',
                'ds.strategy_id as direct_strategy_id',
                'su.strategy_id as owner_strategy_id',
                'dgs.strategy_id as group_strategy_id',
                'sr.id as rollout_id',
                'sr.strategy_id as rollout_strategy_id',
                'sr.strategy_revision_id as rollout_revision_id',
                'sr.status as rollout_status',
                'srd.released_at as rollout_released_at',
                'srd.timed_out_at as rollout_timed_out_at',
                'rev.snapshot as rollout_snapshot',
                'rollout_strategy.active_revision_id as rollout_active_revision_id',
            ])
            ->cursor();

        foreach ($rows as $row) {
            if ($currentDevice !== null && (int) $currentDevice->id !== (int) $row->id) {
                $flush();
                $rolloutEvidence = [];
            }
            $currentDevice = $row;

            $status = (string) ($row->getAttribute('rollout_status') ?? '');
            $released = $row->getAttribute('rollout_released_at') !== null;
            $completedCurrent = $status === 'completed'
                && (int) $row->getAttribute('rollout_active_revision_id') === (int) $row->getAttribute('rollout_revision_id');
            if (! $released || (! in_array($status, ['active', 'paused'], true) && ! $completedCurrent)) {
                continue;
            }

            $snapshot = $row->getAttribute('rollout_snapshot');
            $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;
            $rolloutEvidence[(int) $row->getAttribute('rollout_strategy_id')] = [
                'snapshot' => is_array($snapshot) ? $snapshot : [],
                'timed_out' => $row->getAttribute('rollout_timed_out_at') !== null,
                'released_at' => $row->getAttribute('rollout_released_at') === null
                    ? null
                    : Carbon::parse($row->getAttribute('rollout_released_at')),
            ];
        }
        $flush();

        foreach ($result as &$summary) {
            foreach ($summary['devices'] as &$devices) {
                usort($devices, fn (array $a, array $b) => strcmp($a['rustdesk_id'], $b['rustdesk_id']));
            }
            unset($devices);
        }
        unset($summary);

        return $result;
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

    private function stateFor(
        Device $device,
        Strategy $strategy,
        array $desired,
        bool $rolloutTimedOut,
        ?Carbon $releasedAt,
    ): string {
        $acked = Strategy::stringMap($device->strategy_acked_options);
        $sentAt = $device->strategy_sent_at;
        $ackedAt = $device->strategy_acked_at;
        if ($ackedAt !== null && $acked === $desired && ($sentAt === null || $ackedAt->gte($sentAt))) {
            return 'confirmed';
        }

        $sent = Strategy::stringMap($device->strategy_options);
        $deadlineFrom = $sent === $desired
            ? ($sentAt ?? $releasedAt ?? $strategy->updated_at)
            : ($releasedAt ?? $strategy->updated_at);
        $withinGrace = ! $rolloutTimedOut && ($deadlineFrom === null
            || $deadlineFrom->gt(now()->subMinutes(max(1, (int) $strategy->confirmation_timeout_minutes))));
        if ($withinGrace) {
            return 'pending';
        }

        return $device->isOnline() ? 'stale' : 'offline';
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
