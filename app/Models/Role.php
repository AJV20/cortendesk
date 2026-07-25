<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delegated admin role (PLAN D4): a named permission matrix over the console
 * areas, assigned to users through `users.role_id`.
 *
 * A role answers "which screens and which verbs", never "which rows". Device
 * and address-book visibility stays with the existing scoping helpers
 * (Device::scopeVisibleTo, User::accessibleDeviceGroupIds, …), so granting
 * `device: rw` to a user with no device-group access still shows them nothing.
 *
 * `is_admin` remains the super-admin flag and always outranks any role; role
 * administration itself is reserved to it.
 */
#[Fillable(['name', 'description', 'permissions', 'require_two_factor'])]
class Role extends Model
{
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'require_two_factor' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Configured level for a console area (none|r|rw); unknown => none. */
    public function levelFor(string $resource): string
    {
        $level = $this->permissions[$resource] ?? 'none';

        return in_array($level, Permissions::LEVELS, true) ? $level : 'none';
    }

    public function allows(string $resource, string $required = 'r'): bool
    {
        return Permissions::satisfies($this->levelFor($resource), $required);
    }

    /**
     * Fill in missing console areas as "none" and drop unknown keys. Called on
     * every save so a role stored before a resource existed fails closed.
     *
     * @param  array<string,mixed>  $permissions
     * @return array<string,string>
     */
    public static function normalizePermissions(array $permissions): array
    {
        return Permissions::normalize($permissions, Permissions::CONSOLE_RESOURCES);
    }
}
