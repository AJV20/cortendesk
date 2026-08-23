<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'strategy_rollout_devices')
            ->withPivot(['position', 'released_at', 'confirmed_at'])
            ->withTimestamps();
    }

    public function targets(): HasMany
    {
        return $this->hasMany(StrategyRolloutDevice::class);
    }

    /**
     * @param  iterable<int,int|string>  $deviceIds  Arrays are normalized here;
     *                                               streaming iterables must already be unique and ordered.
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
            $open = static::query()
                ->where('strategy_id', $strategy->id)
                ->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_PAUSED])
                ->lockForUpdate()
                ->exists();

            if ($open) {
                throw ValidationException::withMessages([
                    'rollout' => 'This strategy already has a scheduled or active rollout.',
                ]);
            }

            if ($revision->strategy_id !== $strategy->id) {
                throw ValidationException::withMessages([
                    'rollout' => 'The selected revision does not belong to this strategy.',
                ]);
            }

            $snapshot = is_array($revision->snapshot) ? $revision->snapshot : [];
            if (($snapshot['name'] ?? $strategy->name) !== $strategy->name
                || (bool) ($snapshot['enabled'] ?? true) !== (bool) $strategy->enabled
                || (bool) ($snapshot['is_default'] ?? false) !== (bool) $strategy->is_default
                || (int) ($snapshot['confirmation_timeout_minutes'] ?? 15) !== (int) $strategy->confirmation_timeout_minutes) {
                throw ValidationException::withMessages([
                    'rollout' => 'Staged rollouts cannot change strategy identity, enabled state, default resolution, or confirmation timeout.',
                ]);
            }

            $isScheduled = $startsAt->isFuture();
            $rollout = static::query()->create([
                'strategy_id' => $strategy->id,
                'strategy_revision_id' => $revision->id,
                'status' => $isScheduled ? self::STATUS_SCHEDULED : self::STATUS_ACTIVE,
                'starts_at' => $startsAt,
                'batch_size' => $batchSize,
                'interval_minutes' => $intervalMinutes,
                'next_release_at' => $isScheduled ? $startsAt : now(),
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
            $rows[] = [
                'strategy_rollout_id' => $rolloutId,
                'device_id' => $deviceId,
                'device_rustdesk_id' => $names->get($deviceId),
                'position' => $position++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('strategy_rollout_devices')->insert($rows);
    }

    public function releaseNextBatch(): bool
    {
        $released = DB::transaction(function (): bool {
            // Keep lock ordering consistent with scheduling/deletion: strategy,
            // then rollout, then target rows.
            Strategy::query()->whereKey($this->strategy_id)->lockForUpdate()->firstOrFail();
            $rollout = static::query()->lockForUpdate()->findOrFail($this->id);
            if ($rollout->status !== self::STATUS_ACTIVE) {
                return false;
            }

            // Multiple scheduler hosts can discover the same due rollout. The
            // row lock plus this second due check prevents a double release.
            if ($rollout->next_release_at === null || $rollout->next_release_at->isFuture()) {
                return false;
            }

            $ids = DB::table('strategy_rollout_devices')
                ->where('strategy_rollout_id', $rollout->id)
                ->whereNull('released_at')
                ->orderBy('position')
                ->limit($rollout->batch_size)
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::table('strategy_rollout_devices')->whereIn('id', $ids)->update([
                    'released_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $pending = DB::table('strategy_rollout_devices')
                ->where('strategy_rollout_id', $rollout->id)
                ->whereNull('released_at')
                ->exists();

            if ($pending) {
                $rollout->update(['next_release_at' => now()->addMinutes($rollout->interval_minutes)]);

                return true;
            }

            $rollout->complete();

            return true;
        });

        $this->refresh();

        return $released;
    }

    public function pause(?int $userId): bool
    {
        $paused = static::query()
            ->whereKey($this->id)
            ->where('status', self::STATUS_ACTIVE)
            ->update([
                'status' => self::STATUS_PAUSED,
                'paused_by' => $userId,
                'paused_at' => now(),
                'next_release_at' => null,
                'updated_at' => now(),
            ]);
        $this->refresh();

        return $paused === 1;
    }

    public function resume(): bool
    {
        $resumed = static::query()
            ->whereKey($this->id)
            ->where('status', self::STATUS_PAUSED)
            ->update([
                'status' => self::STATUS_ACTIVE,
                'paused_by' => null,
                'paused_at' => null,
                'next_release_at' => now(),
                'updated_at' => now(),
            ]);
        $this->refresh();

        if ($resumed === 1) {
            $this->releaseNextBatch();
        }

        return $resumed === 1;
    }

    public function cancel(): bool
    {
        $cancelled = DB::transaction(function (): int {
            Strategy::query()->whereKey($this->strategy_id)->lockForUpdate()->firstOrFail();
            $rollout = static::query()->lockForUpdate()->findOrFail($this->id);
            if ($rollout->status === self::STATUS_ACTIVE
                && $rollout->targets()->whereNotNull('released_at')->exists()) {
                throw ValidationException::withMessages([
                    'rollout' => 'Pause this rollout before cancelling released devices.',
                ]);
            }

            return static::query()
                ->whereKey($rollout->id)
                ->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_PAUSED])
                ->update([
                    'status' => self::STATUS_CANCELLED,
                    'next_release_at' => null,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
        });
        $this->refresh();

        return $cancelled === 1;
    }

    /** Advance every rollout whose start/release boundary is due. */
    public static function advanceDue(): int
    {
        $advanced = 0;

        static::query()
            ->where('status', self::STATUS_SCHEDULED)
            ->where('starts_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$advanced): void {
                $started = static::query()
                    ->whereKey($id)
                    ->where('status', self::STATUS_SCHEDULED)
                    ->update([
                        'status' => self::STATUS_ACTIVE,
                        'next_release_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($started === 1) {
                    if (static::findOrFail($id)->releaseNextBatch()) {
                        $advanced++;
                    }
                }
            });

        static::query()
            ->where('status', self::STATUS_ACTIVE)
            ->where('next_release_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
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

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'next_release_at' => null,
            'completed_at' => now(),
        ]);
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
            ->whereNotNull('strategy_rollout_devices.released_at')
            ->orderByDesc('strategy_rollouts.id')
            ->first([
                'strategy_rollout_devices.id as target_id',
                'strategy_rollout_devices.delivered_version',
                'strategy_revisions.snapshot',
            ]);

        if ($row === null) {
            return null;
        }
        $snapshot = is_string($row->snapshot)
            ? json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR)
            : $row->snapshot;

        return [
            'snapshot' => is_array($snapshot) ? $snapshot : [],
            'target_id' => (int) $row->target_id,
            'delivered_version' => $row->delivered_version === null ? null : (int) $row->delivered_version,
        ];
    }

    public static function markDeliveredTarget(int $targetId, int $deviceId, int $version): void
    {
        $updated = DB::table('strategy_rollout_devices')
            ->where('id', $targetId)
            ->where('device_id', $deviceId)
            ->where(function ($query) use ($version): void {
                $query->whereNull('delivered_version')->orWhere('delivered_version', '!=', $version);
            })
            ->update([
                'delivered_version' => $version,
                'delivered_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 1) {
            DB::table('devices')->where('id', $deviceId)->update(['strategy_rollout_ack_pending' => true]);
        }
    }

    public static function markNoopTargetConfirmed(int $targetId): void
    {
        DB::table('strategy_rollout_devices')
            ->where('id', $targetId)
            ->whereNull('confirmed_at')
            ->update([
                'delivered_at' => now(),
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** Confirm the exact historical target represented by an echoed token. */
    public static function confirmDeliveredToken(int $deviceId, int $echoedVersion): void
    {
        if ($echoedVersion <= 0) {
            return;
        }

        DB::table('strategy_rollout_devices')
            ->where('device_id', $deviceId)
            ->where('delivered_version', $echoedVersion)
            ->whereNull('confirmed_at')
            ->update([
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);

        $pending = DB::table('strategy_rollout_devices')
            ->where('device_id', $deviceId)
            ->whereNotNull('delivered_version')
            ->whereNull('confirmed_at')
            ->exists();
        if (! $pending) {
            DB::table('devices')->where('id', $deviceId)->update(['strategy_rollout_ack_pending' => false]);
        }
    }
}
