@extends('layouts.app')

@section('title', 'Device')
@section('subtitle', 'Manage')

@section('content')
    <div class="mb-3">
        <a href="{{ route('devices') }}" class="fs-13 text-muted"><i class="ri-arrow-left-line me-1"></i>Back to Devices</a>
    </div>
    @livewire(App\Livewire\DeviceDetail::class, ['deviceId' => $device], key('device-detail-'.$device))
@endsection
