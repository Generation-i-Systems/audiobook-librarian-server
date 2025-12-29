@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <a href="{{ route('admin.jobs.index') }}" class="text-decoration-none">
                                <i class="fas fa-arrow-left me-2"></i>
                            </a>
                            Job Details
                        </h5>
                        <div>
                            @if(in_array($job['status'], ['queued', 'failed']))
                                <form action="{{ route('admin.jobs.retry', $job['id']) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-warning btn-sm"
                                        onclick="return confirm('Are you sure you want to retry this job?')">
                                        <i class="fas fa-redo me-1"></i> Retry
                                    </button>
                                </form>
                            @endif

                            @if(in_array($job['status'], ['queued', 'processing']))
                                <form action="{{ route('admin.jobs.cancel', $job['id']) }}" method="POST" class="d-inline ms-1">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm"
                                        onclick="return confirm('Are you sure you want to cancel job #{{ $job['id'] }}?')">
                                        <i class="fas fa-times me-1"></i> Cancel
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6 class="text-muted">Job ID</h6>
                                <p class="mb-3">{{ $job['id'] }}</p>

                                <h6 class="text-muted">Type</h6>
                                @php
                                    $typeClass = [
                                        'directory_import' => 'primary',
                                        'book_import' => 'info',
                                        'quota_failure' => 'danger',
                                    ][$job['type'] ?? ''] ?? 'secondary';
                                @endphp
                                <p>
                                    <span class="badge bg-{{ $typeClass }}">
                                        {{ ucfirst(str_replace('_', ' ', $job['type'] ?? 'unknown')) }}
                                    </span>
                                </p>

                                <h6 class="text-muted">Status</h6>
                                @php
                                    $statusClass = [
                                        'queued' => 'warning',
                                        'processing' => 'info',
                                        'completed' => 'success',
                                        'failed' => 'danger',
                                    ][$job['status']] ?? 'secondary';
                                @endphp
                                <p>
                                    <span class="badge bg-{{ $statusClass }}">
                                        {{ ucfirst($job['status']) }}
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Created At</h6>
                                <p>{{ \Carbon\Carbon::parse($job['created_at'])->format('Y-m-d H:i:s') }}</p>

                                @if(isset($job['started_at']))
                                    <h6 class="text-muted">Started At</h6>
                                    <p>{{ \Carbon\Carbon::parse($job['started_at'])->format('Y-m-d H:i:s') }}</p>
                                @endif

                                @if(isset($job['completed_at']))
                                    <h6 class="text-muted">Completed At</h6>
                                    <p>{{ \Carbon\Carbon::parse($job['completed_at'])->format('Y-m-d H:i:s') }}</p>

                                    @if(isset($job['started_at']))
                                        <h6 class="text-muted">Duration</h6>
                                        @php
                                            $start = \Carbon\Carbon::parse($job['started_at']);
                                            $end = \Carbon\Carbon::parse($job['completed_at']);
                                            $duration = $end->diff($start);
                                        @endphp
                                        <p>{{ $duration->format('%H:%I:%S') }}</p>
                                    @endif
                                @endif
                            </div>
                        </div>

                        @if(isset($job['message']))
                            <div class="alert alert-info">
                                <h6 class="alert-heading">Message</h6>
                                <p class="mb-0">{{ $job['message'] }}</p>
                            </div>
                        @endif

                        @if(isset($job['details']) && count($job['details']) > 0)
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="mb-0">Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <tbody>
                                                @foreach($job['details'] as $key => $value)
                                                    <tr>
                                                        <th style="width: 200px;">
                                                            {{ ucfirst(str_replace('_', ' ', $key)) }}
                                                        </th>
                                                        <td>
                                                            @if(is_array($value) || is_object($value))
                                                                <pre class="mb-0">{{ json_encode($value, JSON_PRETTY_PRINT) }}</pre>
                                                            @else
                                                                {{ $value ?? '—' }}
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if(isset($job['error']) && $job['error'])
                            <div class="card border-danger mb-4">
                                <div class="card-header bg-danger text-white">
                                    <h6 class="mb-0">Error Details</h6>
                                </div>
                                <div class="card-body">
                                    @if(isset($job['error']['message']))
                                        <h6>Message</h6>
                                        <pre class="bg-light p-3 rounded">{{ $job['error']['message'] }}</pre>
                                    @endif

                                    @if(isset($job['error']['trace']))
                                        <h6>Stack Trace</h6>
                                        <pre class="bg-light p-3 rounded"
                                            style="max-height: 300px; overflow: auto;">{{ $job['error']['trace'] }}</pre>
                                    @endif

                                    @if(isset($job['error']['file']) && isset($job['error']['line']))
                                        <div class="mt-3">
                                            <span class="text-muted">In file:</span>
                                            {{ $job['error']['file'] }}:{{ $job['error']['line'] }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if(isset($job['logs']) && count($job['logs']) > 0)
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Logs</h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 150px;">Time</th>
                                                    <th style="width: 100px;">Level</th>
                                                    <th>Message</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($job['logs'] as $log)
                                                    <tr>
                                                        <td>
                                                            {{ \Carbon\Carbon::parse($log['timestamp'])->format('Y-m-d H:i:s') }}
                                                        </td>
                                                        <td>
                                                            @php
                                                                $logLevelClass = [
                                                                    'debug' => 'info',
                                                                    'info' => 'info',
                                                                    'notice' => 'success',
                                                                    'warning' => 'warning',
                                                                    'error' => 'danger',
                                                                    'critical' => 'danger',
                                                                    'alert' => 'danger',
                                                                    'emergency' => 'danger',
                                                                ][strtolower($log['level'])] ?? 'secondary';
                                                            @endphp
                                                            <span class="badge bg-{{ $logLevelClass }}">
                                                                {{ strtoupper($log['level']) }}
                                                            </span>
                                                        </td>
                                                        <td>
                                                            {{ $log['message'] ?? '—' }}
                                                            @if(isset($log['context']) && !empty($log['context']))
                                                                <div class="text-muted small mt-1">
                                                                    <pre
                                                                        class="mb-0">{{ json_encode($log['context'], JSON_PRETTY_PRINT) }}</pre>
                                                                </div>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            // Auto-refresh the page every 10 seconds if the job is still processing/queued
            @if(in_array($job['status'], ['queued', 'processing']))
                setTimeout(function () {
                    window.location.reload();
                }, 10000);
            @endif
        </script>
    @endpush

@endsection
