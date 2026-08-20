<div wire:poll.10s="refreshDeviceStatus">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1">Set up your first RustDesk device</h4>
            <p class="text-muted mb-0">Two steps: configure a client, then confirm it checks in.</p>
        </div>
        @if (auth()->user()?->consoleAllows('setting', 'rw'))
            <button type="button" class="btn btn-sm btn-outline-light" wire:click="dismiss">Do this later</button>
        @endif
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">1. Point a client at this console</h5></div>
                <div class="card-body">
                    {{-- Settings → Client Setup already lists these four values
                         with copy buttons. Repeating them here would mean two
                         screens to keep in step. --}}
                    <p>In RustDesk, open <strong>Settings → Network → ID/Relay Server</strong> and enter your
                        ID server, relay server, key and API server.</p>
                    @if ($idServer === '' || $relayServer === '' || $apiUrl === '' || $publicKey === '')
                        <div class="alert alert-warning">
                            One or more of those values is not set yet.
                            <a class="alert-link" href="{{ route('settings', ['tab' => 'server']) }}">Complete Server settings</a> first.
                        </div>
                    @endif
                    <a class="btn btn-outline-light" href="{{ route('settings', ['tab' => 'client']) }}">
                        <i class="ri-file-copy-line me-1"></i>Copy them from Client Setup
                    </a>
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