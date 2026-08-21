<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevicePresenceNotificationState extends Model
{
    protected $fillable = ['device_id', 'offline_notified_at'];

    protected function casts(): array
    {
        return ['offline_notified_at' => 'datetime'];
    }

    /**
     * Atomically consume the single outstanding recovery marker for a device.
     *
     * A conditional DELETE is one database statement, so the scheduler and a
     * concurrent heartbeat cannot both win the right to emit a recovery alert.
     */
    public static function consumeFor(Device $device): bool
    {
        return static::query()->where('device_id', $device->id)->delete() === 1;
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
