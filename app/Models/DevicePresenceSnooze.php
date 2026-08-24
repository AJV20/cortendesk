<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** A bounded maintenance window for device presence alerts only. */
class DevicePresenceSnooze extends Model
{
    public const TARGET_DEVICE = 'device';

    public const TARGET_GROUP = 'group';

    protected $fillable = ['target_type', 'target_id', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public static function snoozeDevice(Device $device, Carbon $expiresAt): self
    {
        return self::put(self::TARGET_DEVICE, (int) $device->id, $expiresAt);
    }

    public static function snoozeGroup(DeviceGroup $group, Carbon $expiresAt): self
    {
        return self::put(self::TARGET_GROUP, (int) $group->id, $expiresAt);
    }

    /** Remove expired windows and bounded old targets deleted outside normal UI flows. */
    public static function pruneForSweep(): void
    {
        static::query()->where('expires_at', '<=', now())->delete();

        static::query()
            ->where('created_at', '<=', now()->subDays(7))
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('target_type', self::TARGET_DEVICE)
                        ->whereNotExists(fn ($subquery) => $subquery->selectRaw('1')
                            ->from('devices')
                            ->whereColumn('devices.id', 'device_presence_snoozes.target_id'));
                })->orWhere(function (Builder $query): void {
                    $query->where('target_type', self::TARGET_GROUP)
                        ->whereNotExists(fn ($subquery) => $subquery->selectRaw('1')
                            ->from('device_groups')
                            ->whereColumn('device_groups.id', 'device_presence_snoozes.target_id'));
                });
            })->delete();
    }

    /** @return array{device: array<int, true>, group: array<int, true>} */
    public static function activeTargets(): array
    {
        $targets = [self::TARGET_DEVICE => [], self::TARGET_GROUP => []];

        static::query()->active()->get(['target_type', 'target_id'])->each(function (self $snooze) use (&$targets): void {
            $targets[$snooze->target_type][(int) $snooze->target_id] = true;
        });

        return $targets;
    }

    public static function isActiveFor(Device $device): bool
    {
        return self::query()->active()->where(function (Builder $query) use ($device): void {
            $query->where(fn (Builder $query) => $query
                ->where('target_type', self::TARGET_DEVICE)
                ->where('target_id', $device->id));

            if ($device->device_group_id !== null) {
                $query->orWhere(fn (Builder $query) => $query
                    ->where('target_type', self::TARGET_GROUP)
                    ->where('target_id', $device->device_group_id));
            }
        })->exists();
    }

    private static function put(string $type, int $id, Carbon $expiresAt): self
    {
        return self::query()->updateOrCreate(
            ['target_type' => $type, 'target_id' => $id],
            ['expires_at' => $expiresAt],
        );
    }
}
