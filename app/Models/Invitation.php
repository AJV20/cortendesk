<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A pending invitation to create a console account (PLAN D1).
 *
 * The plaintext token is returned once from issue() and never persisted — only
 * its sha256 is stored, the same shape as ApiToken. The privileges the inviting
 * admin chose are columns here, so the accept URL cannot carry (or forge) them.
 */
#[Fillable([
    'email', 'username', 'name', 'token_hash', 'is_admin',
    'user_group_ids', 'device_group_ids', 'invited_by',
    'expires_at', 'accepted_at', 'accepted_user_id',
])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    /** Default lifetime of an invitation link, in hours (PLAN D1: 48h). */
    public const DEFAULT_EXPIRY_HOURS = 48;

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'user_group_ids' => 'array',
            'device_group_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    /** Configured link lifetime in hours, clamped to something sane. */
    public static function expiryHours(): int
    {
        $hours = (int) (Setting::get('invite_expiry_hours', (string) self::DEFAULT_EXPIRY_HOURS) ?: self::DEFAULT_EXPIRY_HOURS);

        return max(1, min(720, $hours));
    }

    /**
     * Create an invitation and return [model, plaintext token].
     *
     * @param  array{email:string,username:string,name?:?string,is_admin?:bool,user_group_ids?:array<int,int>,device_group_ids?:array<int,int>}  $attributes
     * @return array{0: self, 1: string}
     */
    public static function issue(array $attributes, ?User $inviter = null): array
    {
        $plain = 'inv_'.Str::random(48);
        $isAdmin = (bool) ($attributes['is_admin'] ?? false);

        $invitation = static::create([
            'email' => $attributes['email'],
            'username' => $attributes['username'],
            'name' => ($attributes['name'] ?? '') !== '' ? $attributes['name'] : null,
            'token_hash' => hash('sha256', $plain),
            'is_admin' => $isAdmin,
            'user_group_ids' => array_values(array_map('intval', $attributes['user_group_ids'] ?? [])),
            // Admins see every device group, so a device-group grant on an admin
            // invite would be dead weight that later reads as a real grant.
            'device_group_ids' => $isAdmin ? [] : array_values(array_map('intval', $attributes['device_group_ids'] ?? [])),
            'invited_by' => $inviter?->id,
            'expires_at' => now()->addHours(static::expiryHours()),
        ]);

        return [$invitation, $plain];
    }

    /** Mint a new token for this invitation and push the expiry out again. */
    public function rotate(): string
    {
        $plain = 'inv_'.Str::random(48);

        $this->forceFill([
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(static::expiryHours()),
        ])->save();

        return $plain;
    }

    /** Resolve a plaintext token to a live (unaccepted, unexpired) invitation. */
    public static function findValid(string $plaintext): ?self
    {
        return static::query()
            ->where('token_hash', hash('sha256', $plaintext))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /** Invitations that can still be accepted. */
    public function scopeLive($query)
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }
}
