@extends('layouts.app')

@section('title', 'Login Log')
@section('subtitle', 'Logs')

@section('content')
    @livewire(App\Livewire\LoginLogList::class)
@endsection
