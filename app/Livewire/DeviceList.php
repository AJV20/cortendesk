<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AddressBook;
use App\Models\AddressBookRule;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class DeviceList extends Component
{
    use AuthorizesConsole, WithPagination;

    protected string $paginationTheme = 'bootstrap';

    /**
     * Optional table columns (issue #16), in render order. ID, Status and
     * Action are not here — they are the row's identity and controls, and a
     * table without them is not a device list. 'owner' is admin-only.
     */
    public const COLUMNS = [
        'device' => 'Device',
        'alias' => 'Alias',
        'group' => 'Group',
        'owner' => 'Owner',
        'version' => 'Version',
        'username' => 'User',
        'ip' => 'IP',
        'cpu' => 'CPU',
        'memory' => 'Memory',
        'uuid' => 'UUID',
        'first_seen' => 'First Seen',
        'last_seen' => 'Last Seen',
    ];

    /** What the table showed before it was configurable — and still the default. */
    public const DEFAULT_COLUMNS = ['device', 'alias', 'group', 'owner', 'version', 'last_seen'];

    /**
     * Sort keys accepted from the browser. Values are fixed SQL identifiers,
     * never request data, so sortBy() cannot turn a Livewire payload into SQL.
     */
    public const SORTABLE = [
        'id' => 'devices.rustdesk_id',
        'device' => 'devices.hostname',
        'alias' => 'devices.alias',
        'group' => '__group__',
        'owner' => '__owner__',
        'version' => 'devices.version',
        'first_seen' => 'devices.created_at',
        'last_seen' => 'devices.last_online_at',
        'status' => '__presence__',
    ];

    /** Keys of the columns currently shown (checkbox array binding). */
    public array $columns = [];

    public bool $columnsOpen = false;

    /** Current deterministic device ordering (issue #27). */
    public string $sortField = 'last_seen';

    public string $sortDirection = 'desc';

    /**
     * Selected device ids for bulk actions (issue #15). Checkbox values arrive
     * as strings; every consumer re-scopes through visibleTo, so a tampered id
     * selects nothing. Selection means the CURRENT page — it clears on any
     * filter, search or page change, so it can never silently span rows the
     * operator is not looking at.
     *
     * @var array<int, string>
     */
    public array $selected = [];

    /** "Add to Address Book" picker (issue #15). */
    public bool $abPickerOpen = false;

    public int $abBookId = 0;

    /** One-line outcome of the last bulk action ("Added 4, 2 already there"). */
    public string $bulkResult = '';

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

        $saved = auth()->user()?->devices_columns;
        $this->columns = is_array($saved)
            ? array_values(array_intersect(array_keys(self::COLUMNS), $saved))
            : self::DEFAULT_COLUMNS;

        $savedSort = (string) (auth()->user()?->devices_sort ?? '');
        $savedDirection = (string) (auth()->user()?->devices_sort_direction ?? '');
        if ($this->canSortBy($savedSort)) {
            $this->sortField = $savedSort;
            $this->sortDirection = in_array($savedDirection, ['asc', 'desc'], true)
                ? $savedDirection
                : 'asc';
        }
    }

    /** Select a column, or reverse it when the current column is selected. */
    public function sortBy(string $field): void
    {
        if (! $this->canSortBy($field)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        auth()->user()->forceFill([
            'devices_sort' => $this->sortField,
            'devices_sort_direction' => $this->sortDirection,
        ])->save();

        $this->resetPage();
        $this->clearSelection();
    }

    /** Choose a field on mobile without also reversing its direction. */
    public function selectSort(string $field): void
    {
        if (! $this->canSortBy($field) || $this->sortField === $field) {
            return;
        }

        $this->sortField = $field;
        $this->sortDirection = 'asc';
        auth()->user()->forceFill([
            'devices_sort' => $this->sortField,
            'devices_sort_direction' => $this->sortDirection,
        ])->save();
        $this->resetPage();
        $this->clearSelection();
    }

    private function canSortBy(string $field): bool
    {
        return isset(self::SORTABLE[$field])
            && ($field !== 'owner' || auth()->user()?->is_admin);
    }

    /** Persist the column selection so it survives sign-out (issue #16). */
    public function updatedColumns(): void
    {
        // Canonical order, known keys only — the checkbox array arrives in
        // click order and a stale browser tab can post removed keys.
        $this->columns = array_values(array_intersect(array_keys(self::COLUMNS), $this->columns));

        auth()->user()->forceFill(['devices_columns' => $this->columns])->save();
    }

    public function resetColumns(): void
    {
        $this->columns = self::DEFAULT_COLUMNS;
        auth()->user()->forceFill(['devices_columns' => null])->save();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedGroup(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedOwner(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedTrashed(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedPendingTab(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedPaginators($page, $pageName): void
    {
        $this->clearSelection();
    }

    public function updatedSelected(): void
    {
        $this->bulkResult = '';
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'group', 'owner', 'trashed');
        $this->resetPage();
        $this->clearSelection();
    }

    /* ---------------------------------------------------------------------
     | Bulk selection + actions (issue #15)
     * ------------------------------------------------------------------- */

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->bulkResult = '';
        $this->abPickerOpen = false;
    }

    /** Header checkbox: select every row on the current page. */
    public function selectPage(): void
    {
        $this->selected = $this->filteredQuery(auth()->user())
            ->paginate($this->perPage)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $this->bulkResult = '';
    }

    /** The selected devices this user is actually allowed to touch. */
    private function selectedDevices()
    {
        return Device::query()
            ->visibleTo(auth()->user())
            ->whereIn('id', array_map('intval', $this->selected))
            ->get();
    }

    public function bulkDelete(): void
    {
        $this->authorizeConsole('device', 'rw');

        $devices = $this->selectedDevices();
        foreach ($devices as $device) {
            $device->delete();
            ConsoleAudit::record('device.delete', 'Deleted device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
        }

        $count = $devices->count();
        $this->clearSelection();
        $this->bulkResult = $count.' '.Str::plural('device', $count).' moved to the recycle bin.';
    }

    public function openAbPicker(): void
    {
        if ($this->selected === []) {
            return;
        }

        // Ensure the personal book exists so the picker always has at least
        // one target. User-initiated (a click), so the lazy create is fine.
        AddressBook::personalFor(auth()->user());

        $this->abBookId = 0;
        $this->abPickerOpen = true;
    }

    public function closeAbPicker(): void
    {
        $this->abPickerOpen = false;
    }

    public function addSelectedToBook(): void
    {
        $book = $this->writableBooks()->firstWhere('id', $this->abBookId);
        if (! $book) {
            $this->addError('abBookId', 'Pick an address book.');

            return;
        }

        $existing = $book->entries()->pluck('rustdesk_id')->all();
        $added = 0;
        $skipped = 0;

        foreach ($this->selectedDevices() as $device) {
            if (in_array($device->rustdesk_id, $existing, true)) {
                $skipped++;

                continue;
            }

            $book->entries()->create([
                'rustdesk_id' => $device->rustdesk_id,
                'alias' => $device->alias ?: null,
                'hostname' => $device->hostname ?: null,
                // RustDesk-style name ("Windows", "Mac OS") — what clients
                // sync into books and match icons against. NOT platform(),
                // whose lowercase slugs are console-internal.
                'platform' => $device->rustdeskPlatform(),
                'username' => $device->username ?: null,
                'tag_ids' => [],
            ]);
            $existing[] = $device->rustdesk_id;
            $added++;
        }

        ConsoleAudit::record(
            'address-book.peer-add',
            'Added '.$added.' '.Str::plural('device', $added).' to address book '.$book->name.' from the device list',
            'address-book',
            $book->name,
        );

        $this->abPickerOpen = false;
        $this->selected = [];
        $this->bulkResult = 'Added '.$added.' to '.$book->name.'.'
            .($skipped > 0 ? ' '.$skipped.' already there.' : '');
    }

    /**
     * Books the current user may add entries to: their personal book plus any
     * shared book where their tier is read-write or better. permissionFor is
     * the same source of truth the client AB API enforces — the device screen
     * gets no wider a reach than the address-book screen would give.
     */
    private function writableBooks()
    {
        $user = auth()->user();

        return AddressBook::query()
            ->with('rules')
            ->orderByDesc('is_personal')
            ->orderBy('name')
            ->get()
            ->filter(fn (AddressBook $b) => $b->permissionFor($user) >= AddressBookRule::PERM_READ_WRITE)
            ->values();
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

        $devices = $this->filteredQuery($user)->paginate($this->perPage);

        // Which optional columns render, keyed for the blade. Owner stays
        // admin-only regardless of what a saved selection claims.
        $cols = [];
        foreach (array_keys(self::COLUMNS) as $key) {
            $cols[$key] = in_array($key, $this->columns, true)
                && ($key !== 'owner' || $user->is_admin);
        }

        $groups = $user->seesAllDevices()
            ? DeviceGroup::orderBy('name')->get()
            : DeviceGroup::whereIn('id', $user->accessibleDeviceGroupIds())->orderBy('name')->get();

        return view('livewire.device-list', [
            'devices' => $devices,
            'cols' => $cols,
            // Select + ID + Status + Action + the visible optional columns.
            'colspan' => 4 + count(array_filter($cols)),
            'books' => $this->abPickerOpen ? $this->writableBooks() : collect(),
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
     * The list the screen is showing right now — scope, filters and order —
     * shared by render() and the CSV export so the two can never disagree
     * about what "the current view" means.
     */
    private function filteredQuery(User $user)
    {
        $query = Device::query()
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
            ->when($this->owner > 0, fn ($q) => $q->where('user_id', $this->owner));

        return $this->applySort($query);
    }

    /** Apply a validated sort with nulls last and a stable ID tie-breaker. */
    private function applySort($query)
    {
        $field = $this->canSortBy($this->sortField) ? $this->sortField : 'last_seen';
        $direction = in_array($this->sortDirection, ['asc', 'desc'], true)
            ? $this->sortDirection
            : 'desc';

        if ($field === 'status') {
            $cutoff = now()->subSeconds(Device::onlineWindow());
            $query->orderByRaw(
                "CASE WHEN devices.last_online_at > ? THEN 1 ELSE 0 END {$direction}",
                [$cutoff],
            );
        } elseif ($field === 'group') {
            $name = DeviceGroup::query()
                ->select('name')
                ->whereColumn('device_groups.id', 'devices.device_group_id');
            $query->orderByRaw('devices.device_group_id IS NULL')
                ->orderBy($name, $direction);
        } elseif ($field === 'owner') {
            $username = User::query()
                ->select('username')
                ->whereColumn('users.id', 'devices.user_id');
            $query->orderByRaw('devices.user_id IS NULL')
                ->orderBy($username, $direction);
        } else {
            $column = self::SORTABLE[$field];
            if (in_array($field, ['device', 'alias', 'version'], true)) {
                $query->orderByRaw("CASE WHEN {$column} IS NULL OR {$column} = '' THEN 1 ELSE 0 END");
            } elseif ($field === 'last_seen') {
                $query->orderByRaw("{$column} IS NULL");
            }
            $query->orderBy($column, $direction);
        }

        return $query->orderBy('devices.rustdesk_id');
    }

    /**
     * Stream the current view as CSV (issue #16). The first fifteen headers
     * match lejianwen/rustdesk-api's device export byte for byte so tooling
     * built against that format keeps working; CortenDesk-specific columns
     * are appended after. Scoping is the list's own: a non-admin exports only
     * the devices they can see.
     */
    public function exportCsv()
    {
        $this->authorizeConsole('device', 'r');

        $rows = $this->filteredQuery(auth()->user())->get();

        ConsoleAudit::record('device.export', 'Exported '.$rows->count().' devices to CSV', 'device', '');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'row_id', 'id', 'cpu', 'hostname', 'memory', 'os', 'username', 'uuid',
                'version', 'last_online_time', 'last_online_ip', 'group_id', 'alias',
                'created_at', 'updated_at',
                'group_name', 'owner', 'status', 'note',
            ]);
            foreach ($rows as $d) {
                fputcsv($out, [
                    $d->id,
                    $d->rustdesk_id,
                    $d->cpu,
                    $d->hostname,
                    $d->memory,
                    $d->os,
                    $d->username,
                    $d->uuid,
                    $d->version,
                    $d->last_online_at?->toDateTimeString(),
                    $d->last_online_ip,
                    $d->device_group_id,
                    $d->alias,
                    $d->created_at?->toDateTimeString(),
                    $d->updated_at?->toDateTimeString(),
                    $d->group?->name,
                    $d->user?->username,
                    $d->trashed() ? 'disabled' : ($d->isPending() ? 'pending' : 'active'),
                    $d->note,
                ]);
            }
            fclose($out);
        }, 'devices.csv');
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
