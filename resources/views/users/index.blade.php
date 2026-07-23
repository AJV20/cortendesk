@extends('layouts.app')

@section('title', 'Users')
@section('subtitle', 'Manage')

@section('content')
    @livewire(App\Livewire\UserList::class)
@endsection
