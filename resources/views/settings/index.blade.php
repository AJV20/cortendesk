@extends('layouts.app')

@section('title', 'Settings')
@section('subtitle', 'System')

@section('content')
    @livewire(App\Livewire\SettingsPage::class)
@endsection
