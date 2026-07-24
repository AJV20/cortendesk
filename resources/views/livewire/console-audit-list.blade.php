<div>
    <div class="card">
        <div class="card-body">

            {{-- Toolbar --}}
            <div class="row g-2 mb-3 align-items-center">
                <div class="col-12 col-md-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input type="search" class="form-control" placeholder="Search operator or details…"
                               wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <select class="form-select" wire:model.live="action" aria-label="Action">
                        <option value="">All actions</option>
                        @foreach ($actions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
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
                <div class="col-4 col-md-auto">
                    <button type="button" class="btn btn-light w-100" wire:click="resetFilters">Reset</button>
                </div>
                <div class="col-4 col-md-auto text-md-end">
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
                        <th>Time</th>
                        <th>Operator</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($audits as $audit)
                        <tr wire:key="a{{ $audit->id }}">
                            <td>
                                <span title="{{ $audit->created_at }}">{{ $audit->created_at?->diffForHumans() }}</span>
                            </td>
                            <td><span class="fw-semibold">{{ $audit->username }}</span></td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary">{{ $audit->action }}</span>
                            </td>
                            <td>
                                @if ($audit->target_type || $audit->target_id)
                                    <span class="d-block">{{ $audit->target_id ?: '—' }}</span>
                                    <small class="text-muted">{{ $audit->target_type }}</small>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $audit->summary }}</td>
                            <td>{{ $audit->ip ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No audit entries match your filters.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none">
                @forelse ($audits as $audit)
                    <div class="card border mb-2" wire:key="ma{{ $audit->id }}">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="fw-semibold d-block">{{ $audit->username }}</span>
                                    <small class="text-muted">{{ $audit->summary }}</small>
                                </div>
                                <span class="badge bg-secondary-subtle text-secondary">{{ $audit->action }}</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-1">
                                <small class="text-muted">
                                    @if ($audit->target_id){{ $audit->target_id }} · @endif{{ $audit->target_type ?: '—' }}
                                </small>
                                <small class="text-muted">
                                    {{ $audit->ip ?: '—' }} · {{ $audit->created_at?->diffForHumans(short: true) }}
                                </small>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-center text-muted py-4 mb-0">No audit entries match your filters.</p>
                @endforelse
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <small class="text-muted">
                    Showing {{ $audits->firstItem() ?? 0 }}–{{ $audits->lastItem() ?? 0 }} of {{ $audits->total() }}
                </small>
                {{ $audits->links() }}
            </div>
        </div>
    </div>
</div>
