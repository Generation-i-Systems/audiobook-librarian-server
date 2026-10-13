@extends('layouts.app')

@section('content')
    <div class="container py-5 text-center">
        <h1 class="h3 mb-3"><i class="fas fa-hdd me-2"></i>{{ __('Book storage is unavailable') }}</h1>
        <p class="text-muted mb-4">{{ $message }}</p>
        <a href="{{ url('/') }}" class="btn btn-primary me-2">{{ __('Go to the library') }}</a>
        <a href="javascript:location.reload()" class="btn btn-outline-secondary">{{ __('Try again') }}</a>
    </div>
@endsection
