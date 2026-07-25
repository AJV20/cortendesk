<?php

namespace App\Livewire\Concerns;

use App\Models\DeviceGroup;
use App\Models\UserGroup;
use Illuminate\Support\Collection;

/**
 * Console permission checks for Livewire components (PLAN D4).
 *
 * Route middleware only gates the initial page load; /livewire/update is
 * reachable directly, and a single screen commonly mixes read and write, so the
 * component is the authoritative surface. Mount checks the read level, every
 * mutator re-checks the write level.
 */
trait AuthorizesConsole
{
    protected function authorizeConsole(string $resource, string $level = 'r'): void
    {
        abort_unless(auth()->user()?->consoleAllows($resource, $level), 403);
    }

    /** Convenience for views/components that need the answer without aborting. */
    protected function consoleAllows(string $resource, string $level = 'r'): bool
    {
        return (bool) auth()->user()?->consoleAllows($resource, $level);
    }

    /**
     * Drop any device group the actor cannot see themselves (PLAN D4).
     *
     * Row visibility never comes from a role, so "manage users" or "manage
     * groups" must not become a way to hand out — to a puppet account, to a
     * group the actor belongs to, or to anyone else — access the actor does not
     * already hold. A super-admin is unclamped.
     *
     * @param  array<int,mixed>  $ids
     * @return array<int,int>
     */
    protected function grantableDeviceGroupIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $actor = auth()->user();
        if ($actor?->is_admin) {
            return $ids;
        }

        return array_values(array_intersect($ids, $actor?->accessibleDeviceGroupIds() ?? []));
    }

    /**
     * Drop any user group whose membership would grant device groups the actor
     * cannot see (PLAN D4).
     *
     * Without this, the device-group clamp above is trivially bypassed: user
     * groups carry folder grants of their own, so ticking "Finance staff"
     * achieves exactly what ticking the Finance folder is refused for.
     *
     * @param  array<int,mixed>  $ids
     * @return array<int,int>
     */
    protected function grantableUserGroupIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $actor = auth()->user();
        if ($actor?->is_admin || $ids === []) {
            return $ids;
        }

        $allowed = $actor?->accessibleDeviceGroupIds() ?? [];

        return UserGroup::whereIn('id', $ids)->get()
            ->filter(fn (UserGroup $group) => array_diff($group->grantedDeviceGroupIds(), $allowed) === [])
            ->map(fn (UserGroup $group) => (int) $group->id)
            ->values()->all();
    }

    /**
     * Device groups to offer in a grant picker: everything for a super-admin,
     * otherwise only what this actor could actually hand out.
     *
     * @return Collection<int,DeviceGroup>
     */
    protected function grantableDeviceGroups()
    {
        $actor = auth()->user();

        return DeviceGroup::orderBy('name')
            ->when(! $actor?->is_admin, fn ($q) => $q->whereIn('id', $actor?->accessibleDeviceGroupIds() ?: [0]))
            ->get();
    }

    /**
     * User groups to offer in a grant picker, clamped the same way.
     *
     * @return Collection<int,UserGroup>
     */
    protected function grantableUserGroups()
    {
        $groups = UserGroup::orderBy('name')->get();

        if (auth()->user()?->is_admin) {
            return $groups;
        }

        $grantable = $this->grantableUserGroupIds($groups->pluck('id')->all());

        return $groups->whereIn('id', $grantable)->values();
    }
}
