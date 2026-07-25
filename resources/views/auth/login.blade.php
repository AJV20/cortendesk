@extends('layouts.guest')

@section('title', 'Sign In')

@section('content')
    <div class="card">

        <div class="card-header py-3 text-center d-flex align-items-center justify-content-center"
             style="background:radial-gradient(120% 140% at 50% 0%,#233149 0%,#141c2b 60%,#0f1622 100%);border-bottom:2px solid #e2652e;">
            <a href="{{ url('/') }}" class="auth-brand mb-0">
                <img src="{{ asset('assets/images/cortendesk-sm.svg') }}" alt="CortenDesk" width="60" height="60" class="auth-brand-logo">
                <span class="auth-brand-wordmark">Corten<span>Desk</span></span>
            </a>
        </div>

        <div class="card-body p-4">

            @php
                $oidc = app(\App\Services\OidcService::class);
                $ssoEnabled = $oidc->isEnabled();
                $passwordDisabled = $oidc->localLoginDisabled();
            @endphp

            <div class="text-center w-75 m-auto">
                <h4 class="text-dark-50 text-center pb-0 fw-bold">Sign In</h4>
                <p class="text-muted mb-4">
                    {{ $passwordDisabled
                        ? 'Use your organisation account to access the console.'
                        : 'Enter your username and password to access the console.' }}
                </p>
            </div>

            @if (session('status'))
                <div class="alert alert-success" role="alert">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            @if ($ssoEnabled)
                <div class="mb-3 d-grid">
                    <a href="{{ route('login.oidc') }}" class="btn btn-outline-primary">
                        <i class="ri-shield-user-line me-1"></i> {{ $oidc->buttonLabel() }}
                    </a>
                </div>

                @unless ($passwordDisabled)
                    <div class="d-flex align-items-center text-muted my-3">
                        <hr class="flex-grow-1 my-0">
                        <span class="px-2 fs-13">or</span>
                        <hr class="flex-grow-1 my-0">
                    </div>
                @endunless
            @endif

            @unless ($passwordDisabled)
            <form method="POST" action="{{ route('login.attempt') }}">
                @csrf

                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input class="form-control" type="text" id="username" name="username"
                           value="{{ old('username') }}" required autofocus autocomplete="username"
                           placeholder="Enter your username">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group input-group-merge">
                        <input type="password" id="password" name="password" class="form-control"
                               required autocomplete="current-password" placeholder="Enter your password">
                        <div class="input-group-text" data-password="false">
                            <span class="password-eye"></span>
                        </div>
                    </div>
                </div>

                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember" checked>
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                    {{-- Only offered when a relay exists to deliver the link. --}}
                    @if (app(\App\Services\MailSettings::class)->isEnabled())
                        <a href="{{ route('password.request') }}" class="text-muted fs-13">Forgot password?</a>
                    @endif
                </div>

                <div class="mb-3 mb-0 text-center">
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="ri-login-circle-fill me-1"></i> Log In
                    </button>
                </div>
            </form>
            @endunless
        </div>
    </div>
@endsection
