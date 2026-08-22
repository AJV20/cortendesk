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
        $canAudit = $user->consoleAllows('audit', 'r');

        $visibleIds = ! $canAudit || $admin
            ? null
            : Device::query()->visibleTo($user)->pluck('rustdesk_id')->all();

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

        $connectionsToday = $canAudit ? $connections()->whereDate('created_at', today())->count() : null;
        $connectionsYesterday = $canAudit ? $connections()->whereDate('created_at', today()->subDay())->count() : null;

        // Deliberately the same predicate as App\Livewire\ActiveSessions, so the
        // tile and the table underneath it can never disagree.
        $sessions = $canAudit
            ? $connections()
                ->whereNull('closed_at')
                ->whereNotNull('from_peer')
                ->where('from_peer', '!=', '')
                ->where('created_at', '>=', now()->subDay())
                ->count()
            : null;

        $alarms = fn () => AlarmLog::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('rustdesk_id', $visibleIds))
            ->where('created_at', '>=', now()->subDay());

        $alarms24h = $canAudit ? $alarms()->count() : null;

        return view('livewire.live-stats', [
            'admin' => $admin,
            'canAudit' => $canAudit,
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
            'connectionTrend' => $canAudit ? $connectionsToday - $connectionsYesterday : null,
            'sessions' => $sessions,
            'alarms24h' => $alarms24h,
            'lastAlarmAt' => $canAudit && $alarms24h > 0 ? $alarms()->latest('id')->value('created_at') : null,
        ]);
    }
}
