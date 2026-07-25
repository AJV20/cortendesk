@extends('layouts.guest')

@section('title', 'Choose a New Password')

@section('content')
    <div class="card-body p-4">
        <div class="text-center w-75 m-auto">
            <h4 class="text-dark-50 text-center pb-0 fw-bold">Choose a New Password</h4>
            <p class="text-muted mb-4">This link can be used once. Signing in elsewhere will end when you save.</p>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.update', ['token' => $token]) }}">
            @csrf
            <div class="mb-3">
                <label for="password" class="form-label">New password</label>
                <input type="password" id="password" name="password" class="form-control"
                       required autofocus autocomplete="new-password">
                <div class="form-text">At least 8 characters.</div>
            </div>

            <div class="mb-3">
                <label for="password_confirmation" class="form-label">Confirm new password</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       class="form-control" required autocomplete="new-password">
            </div>

            <div class="mb-3 text-center">
                <button class="btn btn-primary w-100" type="submit">
                    <i class="ri-key-2-line me-1"></i> Set Password
                </button>
            </div>
        </form>
    </div>
@endsection
