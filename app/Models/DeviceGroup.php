<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceGroup extends Model
{
    protected $fillable = ['name', 'note'];

    /**
     * Deleting a folder drops its strategy assignment (pivot cascade) and, in
     * the console, detaches its devices with a query-builder update that fires
     * no model events — so re-resolve the whole fleet rather than guess which
     * devices used to live here.
     */
    protected static function booted(): void
    {
        static::deleted(fn () => Strategy::recomputeAll());
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** User groups granted access to this folder. */
    public function userGroups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'device_group_user_group');
    }

    /**
     * User-group ids "accessed from" this folder (PLAN B4): user groups whose
     * members may see this folder's devices, stored in group_accesses. This is
     * the symmetric editor for a device group; it unions with the folder picker
     * on the user-group editor (device_group_user_group) in
     * User::accessibleDeviceGroupIds().
     *
     * @return array<int,int>
     */
    public function accessorUserGroupIds(): array
    {
        return GroupAccess::query()
            ->where('target_type', GroupAccess::TARGET_DEVICE_GROUP)
            ->where('target_id', $this->id)
            ->where('accessor_type', GroupAccess::ACCESSOR_USER_GROUP)
            ->pluck('accessor_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Replace the set of user groups that may access this folder's devices. */
    public function syncAccessorUserGroups(array $userGroupIds): void
    {
        GroupAccess::syncAccessors(
            GroupAccess::TARGET_DEVICE_GROUP,
            $this->id,
            array_map('intval', $userGroupIds),
        );
    }
}
