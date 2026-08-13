<div wire:poll.10s="refreshDeviceStatus">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1">Set up your first RustDesk device</h4>
            <p class="text-muted mb-0">These values come from the same saved server settings used by CortenDesk's client API.</p>
        </div>
        @if (auth()->user()?->consoleAllows('setting', 'rw'))
            <button type="button" class="btn btn-sm btn-outline-light" wire:click="dismiss">Do this later</button>
        @endif
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">1. Copy the client settings</h5></div>
                <div class="card-body">
                    @php
                        $clientSettings = "ID Server: {$idServer}\nRelay Server: {$relayServer}\nAPI Server: {$apiUrl}\nKey: {$publicKey}";
                    @endphp
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <p class="mb-0">In RustDesk, open <strong>Settings → Network → ID/Relay Server</strong> and enter:</p>
                        <button class="btn btn-sm btn-outline-light" type="button"
                                onclick="navigator.clipboard.writeText(document.getElementById('client-settings-copy-all').value); this.setAttribute('aria-label', 'All client settings copied');">
                            <i class="ri-file-copy-line me-1"></i>Copy all
                        </button>
                        <textarea id="client-settings-copy-all" class="visually-hidden" tabindex="-1" aria-hidden="true">{{ $clientSettings }}</textarea>
                    </div>
                    @foreach (['ID Server' => $idServer, 'Relay Server' => $relayServer, 'API Server' => $apiUrl, 'Key' => $publicKey] as $label => $value)
                        <label class="form-label fs-13 text-muted mb-0">{{ $label }}</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control rd-mono" readonly value="{{ $value }}">
                            <button class="btn btn-light" type="button" title="Copy {{ $label }}"
                                    onclick="navigator.clipboard.writeText(this.previousElementSibling.value); this.setAttribute('aria-label', '{{ $label }} copied');">
                                <i class="ri-file-copy-line"></i><span class="visually-hidden">Copy {{ $label }}</span>
                            </button>
                        </div>
                    @endforeach

                    @if ($idServer === '' || $relayServer === '' || $apiUrl === '' || $publicKey === '')
                        <div class="alert alert-warning mb-0 mt-3">
                            One or more values are missing. <a class="alert-link" href="{{ route('settings', ['tab' => 'server']) }}">Complete Server settings</a> first.
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">2. Confirm the first heartbeat</h5></div>
                <div class="card-body">
                    @if ($deviceConnected)
                        <div class="alert alert-success mb-3"><i class="ri-check-line me-1"></i>A device has checked in.</div>
                    @else
                        <div class="alert alert-info mb-3"><i class="ri-loader-4-line me-1"></i>Waiting for a device to check in. This page checks every 10 seconds.</div>
                    @endif
                    @error('device')<div class="alert alert-danger">{{ $message }}</div>@enderror
                    @if (auth()->user()?->consoleAllows('setting', 'rw'))
                        <button type="button" class="btn btn-primary" wire:click="complete" @disabled(! $deviceConnected)>
                            Finish setup
                        </button>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">3. Recommended security</h5></div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-3"><i class="{{ $approvalEnabled ? 'ri-checkbox-circle-line text-success' : 'ri-error-warning-line text-warning' }} me-1"></i><strong>Device approval</strong><br><span class="text-muted fs-13">Hold new devices for review before they appear in the fleet.</span></li>
                        <li><i class="{{ $twoFactorRequired ? 'ri-checkbox-circle-line text-success' : 'ri-error-warning-line text-warning' }} me-1"></i><strong>Administrator 2FA</strong><br><span class="text-muted fs-13">Protect console access with an authenticator and recovery codes.</span></li>
                    </ul>
                    @if (! $approvalEnabled || ! $twoFactorRequired)
                        <a href="{{ route('settings', ['tab' => 'security']) }}" class="btn btn-sm btn-outline-light mt-3">Review security settings</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>