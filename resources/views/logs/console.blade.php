@extends('layouts.app')

@section('title', 'Console Audit')
@section('subtitle', 'Logs')

@section('content')
    @livewire(App\Livewire\ConsoleAuditList::class)
@endsection
