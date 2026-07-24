@extends('layouts.app')

@section('title', 'Alarm Log')
@section('subtitle', 'Logs')

@section('content')
    @livewire(App\Livewire\AlarmLogList::class)
@endsection
