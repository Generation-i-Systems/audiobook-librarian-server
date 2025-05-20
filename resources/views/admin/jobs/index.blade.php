@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Job Queue</h5>
                    <div class="btn-group">
                        <a href="{{ route('admin.jobs.index', ['type' => 'directory_import']) }}" 
                           class="btn btn-outline-primary btn-sm {{ request('type') == 'directory_import' ? 'active' : '' }}">
                            Directory Imports
                        </a>
                        <a href="{{ route('admin.jobs.index', ['type' => 'book_import']) }}" 
                           class="btn btn-outline-primary btn-sm {{ request('type') == 'book_import' ? 'active' : '' }}">
                            Book Imports
                        </a>
                        <a href="{{ route('admin.jobs.index', ['status' => 'failed']) }}" 
                           class="btn btn-outline-danger btn-sm {{ request('status') == 'failed' ? 'active' : '' }}">
                            Failed Jobs
                        </a>
                        <a href="{{ route('admin.jobs.index') }}" 
                           class="btn btn-outline-secondary btn-sm {{ !request('type') && !request('status') ? 'active' : '' }}">
                            All Jobs
                        </a>
                    </div>
                </div>

                <div class="card-body">
                    @if (session('status'))
                        <div class="alert alert-success" role="alert">
                            {{ session('status') }}
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Message</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($jobs as $job)
                                    <tr>
                                        <td title="{{ $job['id'] }}">
                                            {{ substr($job['id'], 0, 8) }}...
                                        </td>
                                        <td>
                                            @php
                                                $typeClass = [
                                                    'directory_import' => 'primary',
                                                    'book_import' => 'info',
                                                    'quota_failure' => 'danger'
                                                ][$job['type'] ?? ''] ?? 'secondary';
                                            @endphp
                                            <span class="badge bg-{{ $typeClass }}">
                                                {{ ucfirst(str_replace('_', ' ', $job['type'] ?? 'unknown')) }}
                                            </span>
                                        </td>
                                        <td>
                                            @php
                                                $statusClass = [
                                                    'queued' => 'warning',
                                                    'processing' => 'info',
                                                    'completed' => 'success',
                                                    'failed' => 'danger'
                                                ][$job['status']] ?? 'secondary';
                                            @endphp
                                            <span class="badge bg-{{ $statusClass }}">
                                                {{ ucfirst($job['status']) }}
                                            </span>
                                        </td>
                                        <td>
                                            {{ $job['message'] ?? '—' }}
                                            @if(isset($job['details']['directory']))
                                                <div class="text-muted small">
                                                    {{ $job['details']['directory'] }}
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            {{ \Carbon\Carbon::parse($job['created_at'] ?? now())->diffForHumans() }}
                                            <div class="text-muted small">
                                                {{ \Carbon\Carbon::parse($job['created_at'] ?? now())->format('Y-m-d H:i:s') }}
                                            </div>
                                        </td>
                                        <td>
                                            <a href="{{ route('admin.jobs.show', $job['id']) }}" 
                                               class="btn btn-sm btn-outline-primary">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center">
                                            No jobs found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($jobs->hasPages())
                        <div class="d-flex justify-content-center mt-4">
                            {{ $jobs->appends(request()->query())->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-refresh the page every 30 seconds if there are any processing/queued jobs
        @if($jobs->contains('status', 'processing') || $jobs->contains('status', 'queued'))
            setTimeout(function() {
                window.location.reload();
            }, 30000);
        @endif
    });
</script>
@endpush

@endsection
