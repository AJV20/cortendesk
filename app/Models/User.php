<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['username', 'name', 'email', 'password', 'avatar', 'is_admin', 'is_active', 'note'])]
#[Hidden(['password', 'remember_token', 'totp_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** User groups this user belongs to (many-to-many). */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_user');
    }

    /** Device groups this (non-admin) user has been granted console access to. */
    public function deviceGroups(): BelongsToMany
    {
        return $this->belongsToMany(DeviceGroup::class);
    }

    public function displayName(): string
    {
        return $this->name ?: $this->username;
    }

    /** Admins see the whole fleet; everyone else is scoped. */
    public function seesAllDevices(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * Device-group ids this user may see: the UNION of their per-user grants
     * (device_group_user) and the grants of every user group they belong to
     * (device_group_user_group).
     *
     * @return array<int,int>
     */
    public function accessibleDeviceGroupIds(): array
    {
        $direct = $this->deviceGroups()->pluck('device_groups.id');

        $viaGroups = DB::table('device_group_user_group')
            ->join('user_group_user', 'user_group_user.user_group_id', '=', 'device_group_user_group.user_group_id')
            ->where('user_group_user.user_id', $this->id)
            ->pluck('device_group_user_group.device_group_id');

        return $direct->merge($viaGroups)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
