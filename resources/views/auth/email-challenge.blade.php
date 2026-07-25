@extends('layouts.guest')

@section('title', 'Verify Sign-In')

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
                <h4 class="text-dark-50 text-center pb-0 fw-bold">Check your email</h4>
                <p class="text-muted mb-4">
                    This browser is new to the console, so we emailed a 6-digit code
                    @if ($sentTo)
                        to <span class="font-monospace">{{ $sentTo }}</span>
                    @endif
                    . Enter it below.
                </p>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            @if (session('status'))
                <div class="alert alert-success" role="alert">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('login.email.attempt') }}">
                @csrf

                <div class="mb-3">
                    <label for="code" class="form-label">Verification code</label>
                    <input class="form-control form-control-lg text-center font-monospace" type="text" id="code" name="code"
                           required autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="6"
                           placeholder="123456">
                </div>

                <div class="mb-3 mb-0 text-center">
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="ri-mail-check-line me-1"></i> Verify
                    </button>
                </div>
            </form>

            <form method="POST" action="{{ route('login.email.resend') }}" class="text-center mt-3">
                @csrf
                <button type="submit" class="btn btn-link btn-sm text-muted p-0">Send a new code</button>
            </form>

            <div class="text-center mt-2">
                <a href="{{ route('login') }}" class="btn btn-link btn-sm text-muted p-0">Cancel and sign in as someone else</a>
            </div>

            <p class="text-muted fs-13 text-center mt-3 mb-0">
                Once verified, this browser is remembered for {{ \App\Models\TrustedDevice::trustDays() }} days.
            </p>
        </div>
    </div>
@endsection
