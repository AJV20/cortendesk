<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8"/>
    <title>@yield('title', 'Sign In') | {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset('assets/images/cortendesk-sm.svg') }}">

    <script src="{{ asset('assets/js/config.js') }}"></script>
    <link href="{{ asset('assets/css/app.min.css') }}" rel="stylesheet" type="text/css" id="app-style"/>
    <link href="{{ asset('assets/css/icons.min.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ asset('assets/css/cortendesk.css') }}" rel="stylesheet" type="text/css"/>
</head>

<body class="authentication-bg position-relative">

    <div class="account-pages pt-2 pt-sm-5 pb-4 pb-sm-5 position-relative">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xxl-4 col-lg-5">

                    @yield('content')

                    <div class="text-center mt-3">
                        <p class="text-muted fs-13">CortenDesk — self-hosted RustDesk console</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('assets/js/vendor.min.js') }}"></script>
    <script src="{{ asset('assets/js/app.min.js') }}"></script>
</body>
</html>
