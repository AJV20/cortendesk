<div>
    <div class="card">
        <div class="card-body">

            {{-- Toolbar --}}
            <div class="row g-2 mb-3 align-items-center">
                <div class="col-12 col-md-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input type="search" class="form-control" placeholder="Search device ID, info…"
                               wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <select class="form-select" wire:model.live="type" aria-label="Alarm type">
                        <option value="">All types</option>
                        @foreach ($types as $typ => $meta)
                            <option value="{{ $typ }}">{{ $meta['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" class="form-control" wire:model.live="dateFrom" aria-label="From date">
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" class="form-control" wire:model.live="dateTo" aria-label="To date">
                </div>
                <div class="col-2 col-md-1">
                    <select class="form-select" wire:model.live="perPage" aria-label="Rows per page">
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div class="col-2 col-md-1">
                    <button type="button" class="btn btn-light w-100" wire:click="resetFilters">Reset</button>
                </div>
                <div class="col-8 col-md-1 text-md-end">
                    <button type="button" class="btn btn-primary w-100" wire:click="export">
                        <i class="ri-download-2-line me-1"></i>CSV
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
                        <th>Type</th>
                        <th>Details</th>
                        <th>Conn</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($alarms as $alarm)
                        <tr wire:key="a{{ $alarm->id }}">
                            <td>
                                <span title="{{ $alarm->created_at }}">{{ $alarm->created_at?->diffForHumans() }}</span>
                            </td>
                            <td>
                                @if ($alarm->rustdesk_id === \App\Models\AlarmLog::CONSOLE_SOURCE)
                                    <span class="badge bg-secondary-subtle text-secondary"><i class="ri-terminal-box-line me-1"></i>Console</span>
                                @else
                                    <span class="fw-semibold">
                                    {{ $alarm->rustdesk_id === \App\Models\AlarmLog::CONSOLE_SOURCE ? 'Console' : $alarm->rustdesk_id }}
                                </span>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-{{ $alarm->typeSeverity() }}-subtle text-{{ $alarm->typeSeverity() }}">
                                    <i class="ri-alarm-warning-line me-1"></i>{{ $alarm->typeLabel() }}
                                </span>
                            </td>
                            <td>
                                @if ($alarm->infoPairs() !== [])
                                    <small class="d-inline-block text-truncate align-middle" style="max-width: 320px;"
                                           title="{{ $alarm->info }}">
                                        @foreach ($alarm->infoPairs() as $key => $value)
                                            <span class="me-2"><span class="text-muted">{{ $key }}:</span> {{ $value }}</span>
                                        @endforeach
                                    </small>
                                @else
                                    <span class="d-inline-block text-truncate align-middle" style="max-width: 320px;"
                                          title="{{ $alarm->info }}">{{ $alarm->info ?: '—' }}</span>
                                @endif
                            </td>
                            <td>{{ $alarm->conn_id ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No alarms match your filters.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none">
                @forelse ($alarms as $alarm)
                    <div class="card border mb-2" wire:key="ma{{ $alarm->id }}">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="fw-semibold">{{ $alarm->rustdesk_id }}</span>
                                <span class="badge bg-{{ $alarm->typeSeverity() }}-subtle text-{{ $alarm->typeSeverity() }}">
                                    {{ $alarm->typeLabel() }}
                                </span>
                            </div>
                            <div class="mt-2">
                                @if ($alarm->info)
                                    <small class="d-block text-truncate" title="{{ $alarm->info }}">
                                        <i class="ri-information-line me-1"></i>{{ $alarm->info }}
                                    </small>
                                @endif
                                <small class="text-muted">
                                    @if ($alarm->conn_id)conn {{ $alarm->conn_id }} · @endif{{ $alarm->created_at?->diffForHumans(short: true) }}
                                </small>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-center text-muted py-4 mb-0">No alarms match your filters.</p>
                @endforelse
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <small class="text-muted">
                    Showing {{ $alarms->firstItem() ?? 0 }}–{{ $alarms->lastItem() ?? 0 }} of {{ $alarms->total() }}
                </small>
                {{ $alarms->links() }}
            </div>
        </div>
    </div>
</div>
