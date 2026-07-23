@extends('layouts.app')

@section('title', 'Devices')
@section('subtitle', 'Manage')

@section('content')
    @livewire(App\Livewire\DeviceList::class)
@endsection
