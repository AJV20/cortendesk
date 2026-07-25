<div class="navbar-custom">
    <div class="topbar container-fluid">
        <div class="d-flex align-items-center gap-lg-2 gap-1">

            <div class="logo-topbar">
                <a href="{{ route('overview') }}" class="logo-light">
                    <span class="logo-lg">
                        <img src="{{ asset('assets/images/cortendesk-logo-light.svg') }}" alt="CortenDesk" height="26">
                    </span>
                    <span class="logo-sm">
                        <img src="{{ asset('assets/images/cortendesk-sm.svg') }}" alt="CortenDesk" height="28">
                    </span>
                </a>
                <a href="{{ route('overview') }}" class="logo-dark">
                    <span class="logo-lg">
                        <img src="{{ asset('assets/images/cortendesk-logo-dark.svg') }}" alt="CortenDesk" height="26">
                    </span>
                    <span class="logo-sm">
                        <img src="{{ asset('assets/images/cortendesk-sm.svg') }}" alt="CortenDesk" height="28">
                    </span>
                </a>
            </div>

            <button class="button-toggle-menu">
                <i class="ri-menu-2-fill"></i>
            </button>
        </div>

        <ul class="topbar-menu d-flex align-items-center gap-3">

            @if (auth()->user()?->consoleAllows('setting') && ($newVersion = \App\Support\UpdateChecker::upgradeAvailable()))
                <li>
                    <a class="nav-link" href="{{ \App\Support\UpdateChecker::UPGRADE_DOC }}" target="_blank" rel="noopener"
                       data-bs-toggle="tooltip" data-bs-placement="bottom" title="Version {{ $newVersion }} is available — how to upgrade">
                        <span class="badge bg-warning-subtle text-warning">
                            <i class="ri-download-cloud-2-line align-middle me-1"></i>Upgrade Available
                        </span>
                    </a>
                </li>
            @endif

            <li class="d-none d-sm-inline-block">
                <div class="nav-link" id="light-dark-mode" data-bs-toggle="tooltip" data-bs-placement="left" title="Theme Mode">
                    <i class="ri-moon-line fs-22"></i>
                </div>
            </li>

            <li class="d-none d-md-inline-block">
                <a class="nav-link" href="" data-toggle="fullscreen">
                    <i class="ri-fullscreen-line fs-22"></i>
                </a>
            </li>

            <li class="dropdown">
                <a class="nav-link dropdown-toggle arrow-none nav-user px-2" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                    <span class="account-user-avatar">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white" style="width:32px;height:32px;font-weight:600;">
                            {{ strtoupper(substr(auth()->user()->username ?? 'A', 0, 1)) }}
                        </span>
                    </span>
                    <span class="d-lg-flex flex-column gap-1 d-none">
                        <h5 class="my-0">{{ auth()->user()?->displayName() }}</h5>
                        <h6 class="my-0 fw-normal">{{ auth()->user()?->is_admin ? 'Administrator' : (auth()->user()?->role?->name ?? 'User') }}</h6>
                    </span>
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-animated profile-dropdown">
                    <div class="dropdown-header noti-title">
                        <h6 class="text-overflow m-0">Welcome!</h6>
                    </div>

                    <a href="{{ route('account') }}" class="dropdown-item">
                        <i class="ri-account-circle-line fs-18 align-middle me-1"></i>
                        <span>My Account</span>
                    </a>

                    <a href="{{ route('account.two-factor') }}" class="dropdown-item">
                        <i class="ri-shield-keyhole-line fs-18 align-middle me-1"></i>
                        <span>Two-Factor Authentication</span>
                    </a>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item">
                            <i class="ri-logout-box-line fs-18 align-middle me-1"></i>
                            <span>Logout</span>
                        </button>
                    </form>
                </div>
            </li>
        </ul>
    </div>
</div>
