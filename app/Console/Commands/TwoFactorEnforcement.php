<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Break-glass for the 2FA *requirement* (issue #18). `cortendesk:2fa-reset`
 * clears one user's enrollment, but the lockout GTNeill hit was the other
 * half: the enforcement SETTING was on while nobody had enrolled yet, and no
 * CLI could turn it off — the settings screen is behind the very sign-in the
 * requirement gates. This is the way back in from a shell.
 */
class TwoFactorEnforcement extends Command
{
    protected $signature = 'cortendesk:2fa-requirement {action : "status" to inspect, "off" to stop requiring 2FA}';

    protected $description = 'Show or disable the console-wide two-factor requirement';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        $all = Setting::get('two_factor_required', '0') === '1';
        $admins = Setting::get('two_factor_required_admins', '0') === '1';

        if ($action === 'status') {
            $this->line('Required for all users:      '.($all ? 'yes' : 'no'));
            $this->line('Required for administrators: '.($admins ? 'yes' : 'no'));

            return self::SUCCESS;
        }

        if ($action !== 'off') {
            $this->error('Unknown action "'.$action.'" — use "status" or "off".');

            return self::FAILURE;
        }

        if (! $all && ! $admins) {
            $this->info('Two-factor authentication is not being required. Nothing to do.');

            return self::SUCCESS;
        }

        Setting::put('two_factor_required', '0');
        Setting::put('two_factor_required_admins', '0');

        $this->info('The two-factor requirement is off. Users already enrolled still get their challenge at sign-in.');
        $this->line('Re-enable it in Settings → Security once everyone who needs it has enrolled.');

        return self::SUCCESS;
    }
}
