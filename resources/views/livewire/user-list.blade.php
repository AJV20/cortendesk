<div>
    <div class="card">
        <div class="card-body">

            {{-- Toolbar --}}
            <div class="row g-2 mb-3 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input type="search" class="form-control" placeholder="Search username, name, email…"
                               wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <select class="form-select" wire:model.live="role">
                        <option value="all">All roles</option>
                        <option value="admin">Administrators</option>
                        <option value="user">Users</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select class="form-select" wire:model.live="status">
                        <option value="all">All statuses</option>
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <button type="button" class="btn btn-light w-100" wire:click="resetFilters">Reset</button>
                </div>
                <div class="col-6 col-md-2 text-md-end">
                    <button type="button" class="btn btn-primary w-100" wire:click="create">
                        <i class="ri-add-line me-1"></i>Add User
                    </button>
                </div>
            </div>

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Group</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Devices</th>
                        <th>Created</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($users as $user)
                        <tr wire:key="u{{ $user->id }}">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary flex-shrink-0"
                                          style="width:32px;height:32px;font-weight:600;">
                                        {{ strtoupper(substr($user->username, 0, 1)) }}
                                    </span>
                                    <div>
                                        <span class="fw-semibold d-block">{{ $user->username }}</span>
                                        <small class="text-muted">{{ $user->name ?: '—' }}</small>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $user->email ?: '—' }}</td>
                            <td>
                                @forelse ($user->groups as $g)
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $g->name }}</span>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td>
                                @if ($user->is_admin)
                                    <span class="badge bg-danger-subtle text-danger">Administrator</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">User</span>
                                @endif
                            </td>
                            <td>
                                @if ($user->is_active)
                                    <span class="badge bg-success-subtle text-success">Active</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning">Disabled</span>
                                @endif
                            </td>
                            <td>{{ $user->devices_count }}</td>
                            <td>
                                <span title="{{ $user->created_at }}">{{ $user->created_at?->format('Y-m-d') }}</span>
                            </td>
                            <td class="text-end">
                                <a href="javascript:void(0);" class="text-primary me-2" wire:click="edit({{ $user->id }})">Edit</a>
                                <a href="javascript:void(0);" class="text-primary me-2" wire:click="openAssign({{ $user->id }})">Devices</a>
                                @if ($user->id === auth()->id())
                                    <span class="text-muted me-2" title="You cannot disable your own account" style="cursor:not-allowed;">
                                        {{ $user->is_active ? 'Disable' : 'Enable' }}
                                    </span>
                                    <span class="text-muted" title="You cannot delete your own account" style="cursor:not-allowed;">Delete</span>
                                @else
                                    <a href="javascript:void(0);" class="text-warning me-2" wire:click="toggleActive({{ $user->id }})">
                                        {{ $user->is_active ? 'Disable' : 'Enable' }}
                                    </a>
                                    <a href="javascript:void(0);" class="text-secondary me-2"
                                       wire:click="forceLogout({{ $user->id }})"
                                       wire:confirm="Force {{ $user->username }} to log out everywhere? This signs out their RustDesk clients and console sessions.">Log out</a>
                                    <a href="javascript:void(0);" class="text-danger"
                                       wire:click="deleteUser({{ $user->id }})"
                                       wire:confirm="Delete user {{ $user->username }}? Their devices will be kept but no longer assigned to any user.">Delete</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No users match your filters.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none">
                @forelse ($users as $user)
                    <div class="card border mb-2" wire:key="mu{{ $user->id }}">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="d-flex align-items-center gap-2 min-width-0">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary flex-shrink-0"
                                          style="width:32px;height:32px;font-weight:600;">
                                        {{ strtoupper(substr($user->username, 0, 1)) }}
                                    </span>
                                    <div class="min-width-0 lh-sm">
                                        <span class="fw-semibold d-block text-truncate">{{ $user->username }}</span>
                                        <small class="text-muted d-block text-truncate">{{ $user->name ?: ($user->email ?: '—') }}</small>
                                    </div>
                                </div>
                                <div class="text-end flex-shrink-0">
                                    @if ($user->is_admin)
                                        <span class="badge bg-danger-subtle text-danger">Admin</span>
                                    @endif
                                    @if ($user->is_active)
                                        <span class="badge bg-success-subtle text-success">Active</span>
                                    @else
                                        <span class="badge bg-warning-subtle text-warning">Disabled</span>
                                    @endif
                                </div>
                            </div>
                            {{-- Meta on its own full-width line; actions in one non-wrapping row below --}}
                            <small class="text-muted d-block mt-1">
                                {{ $user->groups->isNotEmpty() ? $user->groups->pluck('name')->join(', ') : 'No group' }} ·
                                {{ $user->devices_count }} {{ Str::plural('device', $user->devices_count) }} ·
                                <span class="text-nowrap">{{ $user->created_at?->format('Y-m-d') }}</span>
                            </small>
                            <div class="d-flex flex-nowrap justify-content-end gap-1 mt-1">
                                <a href="javascript:void(0);" class="btn btn-sm btn-light" wire:click="edit({{ $user->id }})"><i class="ri-pencil-line"></i></a>
                                <a href="javascript:void(0);" class="btn btn-sm btn-light" title="Assign devices" wire:click="openAssign({{ $user->id }})"><i class="ri-computer-line"></i></a>
                                @unless ($user->id === auth()->id())
                                    <a href="javascript:void(0);" class="btn btn-sm btn-light" title="{{ $user->is_active ? 'Disable' : 'Enable' }}"
                                       wire:click="toggleActive({{ $user->id }})">
                                        <i class="{{ $user->is_active ? 'ri-user-unfollow-line' : 'ri-user-follow-line' }}"></i>
                                    </a>
                                    <a href="javascript:void(0);" class="btn btn-sm btn-light" title="Force logout everywhere"
                                       wire:click="forceLogout({{ $user->id }})"
                                       wire:confirm="Force {{ $user->username }} to log out everywhere? This signs out their RustDesk clients and console sessions.">
                                        <i class="ri-logout-box-r-line"></i>
                                    </a>
                                    <a href="javascript:void(0);" class="btn btn-sm btn-light text-danger"
                                       wire:click="deleteUser({{ $user->id }})"
                                       wire:confirm="Delete user {{ $user->username }}? Their devices will be kept but no longer assigned to any user."><i class="ri-delete-bin-line"></i></a>
                                @endunless
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-center text-muted py-4 mb-0">No users match your filters.</p>
                @endforelse
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <small class="text-muted">
                    Showing {{ $users->firstItem() ?? 0 }}–{{ $users->lastItem() ?? 0 }} of {{ $users->total() }}
                </small>
                {{ $users->links() }}
            </div>
        </div>
    </div>

    {{-- Create / Edit modal (plain Bootstrap markup, toggled by Livewire) --}}
    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $editing ? 'Edit User' : 'Add User' }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-username">Username <span class="text-danger">*</span></label>
                                        <input type="text" id="ul-username" class="form-control @error('username') is-invalid @enderror"
                                               wire:model="username" autocomplete="off">
                                        @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-name">Display Name</label>
                                        <input type="text" id="ul-name" class="form-control @error('name') is-invalid @enderror"
                                               wire:model="name" autocomplete="off">
                                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-email">Email</label>
                                        <input type="email" id="ul-email" class="form-control @error('email') is-invalid @enderror"
                                               wire:model="email" autocomplete="off">
                                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-password">
                                            Password
                                            @if ($editing)
                                                <small class="text-muted fw-normal">(leave blank to keep current)</small>
                                            @else
                                                <span class="text-danger">*</span>
                                            @endif
                                        </label>
                                        <input type="password" id="ul-password" class="form-control @error('password') is-invalid @enderror"
                                               wire:model="password" autocomplete="new-password">
                                        @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="ul-admin" wire:model.live="is_admin">
                                        <label class="form-check-label" for="ul-admin">Administrator</label>
                                        <small class="text-muted d-block">Admins see and manage everything.</small>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" role="switch" id="ul-active" wire:model="is_active"
                                               @if ($editing === auth()->id()) disabled @endif>
                                        <label class="form-check-label" for="ul-active">Active</label>
                                        @if ($editing === auth()->id())
                                            <small class="text-muted d-block">You cannot disable your own account.</small>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Groups</label>
                                        <p class="text-muted fs-13 mb-2">A user can belong to several groups. Groups control address-book sharing and device-group access.</p>
                                        <div class="border rounded p-2" style="max-height: 210px; overflow-y: auto;">
                                            @forelse ($userGroups as $g)
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="ul-ug-{{ $g->id }}"
                                                           value="{{ $g->id }}" wire:model="user_group_ids">
                                                    <label class="form-check-label" for="ul-ug-{{ $g->id }}">{{ $g->name }}</label>
                                                </div>
                                            @empty
                                                <p class="text-muted fs-13 mb-0">No user groups exist yet.</p>
                                            @endforelse
                                        </div>
                                        @error('user_group_ids') <div class="text-danger fs-13">{{ $message }}</div> @enderror
                                        @error('user_group_ids.*') <div class="text-danger fs-13">{{ $message }}</div> @enderror
                                    </div>

                                    {{-- Device-group access (non-admins only) --}}
                                    @unless ($is_admin)
                                        <div class="mb-1">
                                            <label class="form-label">Device access</label>
                                            <p class="text-muted fs-13 mb-2">This user sees only devices they own plus devices in the groups checked below.</p>
                                            <div class="border rounded p-2" style="max-height: 210px; overflow-y: auto;">
                                                @forelse ($deviceGroups as $dg)
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="ul-dg-{{ $dg->id }}"
                                                               value="{{ $dg->id }}" wire:model="device_group_ids">
                                                        <label class="form-check-label" for="ul-dg-{{ $dg->id }}">{{ $dg->name }}</label>
                                                    </div>
                                                @empty
                                                    <p class="text-muted fs-13 mb-0">No device groups exist yet.</p>
                                                @endforelse
                                            </div>
                                        </div>
                                    @endunless
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="save">{{ $editing ? 'Save Changes' : 'Create User' }}</span>
                                <span wire:loading wire:target="save">Saving…</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif

    {{-- Assign devices modal: bulk-set which devices this user owns --}}
    @if ($showAssignModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" wire:key="assign-modal">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Assign Devices</h5>
                        <button type="button" class="btn-close" wire:click="closeAssign" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted fs-13">
                            Select the devices owned by <strong>{{ optional(\App\Models\User::find($assignUserId))->username }}</strong>.
                            Checked devices become theirs; unchecking a device they currently own releases it (no owner).
                        </p>
                        <input type="search" class="form-control mb-2" placeholder="Search ID, alias, hostname…"
                               wire:model.live.debounce.300ms="assignSearch">
                        <div class="border rounded" style="max-height: 340px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <tbody>
                                    @forelse ($assignDevices as $d)
                                        <tr wire:key="ad{{ $d->id }}">
                                            <td style="width:38px;">
                                                <input class="form-check-input" type="checkbox"
                                                       value="{{ $d->id }}" wire:model="assignDeviceIds">
                                            </td>
                                            <td>
                                                <span class="fw-semibold">{{ $d->rustdesk_id }}</span>
                                                @if ($d->alias || $d->hostname)
                                                    <small class="text-muted d-block">{{ $d->alias ?: $d->hostname }}</small>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ($d->user_id && $d->user_id !== $assignUserId)
                                                    <span class="badge bg-secondary-subtle text-secondary">owned by {{ optional($d->user)->username ?? '—' }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-center text-muted py-3">No devices match.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted">{{ count($assignDeviceIds) }} selected @if($assignDevices->count() >= 200) · showing first 200, refine with search @endif</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeAssign">Cancel</button>
                        <button type="button" class="btn btn-primary" wire:click="saveAssign">
                            <span wire:loading.remove wire:target="saveAssign">Save Assignment</span>
                            <span wire:loading wire:target="saveAssign">Saving…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
