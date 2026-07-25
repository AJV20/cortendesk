@extends('layouts.app')

@section('title', 'Roles')
@section('subtitle', 'Manage')

@section('content')
    @livewire(App\Livewire\RoleList::class)
@endsection
