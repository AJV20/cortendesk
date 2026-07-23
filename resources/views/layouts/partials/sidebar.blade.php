<!-- ========== Left Sidebar Start ========== -->
<div class="leftside-menu">

    <a href="{{ route('overview') }}" class="logo logo-light">
        <span class="logo-lg">
            <img src="{{ asset('assets/images/cortendesk-logo-light.svg') }}" alt="CortenDesk" height="24"/>
        </span>
        <span class="logo-sm">
            <img src="{{ asset('assets/images/cortendesk-sm.svg') }}" alt="CortenDesk" height="52" class="mx-auto d-block"/>
        </span>
    </a>

    <a href="{{ route('overview') }}" class="logo logo-dark">
        <span class="logo-lg">
            <img src="{{ asset('assets/images/cortendesk-logo-dark.svg') }}" alt="CortenDesk" height="24"/>
        </span>
        <span class="logo-sm">
            <img src="{{ asset('assets/images/cortendesk-sm.svg') }}" alt="CortenDesk" height="52" class="mx-auto d-block"/>
        </span>
    </a>

    <div class="button-sm-hover" data-bs-toggle="tooltip" data-bs-placement="right" title="Show Full Sidebar">
        <i class="ri-checkbox-blank-circle-line align-middle"></i>
    </div>

    <div class="button-close-fullsidebar">
        <i class="ri-close-fill align-middle"></i>
    </div>

    <div class="h-100" id="leftside-menu-container" data-simplebar>

        <ul class="side-nav">

            <li class="side-nav-item {{ request()->routeIs('overview') ? 'menuitem-active' : '' }}">
                <a href="{{ route('overview') }}" class="side-nav-link {{ request()->routeIs('overview') ? 'active' : '' }}">
                    <i class="ri-home-4-line"></i>
                    <span> Overview </span>
                </a>
            </li>

            <li class="side-nav-title">Manage</li>

            <li class="side-nav-item {{ request()->routeIs('devices') ? 'menuitem-active' : '' }}">
                <a href="{{ route('devices') }}" class="side-nav-link {{ request()->routeIs('devices') ? 'active' : '' }}">
                    <i class="ri-computer-line"></i>
                    <span class="badge bg-success float-end" id="sidebar-online-count"></span>
                    <span> Devices </span>
                </a>
            </li>

            <li class="side-nav-item {{ request()->routeIs('address-books') ? 'menuitem-active' : '' }}">
                <a href="{{ route('address-books') }}" class="side-nav-link {{ request()->routeIs('address-books') ? 'active' : '' }}">
                    <i class="ri-contacts-book-2-line"></i>
                    <span> Address Books </span>
                </a>
            </li>

            @if (config('cortendesk.native_webclient'))
                <li class="side-nav-item">
                    <a href="{{ route('webclient') }}" target="cortendesk-webclient" rel="noopener" class="side-nav-link">
                        <i class="ri-global-line"></i>
                        <span> Web Client </span>
                    </a>
                </li>
            @elseif (config('cortendesk.webclient_url'))
                <li class="side-nav-item">
                    <a href="{{ config('cortendesk.webclient_url') }}" target="_blank" rel="noopener" class="side-nav-link">
                        <i class="ri-global-line"></i>
                        <span> Web Client </span>
                    </a>
                </li>
            @endif

            @if (auth()->user()?->is_admin)
                <li class="side-nav-item {{ request()->routeIs('groups') ? 'menuitem-active' : '' }}">
                    <a href="{{ route('groups') }}" class="side-nav-link {{ request()->routeIs('groups') ? 'active' : '' }}">
                        <i class="ri-group-line"></i>
                        <span> Groups </span>
                    </a>
                </li>

                <li class="side-nav-item {{ request()->routeIs('users') ? 'menuitem-active' : '' }}">
                    <a href="{{ route('users') }}" class="side-nav-link {{ request()->routeIs('users') ? 'active' : '' }}">
                        <i class="ri-user-settings-line"></i>
                        <span> Users </span>
                    </a>
                </li>
            @endif

            <li class="side-nav-title">Monitor</li>

            <li class="side-nav-item {{ request()->routeIs('logs.*') ? 'menuitem-active' : '' }}">
                <a data-bs-toggle="collapse" href="#sidebarLogs" aria-expanded="{{ request()->routeIs('logs.*') ? 'true' : 'false' }}" aria-controls="sidebarLogs" class="side-nav-link">
                    <i class="ri-file-list-3-line"></i>
                    <span> Logs </span>
                    <span class="menu-arrow"></span>
                </a>
                <div class="collapse {{ request()->routeIs('logs.*') ? 'show' : '' }}" id="sidebarLogs">
                    <ul class="side-nav-second-level">
                        <li class="{{ request()->routeIs('logs.connections') ? 'menuitem-active' : '' }}"><a href="{{ route('logs.connections') }}">Connections</a></li>
                        <li class="{{ request()->routeIs('logs.file-transfers') ? 'menuitem-active' : '' }}"><a href="{{ route('logs.file-transfers') }}">File Transfers</a></li>
                        @if (auth()->user()?->is_admin)
                            <li class="{{ request()->routeIs('logs.logins') ? 'menuitem-active' : '' }}"><a href="{{ route('logs.logins') }}">Logins</a></li>
                        @endif
                    </ul>
                </div>
            </li>

            @if (auth()->user()?->is_admin)
                <li class="side-nav-title">System</li>

                <li class="side-nav-item {{ request()->routeIs('settings') ? 'menuitem-active' : '' }}">
                    <a href="{{ route('settings') }}" class="side-nav-link {{ request()->routeIs('settings') ? 'active' : '' }}">
                        <i class="ri-settings-3-line"></i>
                        <span> Settings </span>
                    </a>
                </li>

                @php
                    $rdgenUrl = \App\Models\Setting::get('rdgen_url', config('cortendesk.rdgen_url'));
                @endphp
                @if ($rdgenUrl)
                    <li class="side-nav-item">
                        <a href="{{ $rdgenUrl }}" target="_blank" rel="noopener" class="side-nav-link">
                            <i class="ri-install-line"></i>
                            <span> Build Installers </span>
                        </a>
                    </li>
                @endif
            @endif
        </ul>

        <div class="clearfix"></div>
    </div>
</div>
<!-- ========== Left Sidebar End ========== -->
