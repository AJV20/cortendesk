<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class GroupList extends Component
{
    #[Url(except: 'devices')]
    public string $tab = 'devices';

    // Modal state — $editing null means "create".
    public bool $showModal = false;

    public string $modalType = 'devices'; // devices|users

    public ?int $editing = null;

    public string $name = '';

    public string $note = '';

    /** @var array<int,int> device_group ids granted to the user group being edited */
    public array $device_group_ids = [];

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['devices', 'users'], true)) {
            $this->tab = $tab;
        }
    }

    public function create(string $type): void
    {
        $this->resetForm();
        $this->modalType = $this->validType($type);
        $this->showModal = true;
    }

    public function edit(string $type, int $id): void
    {
        $this->resetForm();
        $this->modalType = $this->validType($type);
        $group = $this->model()::findOrFail($id);

        $this->editing = $group->id;
        $this->name = $group->name;
        $this->note = $group->note ?? '';
        if ($group instanceof UserGroup) {
            $this->device_group_ids = $group->deviceGroups()->pluck('device_groups.id')->all();
        }
        $this->showModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'device_group_ids' => ['array'],
            'device_group_ids.*' => [Rule::exists('device_groups', 'id')],
        ]);

        $deviceGroupIds = $validated['device_group_ids'] ?? [];
        unset($validated['device_group_ids']);

        $validated['note'] = ($validated['note'] ?? '') !== '' ? $validated['note'] : null;

        if ($this->editing) {
            $group = $this->model()::findOrFail($this->editing);
            $group->update($validated);
        } else {
            $group = $this->model()::create($validated);
        }

        // Folder access granted to every member of this user group.
        if ($group instanceof UserGroup) {
            $group->deviceGroups()->sync($deviceGroupIds);
        }

        $this->closeModal();
    }

    public function deleteGroup(string $type, int $id): void
    {
        $type = $this->validType($type);

        if ($type === 'devices') {
            Device::where('device_group_id', $id)->update(['device_group_id' => null]);
            DeviceGroup::findOrFail($id)->delete();
        } else {
            $group = UserGroup::findOrFail($id);
            $group->users()->detach();
            $group->deviceGroups()->detach();
            $group->delete();
        }
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'name', 'note', 'device_group_ids');
        $this->resetValidation();
    }

    private function validType(string $type): string
    {
        return $type === 'users' ? 'users' : 'devices';
    }

    /** @return class-string<DeviceGroup|UserGroup> */
    private function model(): string
    {
        return $this->modalType === 'users' ? UserGroup::class : DeviceGroup::class;
    }

    public function render()
    {
        return view('livewire.group-list', [
            'deviceGroups' => DeviceGroup::withCount('devices')->orderBy('name')->get(),
            'userGroups' => UserGroup::withCount('users')->with('deviceGroups')->orderBy('name')->get(),
        ]);
    }
}
