<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use password reset link.
 *
 * The plaintext token exists only in the email; the row keeps its sha256 hash.
 */
#[Fillable(['user_id', 'token_hash', 'requested_ip', 'expires_at'])]
class PasswordReset extends Model
{
    /** Reset links are short-lived — long enough to reach an inbox, no more. */
    public const TTL_MINUTES = 60;

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issue a link for a user, invalidating any earlier outstanding one.
     *
     * @return array{0: self, 1: string} the row and the plaintext token
     */
    public static function issue(User $user, ?string $ip = null): array
    {
        // One live link per account: requesting a new one must retire the old,
        // or an attacker who triggered a reset earlier keeps a usable token.
        static::query()->where('user_id', $user->id)->whereNull('used_at')
            ->update(['used_at' => now()]);

        $plain = 'rst_'.Str::random(48);

        $reset = static::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'requested_ip' => $ip,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return [$reset, $plain];
    }

    /** Resolve a plaintext token to a live (unused, unexpired) reset. */
    public static function findValid(string $plaintext): ?self
    {
        return static::query()
            ->where('token_hash', hash('sha256', $plaintext))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Burn this link.
     *
     * The conditional update is what makes it single-use under concurrency:
     * two submissions of the same link cannot both come back with a row.
     */
    public function claim(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }
}
