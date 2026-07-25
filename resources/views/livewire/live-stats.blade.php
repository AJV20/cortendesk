<div class="row" wire:poll.15s>
    <div class="col-xxl-3 col-sm-6">
        <a href="{{ route('devices') }}" class="card text-reset card-hover" title="View all devices">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-0">{{ $devices }} <span class="text-muted fw-normal fs-16">Devices</span></h4>
                        <p class="mb-0 mt-1">
                            <i class="ri-checkbox-blank-circle-fill text-success fs-10 me-1"></i>
                            <span class="text-muted">{{ $online }} online now</span>
                        </p>
                    </div>
                    <div class="avatar-sm">
                        <span class="avatar-title bg-primary-subtle text-primary rounded fs-22">
                            <i class="ri-computer-line"></i>
                        </span>
                    </div>
                </div>
            </div>
        </a>
    </div>
    @if ($admin)
        <div class="col-xxl-3 col-sm-6">
            <a href="{{ route('users') }}" class="card text-reset card-hover" title="Manage users">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0">{{ $users }}</h4>
                            <p class="text-muted mb-0">Users</p>
                        </div>
                        <div class="avatar-sm">
                            <span class="avatar-title bg-info-subtle text-info rounded fs-22">
                                <i class="ri-group-line"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endif
    <div class="col-xxl-3 col-sm-6">
        <a href="{{ route('logs.connections') }}" class="card text-reset card-hover" title="View connection log">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-0">{{ $connectionsToday }}</h4>
                        <p class="text-muted mb-0">Connections today</p>
                    </div>
                    <div class="avatar-sm">
                        <span class="avatar-title bg-warning-subtle text-warning rounded fs-22">
                            <i class="ri-links-line"></i>
                        </span>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xxl-3 col-sm-6">
        <a href="{{ route('logs.alarms') }}" class="card text-reset card-hover" title="View alarm log">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-0">{{ $alarms24h }}</h4>
                        <p class="text-muted mb-0">Alarms (24h)</p>
                    </div>
                    <div class="avatar-sm">
                        <span class="avatar-title bg-danger-subtle text-danger rounded fs-22">
                            <i class="ri-alarm-warning-line"></i>
                        </span>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>
