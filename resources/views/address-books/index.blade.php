@extends('layouts.app')

@section('title', 'Address Books')
@section('subtitle', 'Manage')

@section('content')
    @livewire(App\Livewire\AddressBookManager::class)
@endsection
