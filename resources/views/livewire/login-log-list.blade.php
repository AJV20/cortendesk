<div>
    <div class="card">
        <div class="card-body">

            {{-- Toolbar --}}
            <div class="row g-2 mb-3 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input type="search" class="form-control" placeholder="Search username, device, IP…"
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
                        <th>Username</th>
                        <th>Client</th>
                        <th>Device</th>
                        <th>IP</th>
                        <th>Result</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($logs as $log)
                        <tr wire:key="l{{ $log->id }}">
                            <td>
                                <span title="{{ $log->created_at }}">{{ $log->created_at?->diffForHumans() }}</span>
                            </td>
                            <td><span class="fw-semibold">{{ $log->username }}</span></td>
                            <td>
                                @if ($log->client === 'web')
                                    <span class="badge bg-primary-subtle text-primary"><i class="ri-global-line me-1"></i>Web</span>
                                @elseif ($log->client === 'mobile')
                                    <span class="badge bg-warning-subtle text-warning"><i class="ri-smartphone-line me-1"></i>Mobile</span>
                                @elseif ($log->client === 'desktop')
                                    <span class="badge bg-info-subtle text-info"><i class="ri-computer-line me-1"></i>Desktop</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">{{ ucfirst($log->client) }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="d-block">{{ $log->device_id ?: '—' }}</span>
                                <small class="text-muted">{{ $log->device_os }}</small>
                            </td>
                            <td>{{ $log->ip ?: '—' }}</td>
                            <td>
                                @if ($log->successful)
                                    <span class="badge bg-success-subtle text-success"><i class="ri-check-line me-1"></i>Success</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger" @if ($log->note) title="{{ $log->note }}" @endif>
                                        <i class="ri-close-line me-1"></i>Failed
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No logins match your filters.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none">
                @forelse ($logs as $log)
                    <div class="card border mb-2" wire:key="ml{{ $log->id }}">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="fw-semibold d-block">{{ $log->username }}</span>
                                    <small class="text-muted">{{ $log->device_id ?: '—' }}@if ($log->device_os) · {{ $log->device_os }}@endif</small>
                                </div>
                                @if ($log->successful)
                                    <span class="badge bg-success-subtle text-success">Success</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger">Failed</span>
                                @endif
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-1">
                                @if ($log->client === 'web')
                                    <span class="badge bg-primary-subtle text-primary">Web</span>
                                @elseif ($log->client === 'mobile')
                                    <span class="badge bg-warning-subtle text-warning">Mobile</span>
                                @elseif ($log->client === 'desktop')
                                    <span class="badge bg-info-subtle text-info">Desktop</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">{{ ucfirst($log->client) }}</span>
                                @endif
                                <small class="text-muted">
                                    {{ $log->ip ?: '—' }} · {{ $log->created_at?->diffForHumans(short: true) }}
                                </small>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-center text-muted py-4 mb-0">No logins match your filters.</p>
                @endforelse
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <small class="text-muted">
                    Showing {{ $logs->firstItem() ?? 0 }}–{{ $logs->lastItem() ?? 0 }} of {{ $logs->total() }}
                </small>
                {{ $logs->links() }}
            </div>
        </div>
    </div>
</div>
