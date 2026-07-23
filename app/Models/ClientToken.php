<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'token', 'device_id', 'device_uuid', 'device_os', 'device_name', 'client_type', 'expires_at', 'last_used_at'])]
class ClientToken extends Model
{
    /** Client tokens live this long without use before expiring. */
    public const LIFETIME_DAYS = 30;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function issue(User $user, array $deviceInfo = []): self
    {
        return static::create([
            'user_id' => $user->id,
            'token' => Str::random(48),
            'device_id' => $deviceInfo['id'] ?? null,
            'device_uuid' => $deviceInfo['uuid'] ?? null,
            'device_os' => $deviceInfo['os'] ?? null,
            'device_name' => $deviceInfo['name'] ?? null,
            'client_type' => $deviceInfo['type'] ?? null,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);
    }

    public function isValid(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
