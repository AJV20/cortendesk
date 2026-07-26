<?php

namespace App\Console\Commands;

use App\Models\AuditConnection;
use Illuminate\Console\Command;

/**
 * Second half of the stale-session fix (issue #10).
 *
 * SyncController reconciles a device's open sessions against the `conns` list
 * on every heartbeat, which covers every case where the machine is still
 * running: the remote peer rebooted, the network dropped, the client crashed.
 *
 * It cannot cover the case where the DEVICE ITSELF stops heartbeating — powered
 * off, unplugged, service stopped. No heartbeat means nothing to reconcile
 * against, and the session would sit Active indefinitely. This sweep closes
 * those on a timer instead.
 *
 * Runs every five minutes rather than daily like the other housekeeping
 * commands: a stale Active session is visible on the dashboard and misleads an
 * operator about what is happening on their network right now, so a once-a-day
 * cleanup would not fix the complaint.
 */
class CloseStaleSessions extends Command
{
    protected $signature = 'cortendesk:close-stale-sessions';

    protected $description = 'Close active sessions belonging to devices that have stopped heartbeating';

    public function handle(): int
    {
        $closed = AuditConnection::closeStaleSessions();

        $this->info($closed === 0
            ? 'No stale sessions.'
            : "Closed {$closed} stale session(s) on silent devices.");

        return self::SUCCESS;
    }
}
