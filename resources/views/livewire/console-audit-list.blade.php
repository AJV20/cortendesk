<div>
    <div class="card">

        {{-- Toolbar --}}
        <div class="rd-toolbar">
            <div class="rd-toolbar-search">
                <div class="input-group">
                    <span class="input-group-text"><i class="ri-search-line"></i></span>
                    <input type="search" class="form-control" placeholder="Search operator or details…"
                           wire:model.live.debounce.300ms="search">
                </div>
            </div>
            <select class="form-select rd-toolbar-filter" wire:model.live="action" aria-label="Action">
                <option value="">All actions</option>
                @foreach ($actions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
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
                        <td>
                            <span class="rd-cell">
                                <span class="rd-avatar rd-tone-purple">{{ strtoupper(substr($audit->username ?: '?', 0, 1)) }}</span>
                                <span class="min-width-0"><span class="rd-cell-title">{{ $audit->username }}</span></span>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-secondary-subtle text-secondary">{{ $audit->action }}</span>
                        </td>
                        <td>
                            @if ($audit->target_type || $audit->target_id)
                                <span class="rd-cell-title">{{ $audit->target_id ?: '—' }}</span>
                                <span class="rd-cell-sub">{{ $audit->target_type }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $audit->summary }}</td>
                        <td class="rd-mono">{{ $audit->ip ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="rd-empty-cell">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-history-line"></i></div>
                                <p class="rd-empty-title">No audit entries match your filters.</p>
                                <p class="rd-empty-text">Changes made in the console — users, roles, settings — are recorded here.</p>
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
            @forelse ($audits as $audit)
                <div class="rd-mini" wire:key="ma{{ $audit->id }}">
                    <div class="rd-mini-head">
                        <div class="min-width-0">
                            <span class="rd-mini-title">{{ $audit->username }}</span>
                            <span class="rd-mini-sub">{{ $audit->summary }}</span>
                        </div>
                        <span class="badge bg-secondary-subtle text-secondary">{{ $audit->action }}</span>
                    </div>
                    <div class="rd-mini-foot">
                        <span class="rd-mini-sub">
                            @if ($audit->target_id){{ $audit->target_id }} · @endif{{ $audit->target_type ?: '—' }}
                        </span>
                        <span class="rd-mini-sub text-end">
                            {{ $audit->ip ?: '—' }} · {{ $audit->created_at?->diffForHumans(short: true) }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="ri-history-line"></i></div>
                    <p class="rd-empty-title">No audit entries match your filters.</p>
                    <p class="rd-empty-text">Changes made in the console — users, roles, settings — are recorded here.</p>
                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">Clear filters</button>
                </div>
            @endforelse
        </div>

        <div class="rd-tablefoot">
            <span>Showing {{ $audits->firstItem() ?? 0 }}–{{ $audits->lastItem() ?? 0 }} of {{ $audits->total() }}</span>
            {{ $audits->links() }}
        </div>
    </div>
</div>
