<?php

namespace App\Livewire;

use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\LoginLog;
use App\Models\User;
use Livewire\Component;

class LiveStats extends Component
{
    public function render()
    {
        $user = auth()->user();
        $admin = $user->seesAllDevices();

        $visibleIds = $admin ? null : Device::query()->visibleTo($user)->pluck('rustdesk_id')->all();

        $devices = Device::query()->visibleTo($user)->count();
        $online = Device::query()->visibleTo($user)->online()->count();

        // Fleet growth: devices first seen in the last 14 days against the 14
        // days before that. Both halves are counted; nothing is extrapolated.
        $newDevices = Device::query()->visibleTo($user)
            ->where('created_at', '>=', now()->subDays(14))
            ->count();
        $priorDevices = Device::query()->visibleTo($user)
            ->whereBetween('created_at', [now()->subDays(28), now()->subDays(14)])
            ->count();

        $connections = fn () => AuditConnection::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('rustdesk_id', $visibleIds));

        $connectionsToday = $connections()->whereDate('created_at', today())->count();
        $connectionsYesterday = $connections()->whereDate('created_at', today()->subDay())->count();

        // Deliberately the same predicate as App\Livewire\ActiveSessions, so the
        // tile and the table underneath it can never disagree.
        $sessions = $connections()
            ->whereNull('closed_at')
            ->whereNotNull('from_peer')
            ->where('from_peer', '!=', '')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $alarms = fn () => AlarmLog::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('rustdesk_id', $visibleIds))
            ->where('created_at', '>=', now()->subDay());

        $alarms24h = $alarms()->count();

        return view('livewire.live-stats', [
            'admin' => $admin,
            'devices' => $devices,
            'online' => $online,
            'onlinePct' => $devices > 0 ? (int) round($online / $devices * 100) : null,
            'deviceTrend' => $newDevices - $priorDevices,
            'users' => $admin ? User::count() : null,
            // Console sign-ins only ('web' = password/invite/reset, 'sso' = OIDC).
            // Client logins are a different population and would inflate this.
            'usersToday' => $admin
                ? LoginLog::query()
                    ->where('successful', true)
                    ->whereIn('client', ['web', 'sso'])
                    ->whereDate('created_at', today())
                    ->distinct()
                    ->count('user_id')
                : null,
            'connectionsToday' => $connectionsToday,
            'connectionTrend' => $connectionsToday - $connectionsYesterday,
            'sessions' => $sessions,
            'alarms24h' => $alarms24h,
            'lastAlarmAt' => $alarms24h > 0 ? $alarms()->latest('id')->value('created_at') : null,
        ]);
    }
}
