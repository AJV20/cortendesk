@extends('layouts.app')

@section('title', 'Two-Factor Authentication')
@section('subtitle', 'Account')

@section('content')
    @if (session('twofactor_enforced'))
        <div class="alert alert-warning">
            <i class="ri-shield-keyhole-line me-1"></i>{{ session('twofactor_enforced') }}
        </div>
    @endif

    <div class="row justify-content-center">
        <div class="col-lg-8">
            @livewire(App\Livewire\TwoFactorSettings::class)
        </div>
    </div>
@endsection
