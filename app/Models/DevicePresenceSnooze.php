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
