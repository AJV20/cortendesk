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
}
