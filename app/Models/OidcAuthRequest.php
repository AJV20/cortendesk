<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One in-flight RustDesk-client SSO authorization (PLAN D3, client half).
 */
#[Fillable([
    'code', 'state', 'nonce', 'verifier', 'op',
    'device_id', 'device_uuid', 'device_os', 'device_name', 'client_type',
    'expires_at',
])]
class OidcAuthRequest extends Model
{
    /** How long the app has to complete the browser flow. Client polls 180s. */
    public const TTL_SECONDS = 300;

    protected function casts(): array
    {
        return [
            'authorized_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Start a flow for a device, generating the code/state/nonce/verifier. */
    public static function start(array $device, ?string $op = null): self
    {
        return static::create([
            'code' => Str::random(40),
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => Str::random(96),
            'op' => $op,
            'device_id' => $device['id'] ?? null,
            'device_uuid' => $device['uuid'] ?? null,
            'device_os' => $device['os'] ?? null,
            'device_name' => $device['name'] ?? null,
            'client_type' => $device['type'] ?? null,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isAuthorized(): bool
    {
        return $this->authorized_at !== null && $this->access_token !== null;
    }

    /**
     * Does this poll come from the device that started the flow?
     *
     * The client always sends both, and a mismatch means the code is being
     * presented by someone else — refuse rather than hand over a token.
     */
    public function belongsToDevice(?string $id, ?string $uuid): bool
    {
        foreach ([[$this->device_id, $id], [$this->device_uuid, $uuid]] as [$expected, $given]) {
            if ($expected !== null && $expected !== '' && ! hash_equals($expected, (string) $given)) {
                return false;
            }
        }

        return true;
    }
}
