<div wire:poll.15s>

    {{-- Summary chips --}}
    <div class="row row-cols-2 row-cols-md-3 g-2 g-md-3 mb-3">
        <div class="col">
            <div class="card mb-0">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <i class="ri-computer-line fs-22 text-primary"></i>
                    <div>
                        <span class="fw-bold fs-16">{{ $totalCount }}</span>
                        <span class="text-muted fs-13 d-block d-sm-inline ms-sm-1">Devices</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card mb-0">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <i class="ri-checkbox-blank-circle-fill fs-14 text-success"></i>
                    <div>
                        <span class="fw-bold fs-16">{{ $onlineCount }}</span>
                        <span class="text-muted fs-13 d-block d-sm-inline ms-sm-1">Online</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card mb-0">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <i class="ri-checkbox-blank-circle-fill fs-14 text-secondary"></i>
                    <div>
                        <span class="fw-bold fs-16">{{ $totalCount - $onlineCount }}</span>
                        <span class="text-muted fs-13 d-block d-sm-inline ms-sm-1">Offline</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">

            {{-- Toolbar --}}
            <div class="row g-2 mb-3 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input type="search" class="form-control" placeholder="Search ID, alias, hostname, user, IP…"
                               wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <select class="form-select" wire:model.live="status" @disabled($trashed)>
                        <option value="all">All statuses</option>
                        <option value="online">Online</option>
                        <option value="offline">Offline</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select class="form-select" wire:model.live="group">
                        <option value="0">All groups</option>
                        @foreach ($groups as $g)
                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <button type="button" class="btn btn-light w-100" wire:click="resetFilters">Reset</button>
                </div>
                <div class="col-6 col-md-2 text-md-end">
                    @if ($trashed)
                        <button type="button" class="btn btn-secondary w-100" wire:click="$set('trashed', false)">
                            <i class="ri-arrow-left-line me-1"></i>Back to Devices
                        </button>
                    @else
                        <button type="button" class="btn btn-primary w-100" wire:click="create">
                            <i class="ri-add-line me-1"></i>Add Device
                        </button>
                    @endif
                </div>
            </div>

            @unless ($trashed)
                <div class="mb-2 text-end">
                    <a href="javascript:void(0);" class="fs-13 text-muted" wire:click="$set('trashed', true)">
                        <i class="ri-delete-bin-line me-1"></i>Recycle Bin ({{ $trashedCount }})
                    </a>
                </div>
            @else
                <div class="alert alert-warning py-2 fs-13">
                    <i class="ri-delete-bin-line me-1"></i>Recycle bin — devices here are hidden from the console and API but not destroyed.
                </div>
            @endunless

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Device</th>
                        <th>Alias</th>
                        <th>Group</th>
                        <th>Version</th>
                        <th>Last Seen</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($devices as $device)
                        <tr wire:key="d{{ $device->id }}">
                            <td>
                                <x-platform-icon :platform="$device->platform()" class="me-1"/>
                                @if ($trashed)
                                    <span class="fw-semibold">{{ $device->rustdesk_id }}</span>
                                @else
                                    <a href="rustdesk://{{ $device->rustdesk_id }}" class="fw-semibold"
                                       title="Connect with RustDesk">{{ $device->rustdesk_id }}</a>
                                @endif
                            </td>
                            <td>
                                <span class="d-block">{{ $device->hostname ?: '—' }}</span>
                                <small class="text-muted">{{ $device->username }}</small>
                            </td>
                            <td>{{ $device->alias ?: '—' }}</td>
                            <td>{{ $device->group?->name ?: '—' }}</td>
                            <td><span class="badge bg-secondary-subtle text-secondary">{{ $device->version ?: '?' }}</span></td>
                            <td>
                                <span title="{{ $device->last_online_at }}">
                                    {{ $device->last_online_at?->diffForHumans() ?? 'never' }}
                                </span>
                            </td>
                            <td>
                                @if ($trashed)
                                    <span class="badge bg-warning-subtle text-warning">Deleted</span>
                                @elseif ($device->isOnline())
                                    <span class="badge bg-success-subtle text-success"><i class="ri-checkbox-blank-circle-fill fs-10 me-1"></i>Online</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary"><i class="ri-checkbox-blank-circle-fill fs-10 me-1"></i>Offline</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($trashed)
                                    <a href="javascript:void(0);" class="text-success me-2" wire:click="restoreDevice({{ $device->id }})">Restore</a>
                                    <a href="javascript:void(0);" class="text-danger"
                                       wire:click="forceDeleteDevice({{ $device->id }})"
                                       wire:confirm="PERMANENTLY delete device {{ $device->rustdesk_id }}? This cannot be undone.">Destroy</a>
                                @else
                                    @if (config('cortendesk.native_webclient'))
                                        <a href="{{ route('webclient') }}?id={{ $device->rustdesk_id }}"
                                           target="cortendesk-webclient" rel="noopener" class="text-primary me-2"
                                           title="Connect in the browser (native client)"><i class="ri-remote-control-line me-1"></i>Connect</a>
                                    @endif
                                    @if (config('cortendesk.webclient_url'))
                                        <a href="{{ config('cortendesk.webclient_url') }}?id={{ $device->rustdesk_id }}"
                                           target="cortendesk-webclient" rel="noopener" class="text-info me-2"
                                           title="Connect in the browser">Web Client</a>
                                    @endif
                                    <a href="javascript:void(0);" class="text-primary me-2" wire:click="edit({{ $device->id }})">Edit</a>
                                    <a href="javascript:void(0);" class="text-danger"
                                       wire:click="deleteDevice({{ $device->id }})"
                                       wire:confirm="Move device {{ $device->rustdesk_id }} to the recycle bin?">Delete</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                {{ $trashed ? 'Recycle bin is empty.' : 'No devices match your filters.' }}
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none">
                @forelse ($devices as $device)
                    <div class="card border mb-2" wire:key="m{{ $device->id }}">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="d-flex align-items-center gap-2">
                                    <x-platform-icon :platform="$device->platform()" size="fs-22"/>
                                    <div>
                                        @if ($trashed)
                                            <span class="fw-semibold d-block">{{ $device->rustdesk_id }}</span>
                                        @else
                                            <a href="rustdesk://{{ $device->rustdesk_id }}" class="fw-semibold d-block"
                                               title="Connect with RustDesk">{{ $device->rustdesk_id }}</a>
                                        @endif
                                        <small class="text-muted">{{ $device->alias ?: $device->hostname }}</small>
                                    </div>
                                </div>
                                @if ($trashed)
                                    <span class="badge bg-warning-subtle text-warning">Deleted</span>
                                @elseif ($device->isOnline())
                                    <span class="badge bg-success-subtle text-success">Online</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">Offline</span>
                                @endif
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small class="text-muted">
                                    {{ $device->username }} · v{{ $device->version ?: '?' }} ·
                                    {{ $device->last_online_at?->diffForHumans(short: true) ?? 'never' }}
                                </small>
                                <div>
                                    @if ($trashed)
                                        <a href="javascript:void(0);" class="btn btn-sm btn-light text-success me-1" wire:click="restoreDevice({{ $device->id }})"><i class="ri-arrow-go-back-line"></i></a>
                                        <a href="javascript:void(0);" class="btn btn-sm btn-light text-danger"
                                           wire:click="forceDeleteDevice({{ $device->id }})"
                                           wire:confirm="PERMANENTLY delete device {{ $device->rustdesk_id }}?"><i class="ri-close-circle-line"></i></a>
                                    @else
                                        @if (config('cortendesk.native_webclient'))
                                            <a href="{{ route('webclient') }}?id={{ $device->rustdesk_id }}"
                                               target="cortendesk-webclient" rel="noopener" class="btn btn-sm btn-light text-primary me-1"
                                               title="Connect in the browser (native client)"><i class="ri-remote-control-line"></i></a>
                                        @endif
                                        @if (config('cortendesk.webclient_url'))
                                            <a href="{{ config('cortendesk.webclient_url') }}?id={{ $device->rustdesk_id }}"
                                               target="cortendesk-webclient" rel="noopener" class="btn btn-sm btn-light text-info me-1"
                                               title="Connect in the browser"><i class="ri-global-line"></i></a>
                                        @endif
                                        <a href="javascript:void(0);" class="btn btn-sm btn-light me-1" wire:click="edit({{ $device->id }})"><i class="ri-pencil-line"></i></a>
                                        <a href="javascript:void(0);" class="btn btn-sm btn-light text-danger"
                                           wire:click="deleteDevice({{ $device->id }})"
                                           wire:confirm="Move device {{ $device->rustdesk_id }} to the recycle bin?"><i class="ri-delete-bin-line"></i></a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-center text-muted py-4 mb-0">
                        {{ $trashed ? 'Recycle bin is empty.' : 'No devices match your filters.' }}
                    </p>
                @endforelse
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <small class="text-muted">
                    Showing {{ $devices->firstItem() ?? 0 }}–{{ $devices->lastItem() ?? 0 }} of {{ $devices->total() }}
                </small>
                {{ $devices->links() }}
            </div>
        </div>
    </div>

    {{-- Add / Edit modal --}}
    @if ($editingId !== null)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5);" wire:keydown.escape="closeModal">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $editingId === 0 ? 'Add Device' : 'Edit Device' }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">RustDesk ID</label>
                                <input type="text" class="form-control @error('formRustdeskId') is-invalid @enderror"
                                       wire:model="formRustdeskId" @disabled($editingId !== 0)>
                                @error('formRustdeskId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                @if ($editingId === 0)
                                    <div class="form-text">Pre-register a device by its RustDesk ID; details fill in when it first reports.</div>
                                @endif
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Alias</label>
                                <input type="text" class="form-control" wire:model="formAlias" placeholder="Friendly name">
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Group</label>
                                    <select class="form-select" wire:model="formGroupId">
                                        <option value="0">No group</option>
                                        @foreach ($groups as $g)
                                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Owner</label>
                                    <select class="form-select" wire:model="formUserId">
                                        <option value="0">Unassigned</option>
                                        @foreach ($users as $u)
                                            <option value="{{ $u->id }}">{{ $u->username }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="mb-1">
                                <label class="form-label">Note</label>
                                <textarea class="form-control" rows="2" wire:model="formNote" maxlength="500"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">{{ $editingId === 0 ? 'Add Device' : 'Save Changes' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
