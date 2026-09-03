<div wire:poll.15s>
    @php
        // Stable tint per controlling peer, so the same operator keeps the same
        // colour between polls. Purely an aid to scanning the list.
        $tones = ['rd-tone-blue', 'rd-tone-purple', 'rd-tone-teal', 'rd-tone-amber', 'rd-tone-accent'];
    @endphp
    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <div class="min-width-0">
                <h5 class="card-title">Active Sessions</h5>
                <div class="rd-card-sub">Connections open right now</div>
            </div>
            @if ($activeCount > 0)
                <div class="rd-card-actions">
                    <span class="badge bg-success-subtle text-success"><i class="rd-dot"></i>{{ $activeCount }} live</span>
                </div>
            @endif
        </div>
        <div class="card-body">
            @if ($sessions->isEmpty())
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="ri-broadcast-line"></i></div>
                    <p class="rd-empty-title">No active sessions</p>
                    <p class="rd-empty-text">Sessions appear here the moment a client connects to one of your devices.</p>
                    <a href="{{ route('logs.connections') }}" class="btn btn-sm btn-outline-light">View the connection log</a>
                </div>
            @else
                {{-- Desktop table (md and up) --}}
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-hover table-centered align-middle mb-0">
                        <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Type</th>
                            <th>Started</th>
                            @if ($canDisconnect)
                                <th class="text-end">Action</th>
                            @endif
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($sessions as $s)
                            @php
                                $who = $s->from_name ?: $s->from_peer;
                                $tone = $tones[crc32((string) $s->from_peer) % count($tones)];
                            @endphp
                            <tr wire:key="s{{ $s->id }}">
                                <td>
                                    <div class="rd-cell">
                                        <span class="rd-avatar {{ $tone }}">{{ mb_substr($who, 0, 1) }}</span>
                                        <div class="min-width-0">
                                            <span class="rd-cell-title text-truncate">{{ $who }}</span>
                                            <span class="rd-cell-sub text-truncate">{{ $s->from_peer }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <a href="rustdesk://{{ $s->rustdesk_id }}" title="Connect with RustDesk" class="fs-13">{{ $s->rustdesk_id }}</a>
                                </td>
                                <td>
                                    @switch((int) $s->conn_type)
                                        @case(1)<span class="badge bg-primary-subtle text-primary">File</span>@break
                                        @case(2)<span class="badge bg-warning-subtle text-warning">Port Fwd</span>@break
                                        @default<span class="badge bg-info-subtle text-info">{{ \App\Models\AuditConnection::typeLabel((int) $s->conn_type) }}</span>
                                    @endswitch
                                </td>
                                <td>
                                    <span class="rd-cell-title fw-normal"><i class="rd-dot text-success"></i><span
                                            title="{{ $s->created_at }}">{{ $s->created_at->diffForHumans(short: true) }}</span></span>
                                </td>
                                @if ($canDisconnect)
                                    <td class="text-end">
                                        @if ($s->isDisconnecting())
                                            {{-- The console cannot close the session itself; it waits for
                                                 the device's next heartbeat to carry the instruction. --}}
                                            <span class="badge bg-warning-subtle text-warning" title="Sent on the device's next heartbeat">Disconnecting…</span>
                                        @else
                                            <a href="#" class="text-danger fs-13" wire:click.prevent="disconnect({{ $s->id }})"
                                               wire:confirm="End this session on {{ $s->rustdesk_id }}?">Disconnect</a>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile card list (below md). Same .rd-cardlist / .rd-mini object
                     as every other list screen, so it inherits the phone wrap guards;
                     p-0 because this list sits inside a card body rather than flush
                     against the panel edges. --}}
                <div class="d-md-none rd-cardlist p-0">
                    @foreach ($sessions as $s)
                        @php
                            $who = $s->from_name ?: $s->from_peer;
                            $tone = $tones[crc32((string) $s->from_peer) % count($tones)];
                        @endphp
                        <div class="rd-mini" wire:key="ms{{ $s->id }}">
                            <div class="rd-mini-head">
                                <div class="rd-cell">
                                    <span class="rd-avatar {{ $tone }}">{{ mb_substr($who, 0, 1) }}</span>
                                    <div class="min-width-0">
                                        <span class="rd-mini-title text-truncate">{{ $who }}</span>
                                        <span class="rd-mini-sub text-truncate">{{ $s->from_peer }}</span>
                                    </div>
                                </div>
                                @switch((int) $s->conn_type)
                                    @case(1)<span class="badge bg-primary-subtle text-primary">File</span>@break
                                    @case(2)<span class="badge bg-warning-subtle text-warning">Port Fwd</span>@break
                                    @default<span class="badge bg-info-subtle text-info">{{ \App\Models\AuditConnection::typeLabel((int) $s->conn_type) }}</span>
                                @endswitch
                            </div>
                            @if ($canDisconnect)
                                <div class="text-end">
                                    @if ($s->isDisconnecting())
                                        <span class="badge bg-warning-subtle text-warning">Disconnecting…</span>
                                    @else
                                        <a href="#" class="text-danger fs-13" wire:click.prevent="disconnect({{ $s->id }})"
                                           wire:confirm="End this session on {{ $s->rustdesk_id }}?">Disconnect</a>
                                    @endif
                                </div>
                            @endif
                            <div class="rd-mini-foot">
                                <a href="rustdesk://{{ $s->rustdesk_id }}" title="Connect with RustDesk"
                                   class="fs-13 text-truncate">{{ $s->rustdesk_id }}</a>
                                <span class="rd-mini-sub text-nowrap"><i class="rd-dot text-success"></i><span
                                        title="{{ $s->created_at }}">{{ $s->created_at->diffForHumans(short: true) }}</span></span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
