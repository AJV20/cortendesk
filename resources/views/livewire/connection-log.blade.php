<div>
    <div class="card">
        <div class="card-body">

            {{-- Toolbar --}}
            <div class="row g-2 mb-3 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input type="search" class="form-control" placeholder="Search device ID, from, IP…"
                               wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" class="form-control" wire:model.live="dateFrom" aria-label="From date">
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" class="form-control" wire:model.live="dateTo" aria-label="To date">
                </div>
                <div class="col-4 col-md-1">
                    <select class="form-select" wire:model.live="perPage" aria-label="Rows per page">
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div class="col-3 col-md-1">
                    <button type="button" class="btn btn-light w-100" wire:click="resetFilters">Reset</button>
                </div>
                <div class="col-5 col-md-2 text-md-end">
                    <button type="button" class="btn btn-primary w-100" wire:click="export">
                        <i class="ri-download-2-line me-1"></i>Export CSV
                    </button>
                </div>
            </div>

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>Controlled Device</th>
                        <th>From</th>
                        <th>Type</th>
                        <th>IP</th>
                        <th>Duration</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($connections as $conn)
                        <tr wire:key="c{{ $conn->id }}">
                            <td>
                                <span title="{{ $conn->created_at }}">{{ $conn->created_at?->diffForHumans() }}</span>
                            </td>
                            <td><span class="fw-semibold">{{ $conn->rustdesk_id }}</span></td>
                            <td>
                                <span class="d-block">{{ $conn->from_peer ?: '—' }}</span>
                                <small class="text-muted">{{ $conn->from_name }}</small>
                            </td>
                            <td>
                                @if ($conn->conn_type === 1)
                                    <span class="badge bg-info-subtle text-info"><i class="ri-file-transfer-line me-1"></i>File Transfer</span>
                                @elseif ($conn->conn_type === 2)
                                    <span class="badge bg-warning-subtle text-warning"><i class="ri-swap-line me-1"></i>Port Forward</span>
                                @else
                                    <span class="badge bg-primary-subtle text-primary"><i class="ri-remote-control-line me-1"></i>Remote Control</span>
                                @endif
                            </td>
                            <td>{{ $conn->ip ?: '—' }}</td>
                            <td>
                                @if ($conn->closed_at)
                                    <span title="Closed {{ $conn->closed_at }}">
                                        {{ $conn->closed_at->shortAbsoluteDiffForHumans($conn->created_at, 2) }}
                                    </span>
                                @else
                                    <span class="badge bg-success-subtle text-success"><i class="ri-checkbox-blank-circle-fill fs-10 me-1"></i>Active</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No connections match your filters.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none">
                @forelse ($connections as $conn)
                    <div class="card border mb-2" wire:key="mc{{ $conn->id }}">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="fw-semibold d-block">{{ $conn->rustdesk_id }}</span>
                                    <small class="text-muted">
                                        from {{ $conn->from_peer ?: '—' }}@if ($conn->from_name) ({{ $conn->from_name }})@endif
                                    </small>
                                </div>
                                @if ($conn->closed_at)
                                    <span class="badge bg-secondary-subtle text-secondary">
                                        {{ $conn->closed_at->shortAbsoluteDiffForHumans($conn->created_at, 2) }}
                                    </span>
                                @else
                                    <span class="badge bg-success-subtle text-success">Active</span>
                                @endif
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-1">
                                @if ($conn->conn_type === 1)
                                    <span class="badge bg-info-subtle text-info">File Transfer</span>
                                @elseif ($conn->conn_type === 2)
                                    <span class="badge bg-warning-subtle text-warning">Port Forward</span>
                                @else
                                    <span class="badge bg-primary-subtle text-primary">Remote Control</span>
                                @endif
                                <small class="text-muted">
                                    {{ $conn->ip ?: '—' }} · {{ $conn->created_at?->diffForHumans(short: true) }}
                                </small>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-center text-muted py-4 mb-0">No connections match your filters.</p>
                @endforelse
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <small class="text-muted">
                    Showing {{ $connections->firstItem() ?? 0 }}–{{ $connections->lastItem() ?? 0 }} of {{ $connections->total() }}
                </small>
                {{ $connections->links() }}
            </div>
        </div>
    </div>
</div>
