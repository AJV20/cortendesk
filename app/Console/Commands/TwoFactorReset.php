<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Break-glass 2FA reset (PLAN B6): clear a locked-out user's TOTP secret,
 * flags, replay pointer and recovery codes so they can sign in with just their
 * password and re-enroll. For an admin who lost their authenticator.
 */
class TwoFactorReset extends Command
{
    protected $signature = 'cortendesk:2fa-reset {username : The username whose 2FA should be reset}';

    protected $description = 'Reset (disable) two-factor authentication for a console user';

    public function handle(): int
    {
        $username = (string) $this->argument('username');
        $user = User::where('username', $username)->first();

        if (! $user) {
            $this->error("No user found with username \"{$username}\".");

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled() && $user->totp_secret === null && $user->recoveryCodes()->doesntExist()) {
            $this->info("User \"{$username}\" does not have two-factor authentication set up. Nothing to do.");

            return self::SUCCESS;
        }

        $user->clearTwoFactor();

        $this->info("Two-factor authentication reset for \"{$username}\". They can now sign in with their password and re-enroll.");

        return self::SUCCESS;
    }
}
