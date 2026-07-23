<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceGroup extends Model
{
    protected $fillable = ['name', 'note'];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** User groups granted access to this folder. */
    public function userGroups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'device_group_user_group');
    }
}
