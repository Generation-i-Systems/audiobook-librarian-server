@extends('layouts.app')

@section('content')
<div class="container-fluid px-4">
    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span><i class="fas fa-database me-2"></i>{{ __('Database Administration') }}</span>
            <a href="{{ route('admin.adminer') }}" target="_blank" class="btn btn-sm btn-outline-light">
                <i class="fas fa-external-link-alt me-1"></i>Open in New Tab
            </a>
        </div>
        <div class="card-body p-0">
            {{-- Adminer iframe --}}
            <iframe src="{{ route('admin.adminer') }}" style="width: 100%; height: 75vh; border: none; display: block;"></iframe>
        </div>
    </div>
</div>
@endsection
