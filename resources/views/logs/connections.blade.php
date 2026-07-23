@extends('layouts.app')

@section('title', 'Connection Log')
@section('subtitle', 'Logs')

@section('content')
    @livewire(App\Livewire\ConnectionLog::class)
@endsection
