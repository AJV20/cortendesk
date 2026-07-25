<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\MailSettings;
use Illuminate\Console\Command;

/**
 * Break-glass for emailed sign-in verification.
 *
 * With verification on and the relay down, ordinary users cannot sign in — by
 * design. An administrator can still get in and repair it from the console, but
 * if nobody with settings access can reach a mailbox either, this is the way
 * out without touching the database by hand.
 *
 * Mirrors cortendesk:2fa-reset, which is the same idea for a locked-out user.
 */
class EmailVerification extends Command
{
    protected $signature = 'cortendesk:email-verification {state? : on, off, or omit to show the current state}';

    protected $description = 'Turn emailed sign-in verification on or off, or report relay health';

    public function handle(MailSettings $mail): int
    {
        $state = strtolower((string) $this->argument('state'));

        if ($state === '') {
            $on = Setting::get('email_login_verification', '0') === '1';
            $this->line('Sign-in verification: '.($on ? 'ON' : 'off'));
            $this->line('SMTP configured:      '.($mail->isEnabled() ? 'yes' : 'no'));
            $this->line('Relay health:         '.($mail->isHealthy() ? 'ok' : 'FAILING'));

            if ($error = $mail->lastError()) {
                $this->line('Last error:           '.$error);
            }

            if ($on && ! $mail->isHealthy()) {
                $this->warn('Users cannot sign in. Run "cortendesk:email-verification off" to reopen the console.');
            }

            return self::SUCCESS;
        }

        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('State must be "on" or "off".');

            return self::FAILURE;
        }

        Setting::put('email_login_verification', $state === 'on' ? '1' : '0');

        if ($state === 'off') {
            // Clear the outage too: leaving it set would keep an administrator
            // pinned to the settings screen for a control that is now off.
            Setting::put('smtp_failed_at', '');
            $this->info('Sign-in verification is OFF. Password sign-in works normally again.');
        } else {
            $this->info('Sign-in verification is ON.');

            if (! $mail->isEnabled()) {
                $this->warn('SMTP is not configured, so no codes can be sent — only administrators will be able to sign in.');
            }
        }

        return self::SUCCESS;
    }
}
