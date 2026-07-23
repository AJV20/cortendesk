<div>
    <div class="card">
        <div class="card-body">

            {{-- Tabs --}}
            <ul class="nav nav-tabs nav-bordered mb-3">
                <li class="nav-item">
                    <a href="javascript:void(0);" class="nav-link {{ $tab === 'devices' ? 'active' : '' }}"
                       wire:click="setTab('devices')">
                        <i class="ri-computer-line me-1"></i>Device Groups
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $deviceGroups->count() }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="javascript:void(0);" class="nav-link {{ $tab === 'users' ? 'active' : '' }}"
                       wire:click="setTab('users')">
                        <i class="ri-group-line me-1"></i>User Groups
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $userGroups->count() }}</span>
                    </a>
                </li>
            </ul>

            @php
                $current = $tab === 'users' ? $userGroups : $deviceGroups;
                $countLabel = $tab === 'users' ? 'Users' : 'Devices';
                $deleteConfirm = $tab === 'users'
                    ? 'Delete this user group? Users in it will be kept but no longer belong to any group.'
                    : 'Delete this device group? Devices in it will be kept but no longer belong to any group.';
            @endphp

            {{-- Toolbar --}}
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">{{ $tab === 'users' ? 'User Groups' : 'Device Groups' }}</h5>
                <button type="button" class="btn btn-primary" wire:click="create('{{ $tab }}')">
                    <i class="ri-add-line me-1"></i>Add Group
                </button>
            </div>

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Note</th>
                        <th>{{ $countLabel }}</th>
                        @if ($tab === 'users')
                            <th>Device access</th>
                        @endif
                        <th>Created</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($current as $group)
                        <tr wire:key="g-{{ $tab }}-{{ $group->id }}">
                            <td class="fw-semibold">{{ $group->name }}</td>
                            <td>{{ $group->note ?: '—' }}</td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary">
                                    {{ $tab === 'users' ? $group->users_count : $group->devices_count }}
                                </span>
                            </td>
                            @if ($tab === 'users')
                                <td>
                                    @forelse ($group->deviceGroups as $dg)
                                        <span class="badge bg-primary-subtle text-primary me-1">{{ $dg->name }}</span>
                                    @empty
                                        <span class="text-muted">—</span>
                                    @endforelse
                                </td>
                            @endif
                            <td>
                                <span title="{{ $group->created_at }}">{{ $group->created_at?->format('Y-m-d') }}</span>
                            </td>
                            <td class="text-end">
                                <a href="javascript:void(0);" class="text-primary me-2"
                                   wire:click="edit('{{ $tab }}', {{ $group->id }})">Edit</a>
                                <a href="javascript:void(0);" class="text-danger"
                                   wire:click="deleteGroup('{{ $tab }}', {{ $group->id }})"
                                   wire:confirm="{{ $deleteConfirm }}">Delete</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $tab === 'users' ? 6 : 5 }}" class="text-center text-muted py-4">
                                No {{ $tab === 'users' ? 'user' : 'device' }} groups yet. Click "Add Group" to create one.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none">
                @forelse ($current as $group)
                    <div class="card border mb-2" wire:key="mg-{{ $tab }}-{{ $group->id }}">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="fw-semibold d-block">{{ $group->name }}</span>
                                    <small class="text-muted">{{ $group->note ?: 'No note' }}</small>
                                </div>
                                <span class="badge bg-secondary-subtle text-secondary">
                                    {{ $tab === 'users' ? $group->users_count : $group->devices_count }}
                                    {{ strtolower($countLabel) }}
                                </span>
                            </div>
                            @if ($tab === 'users' && $group->deviceGroups->isNotEmpty())
                                <div class="mt-2">
                                    @foreach ($group->deviceGroups as $dg)
                                        <span class="badge bg-primary-subtle text-primary me-1">{{ $dg->name }}</span>
                                    @endforeach
                                </div>
                            @endif
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small class="text-muted">Created {{ $group->created_at?->format('Y-m-d') }}</small>
                                <div>
                                    <a href="javascript:void(0);" class="btn btn-sm btn-light me-1"
                                       wire:click="edit('{{ $tab }}', {{ $group->id }})"><i class="ri-pencil-line"></i></a>
                                    <a href="javascript:void(0);" class="btn btn-sm btn-light text-danger"
                                       wire:click="deleteGroup('{{ $tab }}', {{ $group->id }})"
                                       wire:confirm="{{ $deleteConfirm }}"><i class="ri-delete-bin-line"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-center text-muted py-4 mb-0">
                        No {{ $tab === 'users' ? 'user' : 'device' }} groups yet. Tap "Add Group" to create one.
                    </p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Create / Edit modal (plain Bootstrap markup, toggled by Livewire) --}}
    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                {{ $editing ? 'Edit' : 'Add' }} {{ $modalType === 'users' ? 'User' : 'Device' }} Group
                            </h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="gl-name">Name <span class="text-danger">*</span></label>
                                <input type="text" id="gl-name" class="form-control @error('name') is-invalid @enderror"
                                       wire:model="name" autocomplete="off">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="{{ $modalType === 'users' ? 'mb-3' : 'mb-0' }}">
                                <label class="form-label" for="gl-note">Note</label>
                                <textarea id="gl-note" rows="3" class="form-control @error('note') is-invalid @enderror"
                                          wire:model="note"></textarea>
                                @error('note') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            {{-- Device-group access (user groups only) --}}
                            @if ($modalType === 'users')
                                <div class="mb-1">
                                    <label class="form-label">Device group access</label>
                                    <p class="text-muted fs-13 mb-2">Every member of this user group sees the devices in the groups checked below (in addition to devices they own or were granted individually).</p>
                                    @forelse ($deviceGroups as $dg)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="gl-dg-{{ $dg->id }}"
                                                   value="{{ $dg->id }}" wire:model="device_group_ids">
                                            <label class="form-check-label" for="gl-dg-{{ $dg->id }}">{{ $dg->name }}</label>
                                        </div>
                                    @empty
                                        <p class="text-muted fs-13">No device groups exist yet.</p>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="save">{{ $editing ? 'Save Changes' : 'Create Group' }}</span>
                                <span wire:loading wire:target="save">Saving…</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
