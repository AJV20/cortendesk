<?php

namespace App\Http\Controllers\AdminApi;

use App\Models\ConsoleAudit;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserGroupsController extends AdminApiController
{
    /** GET /api/v1/user-groups. */
    public function index(Request $request): JsonResponse
    {
        $groups = UserGroup::query()
            ->withCount('users')
            ->when($request->filled('name'), fn ($q) => $q
                ->where('name', 'like', '%'.$request->query('name').'%'))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->paginated($groups, fn (UserGroup $g) => $this->serialize($g));
    }

    /** GET /api/v1/user-groups/{userGroup} — includes membership. */
    public function show(UserGroup $userGroup): JsonResponse
    {
        return $this->ok($this->serialize($userGroup->loadCount('users'), true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('user_groups', 'name')],
            'note' => ['nullable', 'string'],
        ]);

        $group = UserGroup::create($data);

        ConsoleAudit::record('group.create', 'Created user group '.$group->name.' (API)', 'group', $group->name);

        return $this->created($this->serialize($group->loadCount('users')), 'User group created.');
    }

    public function update(Request $request, UserGroup $userGroup): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('user_groups', 'name')->ignore($userGroup->id)],
            'note' => ['nullable', 'string'],
        ]);

        $userGroup->update($data);

        ConsoleAudit::record('group.update', 'Updated user group '.$userGroup->name.' (API)', 'group', $userGroup->name);

        return $this->ok($this->serialize($userGroup->loadCount('users')), 'User group updated.');
    }

    public function destroy(UserGroup $userGroup): JsonResponse
    {
        $name = $userGroup->name;
        $userGroup->users()->detach();
        $userGroup->delete();

        ConsoleAudit::record('group.delete', 'Deleted user group '.$name.' (API)', 'group', $name);

        return $this->ok(null, 'User group deleted.');
    }

    /** POST /api/v1/user-groups/{userGroup}/members — add by user_id or user_name. */
    public function addMember(Request $request, UserGroup $userGroup): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->fail('User not found.', 404);
        }

        $userGroup->users()->syncWithoutDetaching([$user->id]);

        ConsoleAudit::record('group.update', 'Added '.$user->username.' to user group '.$userGroup->name.' (API)', 'group', $userGroup->name);

        return $this->ok($this->serialize($userGroup->loadCount('users'), true), 'Member added.');
    }

    /** DELETE /api/v1/user-groups/{userGroup}/members — remove by user_id or user_name. */
    public function removeMember(Request $request, UserGroup $userGroup): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->fail('User not found.', 404);
        }

        $userGroup->users()->detach($user->id);

        ConsoleAudit::record('group.update', 'Removed '.$user->username.' from user group '.$userGroup->name.' (API)', 'group', $userGroup->name);

        return $this->ok(null, 'Member removed.');
    }

    private function resolveUser(Request $request): ?User
    {
        if ($request->filled('user_id')) {
            return User::find($request->input('user_id'));
        }
        if ($request->filled('user_name')) {
            return User::where('username', $request->input('user_name'))->first();
        }

        return null;
    }

    private function serialize(UserGroup $group, bool $withMembers = false): array
    {
        $out = [
            'id' => $group->id,
            'name' => $group->name,
            'note' => $group->note,
            'users_count' => $group->users_count ?? $group->users()->count(),
        ];

        if ($withMembers) {
            $out['members'] = $group->users()->orderBy('username')
                ->get(['users.id', 'username'])
                ->map(fn ($u) => ['id' => $u->id, 'username' => $u->username])->all();
        }

        return $out;
    }
}
