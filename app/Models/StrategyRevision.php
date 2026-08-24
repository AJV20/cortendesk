<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['strategy_id', 'revision', 'snapshot', 'change_note', 'created_by', 'created_by_name', 'affected_devices'])]
class StrategyRevision extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Strategy revisions are immutable.');
        });

        static::deleting(function (): never {
            throw new \LogicException('Strategy revisions are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'revision' => 'integer',
            'affected_devices' => 'integer',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class)->withTrashed();
    }

    public function rollouts(): HasMany
    {
        return $this->hasMany(StrategyRollout::class, 'strategy_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<int,array{key:string,before:mixed,after:mixed}>
     */
    public static function diffSnapshots(array $before, array $after): array
    {
        $changes = [];

        foreach (['name', 'note', 'enabled', 'is_default', 'enforce', 'confirmation_timeout_minutes'] as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[] = ['key' => $key, 'before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
            }
        }

        $beforeOptions = is_array($before['options'] ?? null) ? $before['options'] : [];
        $afterOptions = is_array($after['options'] ?? null) ? $after['options'] : [];
        foreach (array_unique([...array_keys($beforeOptions), ...array_keys($afterOptions)]) as $key) {
            if (($beforeOptions[$key] ?? null) !== ($afterOptions[$key] ?? null)) {
                $changes[] = [
                    'key' => 'options.'.$key,
                    'before' => $beforeOptions[$key] ?? null,
                    'after' => $afterOptions[$key] ?? null,
                ];
            }
        }

        return $changes;
    }

    public static function capture(Strategy $strategy, ?int $userId, ?string $changeNote = null): self
    {
        return static::captureSnapshot($strategy, $strategy->snapshot(), $userId, $changeNote);
    }

    /** @param array<string,mixed> $snapshot */
    public static function captureSnapshot(
        Strategy $strategy,
        array $snapshot,
        ?int $userId,
        ?string $changeNote = null,
        ?int $affectedDevices = null,
    ): self {
        return DB::transaction(function () use ($strategy, $snapshot, $userId, $changeNote, $affectedDevices): self {
            if ($changeNote !== null && mb_strlen($changeNote) > 500) {
                throw new \InvalidArgumentException('Strategy revision notes may not exceed 500 characters.');
            }

            // Locking the owning strategy serializes allocation even when this is
            // the first revision and MAX(revision) has no row to lock.
            Strategy::query()->whereKey($strategy->id)->lockForUpdate()->firstOrFail();

            $snapshot = [
                'name' => (string) ($snapshot['name'] ?? $strategy->name),
                'note' => isset($snapshot['note']) ? (string) $snapshot['note'] : null,
                'enabled' => (bool) ($snapshot['enabled'] ?? $strategy->enabled),
                'is_default' => (bool) ($snapshot['is_default'] ?? $strategy->is_default),
                'enforce' => (bool) ($snapshot['enforce'] ?? $strategy->enforce),
                'confirmation_timeout_minutes' => max(1, min(10080, (int) ($snapshot['confirmation_timeout_minutes'] ?? $strategy->confirmation_timeout_minutes ?? 15))),
                'options' => Strategy::sanitizeOptions(is_array($snapshot['options'] ?? null) ? $snapshot['options'] : []),
            ];

            return static::query()->create([
                'strategy_id' => $strategy->id,
                'revision' => (int) static::query()->where('strategy_id', $strategy->id)->max('revision') + 1,
                'snapshot' => $snapshot,
                'change_note' => $changeNote,
                'created_by' => $userId,
                'created_by_name' => $userId === null ? null : User::query()->whereKey($userId)->value('username'),
                'affected_devices' => $affectedDevices ?? $strategy->resolvedDevices()->count(),
            ]);
        });
    }
}
