@extends('layouts.guest')

@section('title', $ok ? 'Signed In' : 'Sign-In Failed')

@section('content')
    <div class="card-body p-4 text-center">
        <div class="mb-3">
            @if ($ok)
                <i class="ri-checkbox-circle-line text-success" style="font-size: 3rem;"></i>
            @else
                <i class="ri-error-warning-line text-danger" style="font-size: 3rem;"></i>
            @endif
        </div>

        <h4 class="text-dark-50 pb-0 fw-bold">
            {{ $ok ? 'Signed in to RustDesk' : 'Sign-in failed' }}
        </h4>

        <p class="text-muted mt-3 mb-0">{{ $message }}</p>

        @unless ($ok)
            <p class="text-muted mt-3 mb-0 fs-13">
                Return to the RustDesk client and try again, or contact your administrator.
            </p>
        @endunless
    </div>
@endsection
