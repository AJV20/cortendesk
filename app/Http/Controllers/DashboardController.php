<?php

namespace App\Http\Controllers;

use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Non-admins: everything on the dashboard is scoped to the devices
        // they can see. Admins: null = no scope (whole fleet).
        $visibleIds = $user->seesAllDevices()
            ? null
            : Device::query()->visibleTo($user)->pluck('rustdesk_id')->all();

        return view('overview', [
            'connectionSeries' => $this->connectionSeries($visibleIds),
            'platformMix' => $this->platformMix($user),
            'versionCounts' => $this->versionCounts($user),
        ]);
    }

    /**
     * Daily connection counts for the last 14 days, split by type:
     * Remote Control (0), File Transfer (1), Other (port forward, camera, terminal).
     *
     * @param  array<int,string>|null  $visibleIds  null = all devices (admin)
     */
    private function connectionSeries(?array $visibleIds): array
    {
        $from = now()->subDays(13)->startOfDay();

        $rows = AuditConnection::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('rustdesk_id', $visibleIds))
            ->selectRaw('DATE(created_at) as day, conn_type, COUNT(*) as c')
            ->where('created_at', '>=', $from)
            ->groupBy('day', 'conn_type')
            ->get()
            ->groupBy('day');

        $labels = [];
        $remote = [];
        $file = [];
        $other = [];

        for ($i = 0; $i < 14; $i++) {
            $date = $from->copy()->addDays($i);
            $key = $date->toDateString();
            $labels[] = $date->format('M j');

            $byType = $rows->get($key, collect())->keyBy('conn_type');
            $remote[] = (int) ($byType->get(0)->c ?? 0);
            $file[] = (int) ($byType->get(1)->c ?? 0);
            $other[] = (int) $rows->get($key, collect())
                ->reject(fn ($r) => in_array((int) $r->conn_type, [0, 1], true))
                ->sum('c');
        }

        return [
            'labels' => $labels,
            'series' => [
                ['name' => 'Remote Control', 'data' => $remote],
                ['name' => 'File Transfer', 'data' => $file],
                ['name' => 'Other', 'data' => $other],
            ],
        ];
    }

    /** Device platform mix: top 3 platforms + Other (max 4 donut segments). */
    private function platformMix(User $user): array
    {
        $names = [
            'windows' => 'Windows',
            'macos' => 'macOS',
            'linux' => 'Linux',
            'android' => 'Android',
            'ios' => 'iOS',
            'unknown' => 'Unknown',
        ];

        $counts = Device::query()->visibleTo($user)->get()
            ->groupBy(fn (Device $d) => $d->platform())
            ->map->count()
            ->sortDesc();

        $top = $counts->take(3);
        $otherCount = $counts->slice(3)->sum();

        $labels = $top->keys()->map(fn ($k) => $names[$k] ?? ucfirst($k))->values()->all();
        $values = $top->values()->all();

        if ($otherCount > 0) {
            $labels[] = 'Other';
            $values[] = $otherCount;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** Client version distribution (top 6). */
    private function versionCounts(User $user): array
    {
        $rows = Device::query()
            ->visibleTo($user)
            ->selectRaw("COALESCE(NULLIF(version, ''), 'unknown') as v, COUNT(*) as c")
            ->groupBy('v')
            ->orderByDesc('c')
            ->limit(6)
            ->get();

        return [
            'labels' => $rows->pluck('v')->all(),
            'values' => $rows->pluck('c')->map(fn ($c) => (int) $c)->all(),
        ];
    }
}
