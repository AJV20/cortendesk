<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AuditConnection;
use App\Models\ConsoleAudit;
use App\Models\Device;
use Livewire\Component;

class ActiveSessions extends Component
{
    use AuthorizesConsole;

    public function mount(): void
    {
        $this->authorizeConsole('audit', 'r');
    }

    /**
     * Ask the controlled device to close a live session.
     *
     * The console cannot terminate a session directly — it runs peer-to-peer
     * through hbbs/hbbr and never passes through this application. The only
     * channel is the device's next heartbeat, which carries the connection id
     * for the client to close (docs/client-api.md §8). So this records the
     * intent and the session ends within a heartbeat, not instantly.
     */
    public function disconnect(int $id): void
    {
        $this->authorizeConsole('audit', 'r');
        $user = auth()->user();

        $session = AuditConnection::query()
            ->whereKey($id)
            ->whereNull('closed_at')
            ->first();

        if (! $session) {
            return;
        }

        // Same scoping as the list: an operator may only end a session on a
        // device they can see, and only with write access to devices.
        if (! $user->consoleAllows('device', 'rw')) {
            return;
        }

        if (! $user->seesAllDevices()
            && ! Device::query()->visibleTo($user)->where('rustdesk_id', $session->rustdesk_id)->exists()) {
            return;
        }

        $session->forceFill([
            'disconnect_requested_at' => now(),
            'disconnect_sent_at' => null,
            'disconnect_requested_by' => $user->id,
        ])->save();

        ConsoleAudit::record(
            'session.disconnect',
            'Requested disconnect of session '.$session->conn_id.' on '.$session->rustdesk_id,
            'device',
            $session->rustdesk_id,
        );
    }

    public function render()
    {
        // Polls and Livewire updates are separate requests. Re-check so a role
        // losing Logs access stops receiving session data immediately.
        $this->authorizeConsole('audit', 'r');
        $user = auth()->user();
        $visibleIds = $user->seesAllDevices()
            ? null
            : Device::query()->visibleTo($user)->pluck('rustdesk_id')->all();

        $sessions = AuditConnection::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('rustdesk_id', $visibleIds))
            ->whereNull('closed_at')
            ->whereNotNull('from_peer')
            ->where('from_peer', '!=', '')
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')
            ->limit(8)
            ->get();

        return view('livewire.active-sessions', [
            'sessions' => $sessions,
            'canDisconnect' => $user->consoleAllows('device', 'rw'),
            'activeCount' => $sessions->count(),
        ]);
    }
}
