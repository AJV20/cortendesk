@extends('layouts.app')

@section('title', 'First-run setup')

@section('content')
    @livewire(App\Livewire\SetupWizard::class)
@endsection