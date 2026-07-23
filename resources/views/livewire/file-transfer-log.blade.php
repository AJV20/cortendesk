<div>
    <div class="card">
        <div class="card-body">

            {{-- Toolbar --}}
            <div class="row g-2 mb-3 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input type="search" class="form-control" placeholder="Search device ID, from, path, IP…"
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
                        <th>Device</th>
                        <th>From</th>
                        <th>Direction</th>
                        <th>Path</th>
                        <th>Files</th>
                        <th>IP</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($transfers as $transfer)
                        <tr wire:key="t{{ $transfer->id }}">
                            <td>
                                <span title="{{ $transfer->created_at }}">{{ $transfer->created_at?->diffForHumans() }}</span>
                            </td>
                            <td><span class="fw-semibold">{{ $transfer->rustdesk_id }}</span></td>
                            <td>
                                <span class="d-block">{{ $transfer->from_peer ?: '—' }}</span>
                                <small class="text-muted">{{ $transfer->from_name }}</small>
                            </td>
                            <td>
                                @if ($transfer->direction === 1)
                                    <span class="badge bg-warning-subtle text-warning"><i class="ri-arrow-down-line me-1"></i>Receive</span>
                                @else
                                    <span class="badge bg-info-subtle text-info"><i class="ri-arrow-up-line me-1"></i>Send</span>
                                @endif
                            </td>
                            <td>
                                <span class="d-inline-block text-truncate align-middle" style="max-width: 240px;"
                                      title="{{ $transfer->path }}">{{ $transfer->path ?: '—' }}</span>
                            </td>
                            <td>{{ $transfer->file_count }}</td>
                            <td>{{ $transfer->ip ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No file transfers match your filters.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none">
                @forelse ($transfers as $transfer)
                    <div class="card border mb-2" wire:key="mt{{ $transfer->id }}">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="fw-semibold d-block">{{ $transfer->rustdesk_id }}</span>
                                    <small class="text-muted">
                                        from {{ $transfer->from_peer ?: '—' }}@if ($transfer->from_name) ({{ $transfer->from_name }})@endif
                                    </small>
                                </div>
                                @if ($transfer->direction === 1)
                                    <span class="badge bg-warning-subtle text-warning"><i class="ri-arrow-down-line me-1"></i>Receive</span>
                                @else
                                    <span class="badge bg-info-subtle text-info"><i class="ri-arrow-up-line me-1"></i>Send</span>
                                @endif
                            </div>
                            <div class="mt-2">
                                <small class="d-block text-truncate" title="{{ $transfer->path }}">
                                    <i class="ri-file-line me-1"></i>{{ $transfer->path ?: '—' }}
                                </small>
                                <small class="text-muted">
                                    {{ $transfer->file_count }} {{ Str::plural('file', $transfer->file_count) }} ·
                                    {{ $transfer->ip ?: '—' }} ·
                                    {{ $transfer->created_at?->diffForHumans(short: true) }}
                                </small>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-center text-muted py-4 mb-0">No file transfers match your filters.</p>
                @endforelse
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <small class="text-muted">
                    Showing {{ $transfers->firstItem() ?? 0 }}–{{ $transfers->lastItem() ?? 0 }} of {{ $transfers->total() }}
                </small>
                {{ $transfers->links() }}
            </div>
        </div>
    </div>
</div>
