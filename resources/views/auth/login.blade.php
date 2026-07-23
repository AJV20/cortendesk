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

            <div class="text-center w-75 m-auto">
                <h4 class="text-dark-50 text-center pb-0 fw-bold">Sign In</h4>
                <p class="text-muted mb-4">Enter your username and password to access the console.</p>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

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

                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember" checked>
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>
                </div>

                <div class="mb-3 mb-0 text-center">
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="ri-login-circle-fill me-1"></i> Log In
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
