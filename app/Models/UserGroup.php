<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'note'])]
class UserGroup extends Model
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_group_user');
    }

    /** Device groups (folders) every member of this user group may see. */
    public function deviceGroups(): BelongsToMany
    {
        return $this->belongsToMany(DeviceGroup::class, 'device_group_user_group');
    }

    /**
     * Device-group ids that membership of THIS group hands out: the folder
     * picker (device_group_user_group) plus every "accessed from" grant naming
     * this group as the accessor (group_accesses).
     *
     * This is the same union User::accessibleDeviceGroupIds() computes from the
     * member's side, asked from the group's side instead — which is what a
     * caller needs to answer "would putting someone in this group let them see
     * more than I can?" (PLAN D4 escalation clamp).
     *
     * @return array<int,int>
     */
    public function grantedDeviceGroupIds(): array
    {
        $direct = $this->deviceGroups()->pluck('device_groups.id');

        $viaAccess = GroupAccess::query()
            ->where('target_type', GroupAccess::TARGET_DEVICE_GROUP)
            ->where('accessor_type', GroupAccess::ACCESSOR_USER_GROUP)
            ->where('accessor_id', $this->id)
            ->pluck('target_id');

        return $direct->merge($viaAccess)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * User-group ids "accessed from" this group (PLAN B4): user groups whose
     * members may see THIS group's members. Stored as group_accesses rows with
     * accessor = each of those groups, target = this group.
     *
     * @return array<int,int>
     */
    public function accessorUserGroupIds(): array
    {
        return GroupAccess::query()
            ->where('target_type', GroupAccess::TARGET_USER_GROUP)
            ->where('target_id', $this->id)
            ->where('accessor_type', GroupAccess::ACCESSOR_USER_GROUP)
            ->pluck('accessor_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Replace the set of user groups that may access this group's members. */
    public function syncAccessorUserGroups(array $userGroupIds): void
    {
        GroupAccess::syncAccessors(
            GroupAccess::TARGET_USER_GROUP,
            $this->id,
            array_map('intval', $userGroupIds),
        );
    }
}
