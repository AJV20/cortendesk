@extends('layouts.app')

@section('title', 'Strategies')
@section('subtitle', 'Manage')

@section('content')
    @livewire(App\Livewire\StrategyList::class)
@endsection
