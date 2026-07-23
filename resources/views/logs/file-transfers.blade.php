@extends('layouts.app')

@section('title', 'File Transfer Log')
@section('subtitle', 'Logs')

@section('content')
    @livewire(App\Livewire\FileTransferLog::class)
@endsection
