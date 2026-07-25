@extends('layouts.guest')

@section('title', 'Reset Password')

@section('content')
    <div class="card-body p-4">
        <div class="text-center w-75 m-auto">
            <h4 class="text-dark-50 text-center pb-0 fw-bold">Reset Password</h4>
            <p class="text-muted mb-4">Enter your username or email address and we'll send you a link to choose a new password.</p>
        </div>

        @if (session('status'))
            <div class="alert alert-success" role="alert">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <div class="mb-3">
                <label for="login" class="form-label">Username or email</label>
                <input class="form-control" type="text" id="login" name="login"
                       value="{{ old('login') }}" required autofocus autocomplete="username">
            </div>

            <div class="mb-3 text-center">
                <button class="btn btn-primary w-100" type="submit">
                    <i class="ri-mail-send-line me-1"></i> Send Reset Link
                </button>
            </div>
        </form>

        <div class="text-center">
            <a href="{{ route('login') }}" class="text-muted fs-13"><i class="ri-arrow-left-line me-1"></i>Back to sign in</a>
        </div>
    </div>
@endsection
