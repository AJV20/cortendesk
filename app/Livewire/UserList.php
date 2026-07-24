<?php

namespace App\Livewire;

use App\Models\ConsoleAudit;
use App\Models\DeviceGroup;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class UserList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $role = 'all';

    #[Url(except: 'all')]
    public string $status = 'all';

    public int $perPage = 20;

    // Modal state — null means "create", otherwise id of user being edited.
    public bool $showModal = false;

    public ?int $editing = null;

    public string $username = '';

    public string $name = '';

    public string $email = '';

    /** @var array<int,int> user_group ids this user belongs to */
    public array $user_group_ids = [];

    public bool $is_admin = false;

    public bool $is_active = true;

    public string $password = '';

    /** @var array<int,int> device_group ids this user may access (non-admins) */
    public array $device_group_ids = [];

    // "Assign devices" modal — bulk-set the owner of several devices at once.
    public bool $showAssignModal = false;

    public ?int $assignUserId = null;

    public string $assignSearch = '';

    /** @var array<int,int> device ids selected in the assign modal */
    public array $assignDeviceIds = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'role', 'status');
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $user = User::findOrFail($id);

        $this->resetForm();
        $this->editing = $user->id;
        $this->username = $user->username;
        $this->name = $user->name ?? '';
        $this->email = $user->email ?? '';
        $this->user_group_ids = $user->groups()->pluck('user_groups.id')->all();
        $this->is_admin = $user->is_admin;
        $this->is_active = $user->is_active;
        $this->device_group_ids = $user->deviceGroups()->pluck('device_groups.id')->all();
        $this->showModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($this->editing)],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editing)],
            'user_group_ids' => ['array'],
            'user_group_ids.*' => [Rule::exists('user_groups', 'id')],
            'is_admin' => ['boolean'],
            'is_active' => ['boolean'],
            'password' => [$this->editing ? 'nullable' : 'required', 'string', 'min:8'],
            'device_group_ids' => ['array'],
            'device_group_ids.*' => [Rule::exists('device_groups', 'id')],
        ]);

        $groupIds = $validated['device_group_ids'] ?? [];
        $userGroupIds = $validated['user_group_ids'] ?? [];
        unset($validated['device_group_ids'], $validated['user_group_ids']);

        $validated['name'] = $validated['name'] ?? null;
        $validated['email'] = ($validated['email'] ?? '') !== '' ? $validated['email'] : null;

        if ($this->editing) {
            // Blank password on edit = keep the current one.
            if (($validated['password'] ?? '') === '') {
                unset($validated['password']);
            }
            $user = User::findOrFail($this->editing);
            $user->update($validated);
        } else {
            $user = User::create($validated);
        }

        // Admins see everything, so device-group grants only apply to non-admins.
        $user->deviceGroups()->sync($validated['is_admin'] ? [] : $groupIds);

        $user->groups()->sync($userGroupIds);

        ConsoleAudit::record(
            $this->editing ? 'user.update' : 'user.create',
            ($this->editing ? 'Updated' : 'Created').' user '.$user->username,
            'user',
            $user->username,
        );

        $this->closeModal();
    }

    public function toggleActive(int $id): void
    {
        if ($id === auth()->id()) {
            return; // never lock yourself out
        }

        $user = User::findOrFail($id);
        $user->update(['is_active' => ! $user->is_active]);

        // Disabling takes effect NOW, not at next login: revoke everything.
        if (! $user->is_active) {
            $this->revokeAllAccess($user);
        }

        ConsoleAudit::record(
            $user->is_active ? 'user.enable' : 'user.disable',
            ($user->is_active ? 'Enabled' : 'Disabled').' user '.$user->username,
            'user',
            $user->username,
        );
    }

    /** Kick a user everywhere: RustDesk clients, console sessions, remember-me. */
    public function forceLogout(int $id): void
    {
        if ($id === auth()->id()) {
            return; // use the account menu to log yourself out
        }

        $user = User::findOrFail($id);
        $this->revokeAllAccess($user);

        ConsoleAudit::record('user.force-logout', 'Forced logout of '.$user->username, 'user', $user->username);
    }

    private function revokeAllAccess(User $user): void
    {
        $user->clientTokens()->delete();
        \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();
        // A surviving remember-me cookie must not silently re-authenticate.
        $user->setRememberToken(\Illuminate\Support\Str::random(60));
        $user->save();
    }

    public function deleteUser(int $id): void
    {
        if ($id === auth()->id()) {
            return; // cannot delete yourself
        }

        $user = User::findOrFail($id);
        $username = $user->username;

        // Keep the devices, just detach them from the deleted owner.
        $user->devices()->update(['user_id' => null]);
        $user->delete();

        ConsoleAudit::record('user.delete', 'Deleted user '.$username, 'user', $username);
    }

    // --- Assign devices (bulk owner reassignment) ----------------------------

    public function openAssign(int $userId): void
    {
        $this->assignUserId = $userId;
        $this->assignDeviceIds = [];
        $this->assignSearch = '';
        // Preselect the devices this user already owns.
        $this->assignDeviceIds = \App\Models\Device::where('user_id', $userId)->pluck('id')->all();
        $this->showAssignModal = true;
    }

    public function saveAssign(): void
    {
        $user = User::findOrFail($this->assignUserId);
        $ids = array_map('intval', $this->assignDeviceIds);

        // Devices to gain this owner, and this user's current devices to release.
        \App\Models\Device::whereIn('id', $ids)->update(['user_id' => $user->id]);
        \App\Models\Device::where('user_id', $user->id)
            ->whereNotIn('id', $ids ?: [0])
            ->update(['user_id' => null]);

        ConsoleAudit::record(
            'user.assign-devices',
            'Assigned '.count($ids).' device(s) to '.$user->username,
            'user',
            $user->username,
        );

        $this->showAssignModal = false;
    }

    public function closeAssign(): void
    {
        $this->showAssignModal = false;
        $this->reset('assignUserId', 'assignDeviceIds', 'assignSearch');
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'username', 'name', 'email', 'user_group_ids', 'is_admin', 'is_active', 'password', 'device_group_ids');
        $this->resetValidation();
    }

    public function render()
    {
        $users = User::query()
            ->with('groups')
            ->withCount('devices')
            ->when($this->search !== '', function ($q) {
                $s = '%'.$this->search.'%';
                $q->where(function ($q) use ($s) {
                    $q->where('username', 'like', $s)
                        ->orWhere('name', 'like', $s)
                        ->orWhere('email', 'like', $s);
                });
            })
            ->when($this->role === 'admin', fn ($q) => $q->where('is_admin', true))
            ->when($this->role === 'user', fn ($q) => $q->where('is_admin', false))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'disabled', fn ($q) => $q->where('is_active', false))
            ->orderBy('username')
            ->paginate($this->perPage);

        // Candidate devices for the assign modal (filtered by its own search).
        $assignDevices = collect();
        if ($this->showAssignModal) {
            $assignDevices = \App\Models\Device::query()
                ->when($this->assignSearch !== '', function ($q) {
                    $s = '%'.$this->assignSearch.'%';
                    $q->where(fn ($q) => $q->where('rustdesk_id', 'like', $s)
                        ->orWhere('alias', 'like', $s)
                        ->orWhere('hostname', 'like', $s));
                })
                ->orderBy('rustdesk_id')
                ->limit(200)
                ->get(['id', 'rustdesk_id', 'alias', 'hostname', 'user_id']);
        }

        return view('livewire.user-list', [
            'users' => $users,
            'userGroups' => UserGroup::orderBy('name')->get(),
            'deviceGroups' => DeviceGroup::orderBy('name')->get(),
            'assignDevices' => $assignDevices,
        ]);
    }
}
