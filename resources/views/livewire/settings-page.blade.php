<div>
    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="ri-server-line me-1"></i>RustDesk Server</h5>
                </div>
                <div class="card-body">
                    @if ($saved)
                        <div class="alert alert-success py-2" wire:poll.4s="$set('saved', false)">
                            <i class="ri-check-line me-1"></i>Settings saved.
                        </div>
                    @endif

                    <form wire:submit="save">
                        <div class="mb-3">
                            <label class="form-label">ID Server (hbbs)</label>
                            <input type="text" class="form-control" wire:model="idServer" placeholder="e.g. hbbs.example.com:21116">
                            <div class="form-text">Host:port of the rendezvous server clients register with.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Relay Server (hbbr)</label>
                            <input type="text" class="form-control" wire:model="relayServer" placeholder="e.g. hbbs.example.com:21117">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Public Key</label>
                            <input type="text" class="form-control font-monospace" wire:model="publicKey" placeholder="contents of id_ed25519.pub">
                            <div class="form-text">The server's ed25519 public key — clients need it when <code>ENCRYPTED_ONLY</code> is enabled.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Build Installers URL</label>
                            <input type="text" class="form-control @error('rdgenUrl') is-invalid @enderror"
                                   wire:model="rdgenUrl" placeholder="https://rdgen.crayoneater.org">
                            @error('rdgenUrl') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">Opened by the sidebar's <strong>Build Installers</strong> entry — point it at your own rdgen instance. Leave empty to hide the menu entry.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Online window (seconds)</label>
                            <input type="number" class="form-control @error('onlineWindow') is-invalid @enderror"
                                   wire:model="onlineWindow" min="20" max="600" style="max-width: 140px;">
                            @error('onlineWindow') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">Seconds without a heartbeat before a device shows as offline. Clients report every ~15s.</div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="ri-save-line me-1"></i>Save Settings</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="ri-database-2-line me-1"></i>Maintenance</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Log retention (days)</label>
                        <input type="number" class="form-control @error('logRetentionDays') is-invalid @enderror"
                               wire:model="logRetentionDays" min="0" max="3650" style="max-width: 140px;">
                        @error('logRetentionDays') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">Connection, file-transfer, login and alarm logs older than this are deleted nightly. <strong>0 keeps logs forever.</strong></div>
                    </div>
                    <button type="button" class="btn btn-light" wire:click="pruneNow"
                            wire:confirm="Delete all log entries older than {{ $logRetentionDays }} days now?"
                            @if($logRetentionDays < 1) disabled @endif>
                        <i class="ri-delete-bin-6-line me-1"></i>Prune now
                    </button>
                    @if ($pruneResult)
                        <div class="alert alert-info py-2 mt-2 mb-0 font-monospace fs-13" style="white-space: pre-line;">{{ $pruneResult }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="ri-download-2-line me-1"></i>Client Setup</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted fs-13">Point RustDesk clients at this console under
                        <strong>Settings &rarr; Network &rarr; ID/Relay Server</strong>:</p>

                    <label class="form-label fs-13 text-muted mb-0">ID Server</label>
                    <div class="input-group input-group-sm mb-2">
                        <input type="text" class="form-control font-monospace" readonly value="{{ $idServer }}">
                        <button class="btn btn-light" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="ri-file-copy-line"></i></button>
                    </div>

                    <label class="form-label fs-13 text-muted mb-0">Relay Server</label>
                    <div class="input-group input-group-sm mb-2">
                        <input type="text" class="form-control font-monospace" readonly value="{{ $relayServer }}">
                        <button class="btn btn-light" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="ri-file-copy-line"></i></button>
                    </div>

                    <label class="form-label fs-13 text-muted mb-0">API Server</label>
                    <div class="input-group input-group-sm mb-2">
                        <input type="text" class="form-control font-monospace" readonly value="{{ $apiUrl }}">
                        <button class="btn btn-light" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="ri-file-copy-line"></i></button>
                    </div>

                    <label class="form-label fs-13 text-muted mb-0">Key</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control font-monospace" readonly value="{{ $publicKey }}">
                        <button class="btn btn-light" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="ri-file-copy-line"></i></button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="ri-information-line me-1"></i>About</h5>
                </div>
                <div class="card-body fs-13 text-muted">
                    <div class="d-flex justify-content-between mb-1">
                        <span>CortenDesk</span><span class="font-monospace">v{{ config('cortendesk.api_version') }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>Laravel</span><span class="font-monospace">{{ app()->version() }}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>PHP</span><span class="font-monospace">{{ PHP_VERSION }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
