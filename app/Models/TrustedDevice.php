<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * A browser that has already cleared an emailed sign-in code (PLAN D1).
 *
 * The cookie holds an opaque random string; only its sha256 lives here. It is
 * NOT an authentication bypass — it only skips the SECOND factor, and only
 * after the password has already been verified.
 */
#[Fillable(['user_id', 'token_hash', 'label', 'ip', 'last_used_at', 'expires_at'])]
#[Hidden(['token_hash'])]
class TrustedDevice extends Model
{
    /** Cookie carrying the plaintext token. Excluded from cookie encryption. */
    public const COOKIE = 'cd_device';

    /** Default number of days a browser stays trusted. */
    public const DEFAULT_DAYS = 30;

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Configured trust window in days, clamped to something sane. */
    public static function trustDays(): int
    {
        $days = (int) (Setting::get('email_trusted_device_days', (string) self::DEFAULT_DAYS) ?: self::DEFAULT_DAYS);

        return max(1, min(365, $days));
    }

    /**
     * Does this request carry a live trust cookie for this user?
     *
     * Sliding: a match pushes the expiry out and re-issues the cookie, so a
     * browser in daily use never has to re-verify, while one that goes quiet
     * for the whole window falls back to a code.
     */
    public static function matches(User $user, Request $request): bool
    {
        $plain = (string) $request->cookie(self::COOKIE);

        if ($plain === '') {
            return false;
        }

        $device = static::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $plain))
            ->where('expires_at', '>', now())
            ->first();

        if (! $device) {
            return false;
        }

        $device->forceFill([
            'last_used_at' => now(),
            'expires_at' => now()->addDays(static::trustDays()),
            'ip' => $request->ip(),
        ])->save();

        static::queueCookie($plain, $request);

        return true;
    }

    /** Trust this browser for the configured window and set the cookie. */
    public static function remember(User $user, Request $request): self
    {
        $plain = Str::random(48);

        $device = static::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'label' => Str::limit((string) $request->userAgent(), 250, ''),
            'ip' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(static::trustDays()),
        ]);

        static::queueCookie($plain, $request);

        return $device;
    }

    /** Queue the trust cookie on the outgoing response. */
    private static function queueCookie(string $plain, Request $request): void
    {
        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $plain,
            minutes: static::trustDays() * 24 * 60,
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }
}
