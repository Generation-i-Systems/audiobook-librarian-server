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

                    @if(!isset($jobs) || $jobs->isEmpty())
                        <div class="alert alert-info">
                            No jobs found.
                        </div>
                    @else
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
                                    @foreach($jobs as $job)
                                        <tr>
                                            <td title="{{ e($job['id'] ?? 'N/A') }}">
                                                {{ isset($job['id']) ? substr($job['id'], 0, 8) . '...' : 'N/A' }}
                                            </td>
                                            <td>
                                                @php
                                                    $type = $job['type'] ?? 'unknown';
                                                    $typeClass = [
                                                        'directory_import' => 'primary',
                                                        'book_import' => 'info',
                                                        'quota_failure' => 'danger'
                                                    ][$type] ?? 'secondary';
                                                @endphp
                                                <span class="badge bg-{{ $typeClass }}">
                                                    {{ ucfirst(str_replace('_', ' ', $type)) }}
                                                </span>
                                            </td>
                                            <td>
                                                @php
                                                    $status = $job['status'] ?? 'unknown';
                                                    $statusClass = [
                                                        'queued' => 'warning',
                                                        'processing' => 'info',
                                                        'completed' => 'success',
                                                        'failed' => 'danger'
                                                    ][$status] ?? 'secondary';
                                                @endphp
                                                <span class="badge bg-{{ $statusClass }}">
                                                    {{ ucfirst($status) }}
                                                </span>
                                            </td>
                                            <td>
                                                {{ e($job['message'] ?? '—') }}
                                                @if(!empty($job['details']['directory']))
                                                    <div class="text-muted small">
                                                        {{ e($job['details']['directory']) }}
                                                    </div>
                                                @endif
                                            </td>
                                            <td>
                                                @php
                                                    $createdAt = $job['created_at'] ?? now();
                                                    $date = \Carbon\Carbon::parse($createdAt);
                                                @endphp
                                                <span title="{{ $date->format('Y-m-d H:i:s') }}">
                                                    {{ $date->diffForHumans() }}
                                                </span>
                                                <div class="text-muted small">
                                                    {{ $date->format('Y-m-d H:i:s') }}
                                                </div>
                                            </td>
                                            <td>
                                                @if(!empty($job['id']))
                                                    <a href="{{ route('admin.jobs.show', $job['id']) }}" 
                                                       class="btn btn-sm btn-outline-primary"
                                                       title="View job details">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if(method_exists($jobs, 'hasPages') && $jobs->hasPages())
                            <div class="d-flex justify-content-center mt-4">
                                {{ $jobs->appends(request()->query())->links('pagination::bootstrap-5') }}
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-refresh the page every 30 seconds if there are any processing/queued jobs
        @if(isset($jobs) && ($jobs->contains('status', 'processing') || $jobs->contains('status', 'queued')))
            setTimeout(function() {
                window.location.reload();
            }, 30000);
        @endif
    });
</script>
@endpush
