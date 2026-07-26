<div>
    <div class="card">

        {{-- Toolbar --}}
        <div class="rd-toolbar">
            <div class="rd-toolbar-search">
                <div class="input-group">
                    <span class="input-group-text"><i class="ri-search-line"></i></span>
                    <input type="search" class="form-control" placeholder="Search device ID, from, IP…"
                           wire:model.live.debounce.300ms="search">
                </div>
            </div>
            <input type="date" class="form-control rd-toolbar-filter" wire:model.live="dateFrom" aria-label="From date">
            <input type="date" class="form-control rd-toolbar-filter" wire:model.live="dateTo" aria-label="To date">
            <select class="form-select rd-toolbar-narrow" wire:model.live="perPage" aria-label="Rows per page">
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            <div class="rd-toolbar-actions">
                <button type="button" class="btn btn-outline-light" wire:click="resetFilters">Reset</button>
                <button type="button" class="btn btn-primary" wire:click="export">
                    <i class="ri-download-2-line"></i>Export CSV
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
                            <span class="rd-cell-title">{{ $conn->from_peer ?: '—' }}</span>
                            <span class="rd-cell-sub">{{ $conn->from_name }}</span>
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
                        <td class="rd-mono">{{ $conn->ip ?: '—' }}</td>
                        <td>
                            @if ($conn->closed_at)
                                <span title="Closed {{ $conn->closed_at }}">
                                    {{ $conn->closed_at->shortAbsoluteDiffForHumans($conn->created_at, 2) }}
                                </span>
                            @else
                                <span class="badge bg-success-subtle text-success"><i class="rd-dot"></i>Active</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="rd-empty-cell">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-remote-control-line"></i></div>
                                <p class="rd-empty-title">No connections match your filters.</p>
                                <p class="rd-empty-text">Sessions appear here as soon as a client connects to one of your devices.</p>
                                <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">Clear filters</button>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile card list (below md) --}}
        <div class="d-md-none rd-cardlist">
            @forelse ($connections as $conn)
                <div class="rd-mini" wire:key="mc{{ $conn->id }}">
                    <div class="rd-mini-head">
                        <div class="min-width-0">
                            <span class="rd-mini-title">{{ $conn->rustdesk_id }}</span>
                            <span class="rd-mini-sub">
                                from {{ $conn->from_peer ?: '—' }}@if ($conn->from_name) ({{ $conn->from_name }})@endif
                            </span>
                        </div>
                        @if ($conn->closed_at)
                            <span class="badge bg-secondary-subtle text-secondary">
                                {{ $conn->closed_at->shortAbsoluteDiffForHumans($conn->created_at, 2) }}
                            </span>
                        @else
                            <span class="badge bg-success-subtle text-success"><i class="rd-dot"></i>Active</span>
                        @endif
                    </div>
                    <div class="rd-mini-foot">
                        @if ($conn->conn_type === 1)
                            <span class="badge bg-info-subtle text-info">File Transfer</span>
                        @elseif ($conn->conn_type === 2)
                            <span class="badge bg-warning-subtle text-warning">Port Forward</span>
                        @else
                            <span class="badge bg-primary-subtle text-primary">Remote Control</span>
                        @endif
                        <span class="rd-mini-sub text-end">
                            {{ $conn->ip ?: '—' }} · {{ $conn->created_at?->diffForHumans(short: true) }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="ri-remote-control-line"></i></div>
                    <p class="rd-empty-title">No connections match your filters.</p>
                    <p class="rd-empty-text">Sessions appear here as soon as a client connects to one of your devices.</p>
                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">Clear filters</button>
                </div>
            @endforelse
        </div>

        <div class="rd-tablefoot">
            <span>Showing {{ $connections->firstItem() ?? 0 }}–{{ $connections->lastItem() ?? 0 }} of {{ $connections->total() }}</span>
            {{ $connections->links() }}
        </div>
    </div>
</div>
