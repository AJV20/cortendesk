<div>
    <div class="card">

        {{-- Toolbar --}}
        <div class="rd-toolbar">
            <div class="rd-toolbar-search">
                <div class="input-group">
                    <span class="input-group-text"><i class="ri-search-line"></i></span>
                    <input type="search" class="form-control" placeholder="Search username, device, IP…"
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
                        <td>
                            <span class="rd-cell">
                                <span class="rd-avatar {{ $log->successful ? 'rd-tone-blue' : 'rd-tone-red' }}">{{ strtoupper(substr($log->username ?: '?', 0, 1)) }}</span>
                                <span class="min-width-0"><span class="rd-cell-title">{{ $log->username }}</span></span>
                            </span>
                        </td>
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
                            <span class="rd-cell-title">{{ $log->device_id ?: '—' }}</span>
                            <span class="rd-cell-sub">{{ $log->device_os }}</span>
                        </td>
                        <td class="rd-mono">{{ $log->ip ?: '—' }}</td>
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
                        <td colspan="6" class="rd-empty-cell">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-login-circle-line"></i></div>
                                <p class="rd-empty-title">No logins match your filters.</p>
                                <p class="rd-empty-text">Every sign-in attempt from a RustDesk client shows up here, successful or not.</p>
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
            @forelse ($logs as $log)
                <div class="rd-mini" wire:key="ml{{ $log->id }}">
                    <div class="rd-mini-head">
                        <div class="min-width-0">
                            <span class="rd-mini-title">{{ $log->username }}</span>
                            <span class="rd-mini-sub">{{ $log->device_id ?: '—' }}@if ($log->device_os) · {{ $log->device_os }}@endif</span>
                        </div>
                        @if ($log->successful)
                            <span class="badge bg-success-subtle text-success">Success</span>
                        @else
                            <span class="badge bg-danger-subtle text-danger">Failed</span>
                        @endif
                    </div>
                    <div class="rd-mini-foot">
                        @if ($log->client === 'web')
                            <span class="badge bg-primary-subtle text-primary">Web</span>
                        @elseif ($log->client === 'mobile')
                            <span class="badge bg-warning-subtle text-warning">Mobile</span>
                        @elseif ($log->client === 'desktop')
                            <span class="badge bg-info-subtle text-info">Desktop</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary">{{ ucfirst($log->client) }}</span>
                        @endif
                        <span class="rd-mini-sub text-end">
                            {{ $log->ip ?: '—' }} · {{ $log->created_at?->diffForHumans(short: true) }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="ri-login-circle-line"></i></div>
                    <p class="rd-empty-title">No logins match your filters.</p>
                    <p class="rd-empty-text">Every sign-in attempt from a RustDesk client shows up here, successful or not.</p>
                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">Clear filters</button>
                </div>
            @endforelse
        </div>

        <div class="rd-tablefoot">
            <span>Showing {{ $logs->firstItem() ?? 0 }}–{{ $logs->lastItem() ?? 0 }} of {{ $logs->total() }}</span>
            {{ $logs->links() }}
        </div>
    </div>
</div>
