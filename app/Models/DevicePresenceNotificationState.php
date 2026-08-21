<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DevicePresenceNotificationState extends Model
{
    public const CLAIM_TIMEOUT_SECONDS = 300;

    protected $fillable = ['device_id', 'offline_notified_at', 'offline_claim_token', 'offline_claimed_at'];

    protected function casts(): array
    {
        return [
            'offline_notified_at' => 'datetime',
            'offline_claimed_at' => 'datetime',
        ];
    }

    /** Preserve a delivered offline marker from the legacy 30-day cache key. */
    public static function recordLegacyOffline(Device $device): void
    {
        $now = now();
        static::query()->insertOrIgnore([
            'device_id' => $device->id,
            'offline_notified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Atomically acquire the right to attempt one offline delivery.
     *
     * The unique device row is inserted pending before transport starts. A dead
     * worker's pending row is reclaimable after a short bounded timeout.
     */
    public static function claimOfflineFor(Device $device): ?string
    {
        $token = (string) Str::uuid();
        $now = now();

        if (static::query()->insertOrIgnore([
            'device_id' => $device->id,
            'offline_claim_token' => $token,
            'offline_claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1) {
            return $token;
        }

        $reclaimed = static::query()
            ->where('device_id', $device->id)
            ->whereNull('offline_notified_at')
            ->where('offline_claimed_at', '<=', $now->copy()->subSeconds(self::CLAIM_TIMEOUT_SECONDS))
            ->update([
                'offline_claim_token' => $token,
                'offline_claimed_at' => $now,
                'updated_at' => $now,
            ]);

        return $reclaimed === 1 ? $token : null;
    }

    /** Mark delivery only when this contender still owns the pending claim. */
    public static function markDelivered(Device $device, string $token): bool
    {
        return static::query()
            ->where('device_id', $device->id)
            ->whereNull('offline_notified_at')
            ->where('offline_claim_token', $token)
            ->update([
                'offline_notified_at' => now(),
                'offline_claim_token' => null,
                'offline_claimed_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /** Release a failed, disabled, scoped, or cooldown-suppressed owned claim. */
    public static function releaseClaim(Device $device, string $token): bool
    {
        return static::query()
            ->where('device_id', $device->id)
            ->whereNull('offline_notified_at')
            ->where('offline_claim_token', $token)
            ->delete() === 1;
    }

    /**
     * Atomically consume the single outstanding recovery marker for a device.
     * Recovery is deliberately at-most-once: once consumed, a failed transport
     * is not retried because a second consumer must never send a duplicate.
     */
    public static function consumeFor(Device $device): bool
    {
        return static::query()
            ->where('device_id', $device->id)
            ->whereNotNull('offline_notified_at')
            ->delete() === 1;
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
