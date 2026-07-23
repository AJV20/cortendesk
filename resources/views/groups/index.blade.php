@extends('layouts.app')

@section('title', 'Groups')
@section('subtitle', 'Manage')

@section('content')
    @livewire(App\Livewire\GroupList::class)
@endsection
