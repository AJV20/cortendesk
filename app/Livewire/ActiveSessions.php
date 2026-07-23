<?php

namespace App\Livewire;

use App\Models\AuditConnection;
use App\Models\Device;
use Livewire\Component;

class ActiveSessions extends Component
{
    public function render()
    {
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
            'activeCount' => $sessions->count(),
        ]);
    }
}
