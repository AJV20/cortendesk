@extends('layouts.guest')

@section('title', 'Accept Invitation')

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
                <h4 class="text-dark-50 text-center pb-0 fw-bold">Set your password</h4>
                <p class="text-muted mb-4">You were invited to this console. Choose a password to finish setting up your account.</p>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('invite.accept', $token) }}">
                @csrf

                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input class="form-control" type="text" id="username" value="{{ $invitation->username }}" readonly disabled>
                    <div class="form-text">Chosen by your administrator — sign in with this, not your email.</div>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input class="form-control" type="text" id="email" value="{{ $invitation->email }}" readonly disabled>
                </div>

                <div class="mb-3">
                    <label for="name" class="form-label">Display name</label>
                    <input class="form-control" type="text" id="name" name="name"
                           value="{{ old('name', $invitation->name) }}" autocomplete="name" placeholder="Optional">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group input-group-merge">
                        <input type="password" id="password" name="password" class="form-control"
                               required autofocus autocomplete="new-password" placeholder="At least 8 characters">
                        <div class="input-group-text" data-password="false">
                            <span class="password-eye"></span>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">Confirm password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
                           required autocomplete="new-password" placeholder="Repeat the password">
                </div>

                <div class="mb-3 mb-0 text-center">
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="ri-user-add-line me-1"></i> Create my account
                    </button>
                </div>
            </form>

            <p class="text-muted fs-13 text-center mt-3 mb-0">
                This link works once and expires {{ $invitation->expires_at->diffForHumans() }}.
            </p>
        </div>
    </div>
@endsection
