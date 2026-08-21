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

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
