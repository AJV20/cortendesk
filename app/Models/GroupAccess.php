<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Group↔group visibility grant (PLAN B4): an accessor (user group | user) may
 * see into a target (user group | device group). See the migration for the
 * full semantics. Subject types are stored as short slugs, not morph classes,
 * to keep the client/console query paths simple and explicit.
 */
#[Fillable(['accessor_type', 'accessor_id', 'target_type', 'target_id'])]
class GroupAccess extends Model
{
    public const ACCESSOR_USER_GROUP = 'user_group';

    public const ACCESSOR_USER = 'user';

    public const TARGET_USER_GROUP = 'user_group';

    public const TARGET_DEVICE_GROUP = 'device_group';

    /**
     * Replace the set of USER-GROUP accessors granted access to a target,
     * leaving any non-user-group accessor rows (e.g. per-user grants) untouched.
     *
     * @param  array<int,int>  $userGroupIds
     */
    public static function syncAccessors(string $targetType, int $targetId, array $userGroupIds): void
    {
        $userGroupIds = array_values(array_unique(array_map('intval', $userGroupIds)));

        static::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('accessor_type', self::ACCESSOR_USER_GROUP)
            ->whereNotIn('accessor_id', $userGroupIds ?: [0])
            ->delete();

        foreach ($userGroupIds as $id) {
            static::firstOrCreate([
                'accessor_type' => self::ACCESSOR_USER_GROUP,
                'accessor_id' => $id,
                'target_type' => $targetType,
                'target_id' => $targetId,
            ]);
        }
    }

    /** Remove every grant that references a group as accessor OR target. */
    public static function purgeFor(string $type, int $id): void
    {
        static::query()
            ->where(fn ($q) => $q->where('accessor_type', $type)->where('accessor_id', $id))
            ->orWhere(fn ($q) => $q->where('target_type', $type)->where('target_id', $id))
            ->delete();
    }
}
