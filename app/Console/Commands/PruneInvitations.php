<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use App\Models\TrustedDevice;
use Illuminate\Console\Command;

/**
 * Housekeeping for PLAN D1: drop dead invitations and expired device trust.
 *
 * Deliberately NOT folded into cortendesk:prune-logs — that command returns
 * early when log retention is 0 (a supported, common configuration), which
 * would silently disable this sweep. Expiry is enforced at redemption in any
 * case; this is hygiene, not a security control.
 */
class PruneInvitations extends Command
{
    protected $signature = 'cortendesk:prune-invitations {--days=30 : Keep accepted invitations this long}';

    protected $description = 'Delete expired/old invitations and expired trusted-device records';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));

        $expired = Invitation::query()
            ->whereNull('accepted_at')
            ->where('expires_at', '<', now())
            ->delete();

        $accepted = Invitation::query()
            ->whereNotNull('accepted_at')
            ->where('accepted_at', '<', now()->subDays($days))
            ->delete();

        $devices = TrustedDevice::query()
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Pruned {$expired} expired and {$accepted} accepted invitation(s), {$devices} trusted device(s).");

        return self::SUCCESS;
    }
}
