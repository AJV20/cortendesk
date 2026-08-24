<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['strategy_rollout_id', 'device_id', 'device_rustdesk_id', 'position', 'released_at', 'delivered_version', 'delivered_at', 'confirmed_at', 'timed_out_at'])]
class StrategyRolloutDevice extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Strategy rollout target evidence is immutable and cannot be deleted directly.');
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'released_at' => 'datetime',
            'delivered_version' => 'integer',
            'delivered_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'timed_out_at' => 'datetime',
        ];
    }

    public function rollout(): BelongsTo
    {
        return $this->belongsTo(StrategyRollout::class, 'strategy_rollout_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withTrashed();
    }
}
