<div wire:poll.15s>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0"><i class="ri-broadcast-line me-1"></i>Active Sessions</h5>
            @if ($activeCount > 0)
                <span class="badge bg-success-subtle text-success">{{ $activeCount }} live</span>
            @endif
        </div>
        <div class="card-body pt-2">
            <div class="table-responsive">
                <table class="table table-sm table-centered mb-0">
                    <thead>
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th class="d-none d-sm-table-cell">Type</th>
                        <th>Started</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($sessions as $s)
                        <tr wire:key="s{{ $s->id }}">
                            <td>
                                <span class="d-block fs-13">{{ $s->from_name ?: $s->from_peer }}</span>
                                <small class="text-muted">{{ $s->from_peer }}</small>
                            </td>
                            <td><a href="rustdesk://{{ $s->rustdesk_id }}" title="Connect with RustDesk" class="fs-13">{{ $s->rustdesk_id }}</a></td>
                            <td class="d-none d-sm-table-cell">
                                @switch((int) $s->conn_type)
                                    @case(1)<span class="badge bg-info-subtle text-info">File</span>@break
                                    @case(2)<span class="badge bg-warning-subtle text-warning">Port Fwd</span>@break
                                    @default<span class="badge bg-primary-subtle text-primary">Remote</span>
                                @endswitch
                            </td>
                            <td>
                                <span class="fs-13" title="{{ $s->created_at }}">{{ $s->created_at->diffForHumans(short: true) }}</span>
                                <i class="ri-checkbox-blank-circle-fill text-success fs-10 ms-1 align-middle"></i>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No active sessions right now.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
