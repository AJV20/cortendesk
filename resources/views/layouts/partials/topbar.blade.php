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

        @php
            $rdUser = auth()->user();
            $rdRole = $rdUser?->is_admin ? 'Administrator' : ($rdUser?->role?->name ?? 'User');
        @endphp

        <ul class="topbar-menu d-flex align-items-center">

            @if ($rdUser?->consoleAllows('setting') && ($newVersion = \App\Support\UpdateChecker::upgradeAvailable()))
                <li class="rd-topbar-upgrade">
                    <a class="nav-link" href="{{ \App\Support\UpdateChecker::UPGRADE_DOC }}" target="_blank" rel="noopener"
                       data-bs-toggle="tooltip" data-bs-placement="bottom" title="Version {{ $newVersion }} is available — how to upgrade">
                        {{-- The widest thing in the bar at ~150px. Below sm the icon carries
                             it alone; the tooltip and the sidebar footer still name the
                             version, so nothing is lost. --}}
                        <span class="rd-shell-badge">
                            <i class="ri-download-cloud-2-line"></i><span class="d-none d-sm-inline">Upgrade Available</span>
                        </span>
                    </a>
                </li>
            @endif

            {{-- Reachable at every width. It used to drop below sm, which left the
                 light palette unreachable on a phone — the one screen size where
                 someone is most likely to be outdoors and want it. --}}
            <li>
                <div class="nav-link rd-topbar-btn" id="light-dark-mode" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Theme Mode">
                    <i class="ri-moon-line"></i>
                </div>
            </li>

            <li class="d-none d-md-inline-block">
                <a class="nav-link rd-topbar-btn" href="" data-toggle="fullscreen" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Full Screen">
                    <i class="ri-fullscreen-line"></i>
                </a>
            </li>

            <li class="dropdown">
                <a class="nav-link dropdown-toggle arrow-none nav-user" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                    <span class="account-user-avatar">
                        <span class="rd-avatar rd-tone-accent">
                            {{ strtoupper(substr($rdUser->username ?? 'A', 0, 1)) }}
                        </span>
                    </span>
                    <span class="d-lg-flex flex-column d-none rd-topbar-identity">
                        <h5 class="rd-topbar-name">{{ $rdUser?->displayName() }}</h5>
                        <h6 class="rd-topbar-role">{{ $rdRole }}</h6>
                    </span>
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-animated profile-dropdown">
                    <div class="dropdown-header">
                        <h6 class="rd-menu-name text-truncate">{{ $rdUser?->displayName() }}</h6>
                        <span class="rd-menu-role">{{ $rdRole }}</span>
                    </div>

                    <a href="{{ route('account') }}" class="dropdown-item">
                        <i class="ri-account-circle-line"></i>
                        <span>My Account</span>
                    </a>

                    <a href="{{ route('account.two-factor') }}" class="dropdown-item">
                        <i class="ri-shield-keyhole-line"></i>
                        <span>Two-Factor Authentication</span>
                    </a>

                    <div class="dropdown-divider"></div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item">
                            <i class="ri-logout-box-line"></i>
                            <span>Logout</span>
                        </button>
                    </form>
                </div>
            </li>
        </ul>
    </div>
</div>
