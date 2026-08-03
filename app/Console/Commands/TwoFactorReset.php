<?php

namespace App\Console\Commands;

use App\Models\Setting;
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

            // The locked-out-but-nothing-to-reset combination (#18) means the
            // REQUIREMENT is what's in the way, not an enrollment — point at
            // the command that clears that, or this dead end reads as "wipe
            // the database".
            if (Setting::get('two_factor_required', '0') === '1'
                || Setting::get('two_factor_required_admins', '0') === '1') {
                $this->warn('However, this console REQUIRES two-factor authentication, so un-enrolled users are held at the setup screen.');
                $this->line('Run "php artisan cortendesk:2fa-requirement off" to stop requiring it.');
            }

            return self::SUCCESS;
        }

        $user->clearTwoFactor();

        $this->info("Two-factor authentication reset for \"{$username}\". They can now sign in with their password and re-enroll.");

        return self::SUCCESS;
    }
}
