<!DOCTYPE html>
<html lang="en" data-bs-theme="dark" data-menu-color="dark" data-topbar-color="dark" data-layout-mode="fluid" data-layout-position="fixed" data-sidenav-size="default">
<head>
    <meta charset="utf-8"/>
    <title>@yield('title', 'Console') | {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="CortenDesk — self-hosted RustDesk server console"/>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ \App\Support\Asset::url('assets/images/cortendesk-sm.svg') }}">

    <!-- Theme Config Js -->
    <script src="{{ \App\Support\Asset::url('assets/js/config.js') }}"></script>

    <!-- App css -->
    <link href="{{ \App\Support\Asset::url('assets/css/app.min.css') }}" rel="stylesheet" type="text/css" id="app-style"/>

    <!-- Icons css -->
    <link href="{{ \App\Support\Asset::url('assets/css/icons.min.css') }}" rel="stylesheet" type="text/css"/>

    <!-- CortenDesk custom css -->
    <link href="{{ \App\Support\Asset::url('assets/css/cortendesk.css') }}" rel="stylesheet" type="text/css"/>
</head>

<body>
    <div class="wrapper">

        @include('layouts.partials.topbar')

        @include('layouts.partials.sidebar')

        <div class="content-page">
            <div class="content">
                <div class="container-fluid">

                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box">
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="{{ route('overview') }}">CortenDesk</a></li>
                                        @hasSection('subtitle')
                                            <li class="breadcrumb-item"><a href="javascript:void(0);">@yield('subtitle')</a></li>
                                        @endif
                                        <li class="breadcrumb-item active">@yield('title')</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">@yield('title')</h4>
                            </div>
                        </div>
                    </div>

                    @yield('content')

                </div>
            </div>

            <footer class="footer">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-md-6">
                            <script>document.write(new Date().getFullYear())</script> © CortenDesk
                        </div>
                        <div class="col-md-6">
                            <div class="text-md-end footer-links d-none d-md-block">
                                <span class="text-muted">Self-hosted RustDesk console</span>
                            </div>
                        </div>
                    </div>
                </div>
            </footer>
        </div>

    </div>

    <!-- Vendor js -->
    <script src="{{ \App\Support\Asset::url('assets/js/vendor.min.js') }}"></script>

    <!-- App js -->
    <script src="{{ \App\Support\Asset::url('assets/js/app.min.js') }}"></script>

    <!-- CortenDesk helpers (ours, not the theme's) -->
    <script src="{{ \App\Support\Asset::url('assets/js/cortendesk.js') }}"></script>

    @stack('scripts')
</body>
</html>
