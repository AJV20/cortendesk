<?php

namespace App\Livewire;

use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class DeviceList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: 0)]
    public int $group = 0;

    #[Url(except: 0)]
    public int $owner = 0; // 0 = any, -1 = unassigned, >0 = user id

    #[Url(except: false)]
    public bool $trashed = false;

    public int $perPage = 20;

    /** Device id being edited, 0 = creating, null = modal closed. */
    public ?int $editingId = null;

    public string $formRustdeskId = '';

    public string $formAlias = '';

    public string $formNote = '';

    public int $formGroupId = 0;

    public int $formUserId = 0;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedGroup(): void
    {
        $this->resetPage();
    }

    public function updatedOwner(): void
    {
        $this->resetPage();
    }

    public function updatedTrashed(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'group', 'owner', 'trashed');
        $this->resetPage();
    }

    public function create(): void
    {
        $this->reset('formRustdeskId', 'formAlias', 'formNote', 'formGroupId', 'formUserId');
        $this->editingId = 0;
    }

    /** Load a device the current user is allowed to see, or fail. */
    private function scopedDevice(int $id): Device
    {
        return Device::withTrashed()->visibleTo(auth()->user())->findOrFail($id);
    }

    public function edit(int $id): void
    {
        $device = $this->scopedDevice($id);
        $this->editingId = $device->id;
        $this->formRustdeskId = $device->rustdesk_id;
        $this->formAlias = (string) $device->alias;
        $this->formNote = (string) $device->note;
        $this->formGroupId = (int) $device->device_group_id;
        $this->formUserId = (int) $device->user_id;
    }

    public function save(): void
    {
        $data = $this->validate([
            'formRustdeskId' => 'required|string|max:100',
            'formAlias' => 'nullable|string|max:255',
            'formNote' => 'nullable|string|max:500',
            'formGroupId' => 'integer',
            'formUserId' => 'integer',
        ]);

        $attributes = [
            'alias' => $data['formAlias'] ?: null,
            'note' => $data['formNote'] ?: null,
            'device_group_id' => $data['formGroupId'] ?: null,
            'user_id' => $data['formUserId'] ?: null,
        ];

        if ($this->editingId === 0) {
            $this->validate(['formRustdeskId' => 'unique:devices,rustdesk_id']);
            Device::create($attributes + [
                'rustdesk_id' => $data['formRustdeskId'],
                'uuid' => '',
            ]);
            ConsoleAudit::record('device.create', 'Created device '.$data['formRustdeskId'], 'device', $data['formRustdeskId']);
        } else {
            $device = $this->scopedDevice($this->editingId);
            $device->update($attributes);
            ConsoleAudit::record('device.update', 'Updated device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
        }

        $this->editingId = null;
    }

    public function closeModal(): void
    {
        $this->editingId = null;
    }

    public function deleteDevice(int $id): void
    {
        $device = $this->scopedDevice($id);
        $rustdeskId = $device->rustdesk_id;
        $device->delete(); // soft delete → recycle bin
        ConsoleAudit::record('device.delete', 'Deleted device '.$rustdeskId, 'device', $rustdeskId);
    }

    public function restoreDevice(int $id): void
    {
        $device = $this->scopedDevice($id);
        $device->restore();
        ConsoleAudit::record('device.restore', 'Restored device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
    }

    public function forceDeleteDevice(int $id): void
    {
        $device = $this->scopedDevice($id);
        $rustdeskId = $device->rustdesk_id;
        $device->forceDelete();
        ConsoleAudit::record('device.destroy', 'Permanently deleted device '.$rustdeskId, 'device', $rustdeskId);
    }

    public function render()
    {
        $user = auth()->user();

        $devices = Device::query()
            ->visibleTo($user)
            ->with(['group', 'user'])
            ->when($this->trashed, fn ($q) => $q->onlyTrashed())
            ->when($this->search !== '', function ($q) {
                $s = '%'.$this->search.'%';
                $q->where(function ($q) use ($s) {
                    $q->where('rustdesk_id', 'like', $s)
                        ->orWhere('alias', 'like', $s)
                        ->orWhere('hostname', 'like', $s)
                        ->orWhere('username', 'like', $s)
                        ->orWhere('last_online_ip', 'like', $s);
                });
            })
            ->when(! $this->trashed && $this->status === 'online', fn ($q) => $q->online())
            ->when(! $this->trashed && $this->status === 'offline', fn ($q) => $q->offline())
            ->when($this->group > 0, fn ($q) => $q->where('device_group_id', $this->group))
            ->when($this->owner === -1, fn ($q) => $q->whereNull('user_id'))
            ->when($this->owner > 0, fn ($q) => $q->where('user_id', $this->owner))
            ->orderByRaw('last_online_at IS NULL')
            ->orderByDesc('last_online_at')
            ->paginate($this->perPage);

        $groups = $user->seesAllDevices()
            ? DeviceGroup::orderBy('name')->get()
            : DeviceGroup::whereIn('id', $user->accessibleDeviceGroupIds())->orderBy('name')->get();

        return view('livewire.device-list', [
            'devices' => $devices,
            'groups' => $groups,
            'users' => User::orderBy('username')->get(['id', 'username']),
            'totalCount' => Device::visibleTo($user)->count(),
            'onlineCount' => Device::visibleTo($user)->online()->count(),
            'trashedCount' => Device::visibleTo($user)->onlyTrashed()->count(),
        ]);
    }
}
