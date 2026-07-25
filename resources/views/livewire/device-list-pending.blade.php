<div wire:poll.15s>
    <div class="card">
        <div class="card-body">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="mb-0"><i class="ri-time-line me-1 text-warning"></i>Pending approval
                        <span class="badge bg-warning-subtle text-warning ms-1">{{ $pendingCount }}</span>
                    </h5>
                    <small class="text-muted">New devices held by the deployment gate. Approve to make them visible, or reject to remove.</small>
                </div>
                <button type="button" class="btn btn-secondary" wire:click="$set('pendingTab', false)">
                    <i class="ri-arrow-left-line me-1"></i>Back to Devices
                </button>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-12 col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input type="search" class="form-control" placeholder="Search ID, hostname, user, IP…"
                               wire:model.live.debounce.300ms="search">
                    </div>
                </div>
            </div>

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Device</th>
                        <th>OS</th>
                        <th>Version</th>
                        <th>First Seen</th>
                        <th>IP</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($devices as $device)
                        <tr wire:key="p{{ $device->id }}">
                            <td>
                                <x-platform-icon :platform="$device->platform()" class="me-1"/>
                                <span class="fw-semibold">{{ $device->rustdesk_id }}</span>
                            </td>
                            <td>
                                <span class="d-block">{{ $device->hostname ?: '—' }}</span>
                                <small class="text-muted">{{ $device->username }}</small>
                            </td>
                            <td>{{ $device->os ?: '—' }}</td>
                            <td><span class="badge bg-secondary-subtle text-secondary">{{ $device->version ?: '?' }}</span></td>
                            <td><span title="{{ $device->created_at }}">{{ $device->created_at?->diffForHumans() ?? '—' }}</span></td>
                            <td>{{ $device->last_online_ip ?: '—' }}</td>
                            <td class="text-end">
                                @if (auth()->user()?->consoleAllows('device', 'rw'))
                                    <a href="javascript:void(0);" class="text-success me-2" wire:click="approveDevice({{ $device->id }})">
                                        <i class="ri-check-line me-1"></i>Approve
                                    </a>
                                    <a href="javascript:void(0);" class="text-danger"
                                       wire:click="rejectDevice({{ $device->id }})"
                                       wire:confirm="Reject device {{ $device->rustdesk_id }}? It will be removed from the console.">
                                        <i class="ri-close-line me-1"></i>Reject
                                    </a>
                                @else
                                    <span class="text-muted">View only</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No devices are awaiting approval.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none">
                @forelse ($devices as $device)
                    <div class="card border mb-2" wire:key="pm{{ $device->id }}">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="d-flex align-items-center gap-2 min-width-0">
                                    <x-platform-icon :platform="$device->platform()" size="fs-22"/>
                                    <div class="lh-sm min-width-0">
                                        <span class="fw-semibold d-block text-truncate">{{ $device->rustdesk_id }}</span>
                                        <small class="text-muted d-block text-truncate">{{ $device->hostname ?: $device->os }}</small>
                                    </div>
                                </div>
                                <span class="badge bg-warning-subtle text-warning flex-shrink-0">Pending</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center gap-2 mt-2">
                                <small class="text-muted min-width-0">
                                    {{ $device->username }} · v{{ $device->version ?: '?' }} ·
                                    <span class="text-nowrap">{{ $device->created_at?->diffForHumans(short: true) ?? '—' }}</span>
                                </small>
                                <div class="d-flex flex-nowrap gap-1 flex-shrink-0">
                                    @if (auth()->user()?->consoleAllows('device', 'rw'))
                                        <a href="javascript:void(0);" class="btn btn-sm btn-light text-success" wire:click="approveDevice({{ $device->id }})"><i class="ri-check-line"></i></a>
                                        <a href="javascript:void(0);" class="btn btn-sm btn-light text-danger"
                                           wire:click="rejectDevice({{ $device->id }})"
                                           wire:confirm="Reject device {{ $device->rustdesk_id }}?"><i class="ri-close-line"></i></a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-center text-muted py-4 mb-0">No devices are awaiting approval.</p>
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
</div>
