<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'strategy_id', 'strategy_revision_id', 'status', 'starts_at', 'batch_size',
    'interval_minutes', 'next_release_at', 'created_by', 'paused_by',
    'paused_at', 'completed_at',
])]
class StrategyRollout extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const OPEN_STATUSES = [self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_PAUSED];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Strategy rollout evidence is immutable and cannot be deleted directly.');
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'next_release_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'batch_size' => 'integer',
            'interval_minutes' => 'integer',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class)->withTrashed();
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(StrategyRevision::class, 'strategy_revision_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(StrategyRolloutDevice::class);
    }

    public function getTargetCountAttribute(): int
    {
        return isset($this->attributes['targets_count'])
            ? (int) $this->attributes['targets_count']
            : $this->targets()->count();
    }

    /**
     * @param  iterable<int,int|string>  $deviceIds  Arrays are normalized here;
     *                                               streams must already be unique and ordered.
     */
    public static function schedule(
        Strategy $strategy,
        StrategyRevision $revision,
        iterable $deviceIds,
        CarbonInterface $startsAt,
        int $batchSize,
        int $intervalMinutes,
        ?int $userId,
    ): self {
        $batchSize = max(1, min(1000, $batchSize));
        $intervalMinutes = max(1, min(10080, $intervalMinutes));
        if (is_array($deviceIds)) {
            $deviceIds = collect($deviceIds)->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();
        }

        $rollout = DB::transaction(function () use (
            $strategy, $revision, $deviceIds, $startsAt, $batchSize, $intervalMinutes, $userId,
        ): self {
            Strategy::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $strategy = Strategy::findOrFail($strategy->id);
            if ($revision->strategy_id !== $strategy->id) {
                throw ValidationException::withMessages(['rollout' => 'The selected revision does not belong to this strategy.']);
            }
            if (static::query()->where('strategy_id', $strategy->id)
                ->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_PAUSED])
                ->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['rollout' => 'This strategy already has a scheduled or active rollout.']);
            }

            $snapshot = is_array($revision->snapshot) ? $revision->snapshot : [];
            if (($snapshot['name'] ?? $strategy->name) !== $strategy->name
                || (bool) ($snapshot['enabled'] ?? $strategy->enabled) !== (bool) $strategy->enabled
                || (bool) ($snapshot['is_default'] ?? $strategy->is_default) !== (bool) $strategy->is_default
                || (int) ($snapshot['confirmation_timeout_minutes'] ?? $strategy->confirmation_timeout_minutes) !== (int) $strategy->confirmation_timeout_minutes) {
                throw ValidationException::withMessages([
                    'rollout' => 'Staged rollouts cannot change strategy identity, enabled state, default resolution, or confirmation timeout.',
                ]);
            }

            $scheduled = $startsAt->isFuture();
            $rollout = static::query()->create([
                'strategy_id' => $strategy->id,
                'strategy_revision_id' => $revision->id,
                'status' => $scheduled ? self::STATUS_SCHEDULED : self::STATUS_ACTIVE,
                'starts_at' => $startsAt,
                'batch_size' => $batchSize,
                'interval_minutes' => $intervalMinutes,
                'next_release_at' => $scheduled ? $startsAt : now(),
                'created_by' => $userId,
            ]);

            $position = 1;
            $buffer = [];
            foreach ($deviceIds as $deviceId) {
                $deviceId = (int) $deviceId;
                if ($deviceId <= 0) {
                    continue;
                }
                $buffer[] = $deviceId;
                if (count($buffer) === 100) {
                    self::insertTargets($rollout->id, $buffer, $position);
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                self::insertTargets($rollout->id, $buffer, $position);
            }
            if ($position === 1) {
                throw ValidationException::withMessages(['rollout' => 'A staged rollout requires at least one target device.']);
            }

            return $rollout;
        });

        if ($rollout->status === self::STATUS_ACTIVE) {
            $rollout->releaseNextBatch();
        }

        return $rollout->fresh();
    }

    /** @param array<int,int> $deviceIds */
    private static function insertTargets(int $rolloutId, array $deviceIds, int &$position): void
    {
        $names = Device::withTrashed()->whereIn('id', $deviceIds)->pluck('rustdesk_id', 'id');
        $now = now();
        $rows = [];
        foreach ($deviceIds as $deviceId) {
            if (! $names->has($deviceId)) {
                continue;
            }
            $rows[] = [
                'strategy_rollout_id' => $rolloutId,
                'device_id' => $deviceId,
                'device_rustdesk_id' => $names->get($deviceId),
                'position' => $position++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            DB::table('strategy_rollout_devices')->insert($rows);
        }
    }

    public function releaseNextBatch(): bool
    {
        $advanced = DB::transaction(function (): bool {
            // Shared lock order: all strategies, then rollout, then targets.
            Strategy::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $rollout = static::query()->lockForUpdate()->findOrFail($this->id);
            if ($rollout->status !== self::STATUS_ACTIVE || $rollout->next_release_at === null || $rollout->next_release_at->isFuture()) {
                return false;
            }

            if (! $this->closeOrWaitForReleasedCohort($rollout)) {
                return false;
            }

            $ids = DB::table('strategy_rollout_devices')->where('strategy_rollout_id', $rollout->id)
                ->whereNull('released_at')->orderBy('position')->limit($rollout->batch_size)->lockForUpdate()->pluck('id');
            if ($ids->isNotEmpty()) {
                DB::table('strategy_rollout_devices')->whereIn('id', $ids)->update(['released_at' => now(), 'updated_at' => now()]);
                // Even the final batch must be confirmed or time out before the
                // candidate becomes the global active revision.
                $rollout->update(['next_release_at' => now()->addMinutes($rollout->interval_minutes)]);

                return true;
            }

            // No unreleased targets remain and every released target is terminal.
            $rollout->complete();

            return true;
        });
        $this->refresh();

        return $advanced;
    }

    /** Mark expired confirmations and defer while any released target is pending. */
    private function closeOrWaitForReleasedCohort(StrategyRollout $rollout): bool
    {
        $targets = StrategyRolloutDevice::query()->where('strategy_rollout_id', $rollout->id)
            ->whereNotNull('released_at')
            ->whereNull('confirmed_at')
            ->whereNull('timed_out_at')
            ->orderBy('position')
            ->lockForUpdate()
            ->get(['id', 'released_at']);
        if ($targets->isEmpty()) {
            return true;
        }

        $timeout = max(1, (int) $rollout->strategy()->value('confirmation_timeout_minutes'));
        $cutoff = now()->subMinutes($timeout);
        $expired = $targets->filter(fn (StrategyRolloutDevice $target) => $target->released_at->lte($cutoff));
        if ($expired->isNotEmpty()) {
            StrategyRolloutDevice::query()->whereIn('id', $expired->pluck('id'))->update([
                'timed_out_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $waiting = $targets->reject(fn (StrategyRolloutDevice $target) => $expired->contains('id', $target->id));
        if ($waiting->isEmpty()) {
            return true;
        }

        $nextDeadline = $waiting->min(fn (StrategyRolloutDevice $target) => $target->released_at->copy()->addMinutes($timeout));
        $rollout->update(['next_release_at' => $nextDeadline->isFuture() ? $nextDeadline : now()->addMinute()]);

        return false;
    }

    public function pause(?int $userId): bool
    {
        $paused = DB::transaction(function () use ($userId): bool {
            Strategy::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $rollout = static::query()->lockForUpdate()->findOrFail($this->id);
            if ($rollout->status !== self::STATUS_ACTIVE) {
                return false;
            }
            $rollout->update([
                'status' => self::STATUS_PAUSED,
                'paused_by' => $userId,
                'paused_at' => now(),
                'next_release_at' => null,
            ]);

            return true;
        });
        $this->refresh();

        return $paused;
    }

    public function resume(): bool
    {
        $resumed = DB::transaction(function (): bool {
            Strategy::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $rollout = static::query()->lockForUpdate()->findOrFail($this->id);
            if ($rollout->status !== self::STATUS_PAUSED) {
                return false;
            }
            $rollout->update([
                'status' => self::STATUS_ACTIVE,
                'paused_by' => null,
                'paused_at' => null,
                'next_release_at' => now(),
            ]);

            return true;
        });
        $this->refresh();

        if ($resumed) {
            $this->releaseNextBatch();
        }

        return $resumed;
    }

    public function cancel(): bool
    {
        $cancelled = DB::transaction(function (): bool {
            Strategy::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $rollout = static::query()->lockForUpdate()->findOrFail($this->id);
            if (! in_array($rollout->status, [self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_PAUSED], true)) {
                return false;
            }
            if ($rollout->targets()->whereNotNull('released_at')->exists()) {
                throw ValidationException::withMessages([
                    'rollout' => 'A rollout can only be cancelled before its first batch is released.',
                ]);
            }
            $rollout->update([
                'status' => self::STATUS_CANCELLED,
                'next_release_at' => null,
                'completed_at' => now(),
            ]);

            return true;
        });
        $this->refresh();

        return $cancelled;
    }

    /** Advance every rollout whose start/release boundary is due. */
    public static function advanceDue(): int
    {
        $advanced = 0;
        static::query()->where('status', self::STATUS_SCHEDULED)->where('starts_at', '<=', now())->orderBy('id')->pluck('id')
            ->each(function (int $id) use (&$advanced): void {
                $started = static::query()->whereKey($id)->where('status', self::STATUS_SCHEDULED)->update([
                    'status' => self::STATUS_ACTIVE, 'next_release_at' => now(), 'updated_at' => now(),
                ]);
                if ($started === 1 && static::findOrFail($id)->releaseNextBatch()) {
                    $advanced++;
                }
            });
        static::query()->where('status', self::STATUS_ACTIVE)->where('next_release_at', '<=', now())->orderBy('id')->pluck('id')
            ->each(function (int $id) use (&$advanced): void {
                if (static::findOrFail($id)->releaseNextBatch()) {
                    $advanced++;
                }
            });

        return $advanced;
    }

    private function complete(): void
    {
        $revision = $this->revision()->lockForUpdate()->firstOrFail();
        $strategy = $this->strategy()->lockForUpdate()->firstOrFail();
        $snapshot = $revision->snapshot;
        $strategy->fill([
            'note' => $snapshot['note'] ?? null,
            'enabled' => (bool) ($snapshot['enabled'] ?? true),
            'is_default' => (bool) ($snapshot['is_default'] ?? false),
            'enforce' => (bool) ($snapshot['enforce'] ?? false),
            'confirmation_timeout_minutes' => (int) ($snapshot['confirmation_timeout_minutes'] ?? 15),
        ]);
        $strategy->setOptions(is_array($snapshot['options'] ?? null) ? $snapshot['options'] : []);
        $strategy->save();
        $strategy->forceFill(['active_revision_id' => $revision->id])->saveQuietly();
        $this->update(['status' => self::STATUS_COMPLETED, 'next_release_at' => null, 'completed_at' => now()]);
    }

    /** @return array{snapshot:array<string,mixed>,target_id:int,delivered_version:?int}|null */
    public static function releasedPolicyFor(int $strategyId, int $deviceId): ?array
    {
        $row = DB::table('strategy_rollouts')
            ->join('strategy_rollout_devices', 'strategy_rollout_devices.strategy_rollout_id', '=', 'strategy_rollouts.id')
            ->join('strategy_revisions', 'strategy_revisions.id', '=', 'strategy_rollouts.strategy_revision_id')
            ->join('strategies', 'strategies.id', '=', 'strategy_rollouts.strategy_id')
            ->where('strategy_rollouts.strategy_id', $strategyId)
            ->where(function ($query): void {
                $query->whereIn('strategy_rollouts.status', [self::STATUS_ACTIVE, self::STATUS_PAUSED])
                    ->orWhere(function ($query): void {
                        $query->where('strategy_rollouts.status', self::STATUS_COMPLETED)
                            ->whereColumn('strategies.active_revision_id', 'strategy_rollouts.strategy_revision_id')
                            ->whereNull('strategy_rollout_devices.delivered_version');
                    });
            })
            ->where('strategy_rollout_devices.device_id', $deviceId)
            ->whereNotNull('strategy_rollout_devices.released_at')->orderByDesc('strategy_rollouts.id')
            ->first(['strategy_rollout_devices.id as target_id', 'strategy_rollout_devices.delivered_version', 'strategy_revisions.snapshot']);
        if ($row === null) {
            return null;
        }
        $snapshot = is_string($row->snapshot) ? json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR) : $row->snapshot;

        return ['snapshot' => is_array($snapshot) ? $snapshot : [], 'target_id' => (int) $row->target_id, 'delivered_version' => $row->delivered_version === null ? null : (int) $row->delivered_version];
    }

    public static function markDeliveredTarget(int $targetId, int $deviceId, int $version): void
    {
        $updated = DB::table('strategy_rollout_devices')->where('id', $targetId)->where('device_id', $deviceId)
            ->where(fn ($query) => $query->whereNull('delivered_version')->orWhere('delivered_version', '!=', $version))
            ->update(['delivered_version' => $version, 'delivered_at' => now(), 'updated_at' => now()]);
        if ($updated === 1) {
            DB::table('devices')->where('id', $deviceId)->update(['strategy_rollout_ack_pending' => true]);
        }
    }

    public static function markNoopTargetConfirmed(int $targetId): void
    {
        DB::table('strategy_rollout_devices')->where('id', $targetId)->whereNull('confirmed_at')
            ->update(['delivered_at' => now(), 'confirmed_at' => now(), 'updated_at' => now()]);
    }

    public static function confirmDeliveredToken(int $deviceId, int $echoedVersion): void
    {
        if ($echoedVersion <= 0) {
            return;
        }
        DB::table('strategy_rollout_devices')->where('device_id', $deviceId)->where('delivered_version', $echoedVersion)->whereNull('confirmed_at')
            ->update(['confirmed_at' => now(), 'updated_at' => now()]);
        if (! DB::table('strategy_rollout_devices')->where('device_id', $deviceId)->whereNotNull('delivered_version')->whereNull('confirmed_at')->exists()) {
            DB::table('devices')->where('id', $deviceId)->update(['strategy_rollout_ack_pending' => false]);
        }
    }
}
