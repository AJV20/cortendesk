<?php

namespace App\Livewire;

use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\User;
use Livewire\Component;

class LiveStats extends Component
{
    public function render()
    {
        $user = auth()->user();
        $admin = $user->seesAllDevices();

        $visibleIds = $admin ? null : Device::query()->visibleTo($user)->pluck('rustdesk_id')->all();

        return view('livewire.live-stats', [
            'admin' => $admin,
            'devices' => Device::query()->visibleTo($user)->count(),
            'online' => Device::query()->visibleTo($user)->online()->count(),
            'users' => $admin ? User::count() : null,
            'connectionsToday' => AuditConnection::query()
                ->when($visibleIds !== null, fn ($q) => $q->whereIn('rustdesk_id', $visibleIds))
                ->whereDate('created_at', today())
                ->count(),
        ]);
    }
}
