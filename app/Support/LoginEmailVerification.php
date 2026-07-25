<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\MailSettings;
use Illuminate\Http\Request;

/**
 * Should this sign-in be interrupted by an emailed 6-digit code (PLAN D1)?
 *
 * Fails CLOSED when the relay is unreachable, except for operators who can
 * repair it: ordinary users are refused, anyone with settings access is let in
 * and walked to the mail settings by RequireMailHealthy. `cortendesk:email-
 * verification off` is the shell escape hatch when nobody can get in at all. A user with NO ADDRESS no longer reaches this at all: the
 * RequireEmailAddress middleware makes them set one first, so an empty email
 * cannot quietly exempt an account from a control the operator switched on.
 *
 * An SMTP relay that
 * is down, must not be able to lock every non-TOTP operator out of their own
 * console — the same break-glass philosophy as OidcService::localLoginDisabled.
 * The trade is availability over strictness and is called out in the settings
 * help text.
 */
class LoginEmailVerification
{
    /** Is the console-wide switch on and backed by a working mail config? */
    public static function isActive(): bool
    {
        return Setting::get('email_login_verification', '0') === '1'
            && app(MailSettings::class)->isEnabled();
    }

    /**
     * Does this particular sign-in need a code?
     *
     * Never called for users with TOTP enrolled — AuthController hands those to
     * the 2FA challenge instead, so a sign-in raises at most one challenge.
     */
    public static function required(User $user, Request $request): bool
    {
        if (! self::isActive()) {
            return false;
        }

        // No address to send to: let them in rather than strand them.
        if (trim((string) $user->email) === '') {
            return false;
        }

        return ! TrustedDevice::matches($user, $request);
    }

    /** How many accounts the switch cannot protect, for the settings screen. */
    public static function usersWithoutEmail(): int
    {
        return User::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))
            ->count();
    }
}
