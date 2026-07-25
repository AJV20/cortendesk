<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class DeviceList extends Component
{
    use AuthorizesConsole, WithPagination;

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

    #[Url(except: false)]
    public bool $pendingTab = false;

    public int $perPage = 20;

    /** Device id being edited, 0 = creating, null = modal closed. */
    public ?int $editingId = null;

    public string $formRustdeskId = '';

    public string $formAlias = '';

    public string $formNote = '';

    public int $formGroupId = 0;

    public int $formUserId = 0;

    /** Device-level strategy assignment (PLAN C4). 0 = none — inherit. */
    public int $formStrategyId = 0;

    /**
     * Devices needs "View" to open (PLAN D4). Which devices are then listed is
     * still decided entirely by Device::scopeVisibleTo — a role never widens
     * the fleet, so device:rw with no device-group grant lists nothing.
     */
    public function mount(): void
    {
        $this->authorizeConsole('device', 'r');
    }

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

    public function updatedPendingTab(): void
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
        $this->authorizeConsole('device', 'rw');

        $this->reset('formRustdeskId', 'formAlias', 'formNote', 'formGroupId', 'formUserId', 'formStrategyId');
        $this->editingId = 0;
    }

    /** Load a device the current user is allowed to see, or fail. */
    private function scopedDevice(int $id): Device
    {
        return Device::withTrashed()->visibleTo(auth()->user())->findOrFail($id);
    }

    /**
     * Load a gate-quarantined (pending) device the current user may act on.
     * Bypasses the approved() filter in scopeVisibleTo but keeps ownership scope.
     */
    private function scopedPendingDevice(int $id): Device
    {
        return Device::query()
            ->ownershipVisibleTo(auth()->user())
            ->pending()
            ->findOrFail($id);
    }

    public function approveDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedPendingDevice($id);
        $device->update(['status' => Device::STATUS_ACTIVE]);
        ConsoleAudit::record('device.approve', 'Approved device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
    }

    public function rejectDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedPendingDevice($id);
        $rustdeskId = $device->rustdesk_id;
        $device->delete(); // reject = soft-delete (quarantined + removed)
        ConsoleAudit::record('device.reject', 'Rejected device '.$rustdeskId, 'device', $rustdeskId);
    }

    public function edit(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedDevice($id);
        $this->editingId = $device->id;
        $this->formRustdeskId = $device->rustdesk_id;
        $this->formAlias = (string) $device->alias;
        $this->formNote = (string) $device->note;
        $this->formGroupId = (int) $device->device_group_id;
        $this->formUserId = (int) $device->user_id;
        // Only admins may see or set strategies; for everyone else the field
        // stays 0 and save() ignores it, so it cannot be posted from a form
        // that never rendered it.
        $this->formStrategyId = auth()->user()?->is_admin
            ? (int) $device->assignedStrategyId()
            : 0;
    }

    public function save(): void
    {
        $this->authorizeConsole('device', 'rw');

        $data = $this->validate([
            'formRustdeskId' => 'required|string|max:100',
            'formAlias' => 'nullable|string|max:255',
            'formNote' => 'nullable|string|max:500',
            'formGroupId' => 'integer',
            'formUserId' => 'integer',
            'formStrategyId' => 'integer',
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

            $this->saveStrategyAssignment($device, (int) $data['formStrategyId']);
        }

        $this->editingId = null;
    }

    /**
     * Device-level strategy assignment from the editor (PLAN C4). Admin-only,
     * and a no-op unless it actually changes: assignTo() recomputes the cached
     * resolution and an unconditional call would audit "changed" for every save.
     */
    private function saveStrategyAssignment(Device $device, int $strategyId): void
    {
        if (! auth()->user()?->is_admin) {
            return;
        }

        $current = (int) $device->assignedStrategyId();
        if ($current === $strategyId) {
            return;
        }

        if ($strategyId !== 0 && ! Strategy::whereKey($strategyId)->exists()) {
            return; // stale option in a form left open while the strategy was deleted
        }

        Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategyId ?: null);

        $name = $strategyId === 0
            ? 'none (inherits)'
            : (string) Strategy::whereKey($strategyId)->value('name');

        ConsoleAudit::record(
            'strategy.assign',
            'Device '.$device->rustdesk_id.' strategy set to '.$name,
            'device',
            $device->rustdesk_id,
        );
    }

    public function closeModal(): void
    {
        $this->editingId = null;
    }

    public function deleteDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedDevice($id);
        $rustdeskId = $device->rustdesk_id;
        $device->delete(); // soft delete → recycle bin
        ConsoleAudit::record('device.delete', 'Deleted device '.$rustdeskId, 'device', $rustdeskId);
    }

    public function restoreDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedDevice($id);
        $device->restore();
        ConsoleAudit::record('device.restore', 'Restored device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
    }

    public function forceDeleteDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedDevice($id);
        $rustdeskId = $device->rustdesk_id;
        $device->forceDelete();
        ConsoleAudit::record('device.destroy', 'Permanently deleted device '.$rustdeskId, 'device', $rustdeskId);
    }

    public function render()
    {
        $user = auth()->user();

        $pendingCount = Device::query()->ownershipVisibleTo($user)->pending()->count();

        if ($this->pendingTab) {
            return $this->renderPending($user, $pendingCount);
        }

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
            'strategies' => $this->editorStrategies(),
            'strategyExplain' => $this->editorStrategyExplain(),
            'totalCount' => Device::visibleTo($user)->count(),
            'onlineCount' => Device::visibleTo($user)->online()->count(),
            'trashedCount' => Device::visibleTo($user)->onlyTrashed()->count(),
            'pendingCount' => $pendingCount,
        ]);
    }

    /**
     * Strategies offered by the editor's assignment select. Empty unless an
     * admin has the editor open on an existing device, so the device list keeps
     * costing exactly what it cost before strategies existed.
     */
    private function editorStrategies()
    {
        if (! auth()->user()?->is_admin || ! $this->editingId) {
            return collect();
        }

        return Strategy::orderBy('name')->get(['id', 'name', 'enabled', 'is_default']);
    }

    /** "Effective strategy" inspector data for the editor (PLAN C4). */
    private function editorStrategyExplain(): ?array
    {
        if (! auth()->user()?->is_admin || ! $this->editingId) {
            return null;
        }

        $device = Device::withTrashed()->visibleTo(auth()->user())->find($this->editingId);

        return $device === null ? null : Strategy::explainFor($device);
    }

    /** Render the "Pending" approval queue (gate-quarantined devices). */
    private function renderPending(User $user, int $pendingCount)
    {
        $devices = Device::query()
            ->ownershipVisibleTo($user)
            ->pending()
            ->with(['group', 'user'])
            ->when($this->search !== '', function ($q) {
                $s = '%'.$this->search.'%';
                $q->where(function ($q) use ($s) {
                    $q->where('rustdesk_id', 'like', $s)
                        ->orWhere('hostname', 'like', $s)
                        ->orWhere('username', 'like', $s)
                        ->orWhere('last_online_ip', 'like', $s);
                });
            })
            ->orderByDesc('created_at')
            ->paginate($this->perPage);

        return view('livewire.device-list-pending', [
            'devices' => $devices,
            'pendingCount' => $pendingCount,
        ]);
    }
}
