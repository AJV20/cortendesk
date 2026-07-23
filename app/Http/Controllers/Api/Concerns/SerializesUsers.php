<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\User;

trait SerializesUsers
{
    /**
     * UserPayload shape per docs/client-api.md §5.
     * `info` must always be an object; `is_admin` a real boolean.
     */
    protected function userPayload(User $user): array
    {
        return [
            'name' => $user->username,
            'display_name' => $user->displayName(),
            'email' => (string) $user->email,
            'note' => (string) $user->note,
            'status' => $user->is_active ? 1 : 0,
            'is_admin' => (bool) $user->is_admin,
            'info' => (object) [],
        ];
    }
}
