@extends('layouts.app')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1">Queue & Worker Management</h1>
                <p class="text-muted mb-0">Control background workers, queue states, and job processing in real-time.</p>
            </div>
            <div>
                <a href="/horizon" target="_blank" class="btn btn-outline-primary">
                    <i class="bi bi-speedometer2 me-1"></i> Open Horizon Dashboard
                </a>
            </div>
        </div>

        <!-- Worker Control Status Bar -->
        <div class="card mb-4 shadow-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <div class="d-flex align-items-center">
                            <span class="fw-bold me-2">Worker Status:</span>
                            <span id="worker-status-badge" class="badge bg-secondary px-3 py-2 fs-6">Checking...</span>
                        </div>
                        <div class="mt-2 text-muted small" id="queue-depths-summary">
                            Loading queue metrics...
                        </div>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <div class="btn-group mb-2 mb-lg-0" role="group">
                            <button id="start-worker" class="btn btn-success">
                                <i class="bi bi-play-fill me-1"></i> Start Worker
                            </button>
                            <button id="pause-worker" class="btn btn-warning">
                                <i class="bi bi-pause-fill me-1"></i> Pause Queue
                            </button>
                            <button id="resume-worker" class="btn btn-info text-white">
                                <i class="bi bi-play-circle-fill me-1"></i> Resume Queue
                            </button>
                            <button id="stop-worker" class="btn btn-dark">
                                <i class="bi bi-stop-fill me-1"></i> Stop Worker
                            </button>
                        </div>
                        <div class="btn-group ms-2 mb-2 mb-lg-0" role="group">
                            <button id="retry-failed-btn" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Retry Failed
                            </button>
                            <button id="clear-queue-btn" class="btn btn-outline-danger">
                                <i class="bi bi-trash-fill me-1"></i> Clear Queue
                            </button>
                            <button id="refresh-queue" class="btn btn-secondary">
                                <i class="bi bi-arrow-repeat me-1"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter and Jobs Table -->
        <div class="card shadow-sm">
            <div class="card-header bg-light d-flex justify-content-between align-items-center py-3">
                <div class="d-flex align-items-center">
                    <label for="job-type-filter" class="form-label mb-0 me-2 fw-semibold">Job Type:</label>
                    <select id="job-type-filter" class="form-select form-select-sm" style="width:auto; display:inline-block;"></select>
                </div>
                <span id="job-type-counts" class="text-muted small"></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="import-queue-table">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Type</th>
                                <th>Directory / Payload</th>
                                <th>Attempts</th>
                                <th>Available At</th>
                                <th>Created At</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentType = '';

        function loadQueue(type = '') {
            let url = '{{ route("admin.queue.list") }}';
            if (type) url += '?type=' + encodeURIComponent(type);
            $.getJSON(url, function(data) {
                let rows = '';
                if (!data.jobs || data.jobs.length === 0) {
                    rows = '<tr><td colspan="7" class="text-center py-4 text-muted">No pending or active queue jobs found.</td></tr>';
                } else {
                    data.jobs.forEach(function(job) {
                        rows += `<tr>
                            <td><code>${job.id}</code></td>
                            <td><span class="badge bg-light text-dark border">${job.type||'Job'}</span></td>
                            <td>${job.directory||job.message||'-'}</td>
                            <td>${job.attempts}</td>
                            <td>${job.available_at||'-'}</td>
                            <td>${job.created_at||'-'}</td>
                            <td class="text-end">
                                <button class="btn btn-outline-danger btn-sm remove-job" data-id="${job.id}">
                                    <i class="bi bi-x-circle me-1"></i> Remove
                                </button>
                            </td>
                        </tr>`;
                    });
                }
                $('#import-queue-table tbody').html(rows);

                let $filter = $('#job-type-filter');
                $filter.empty();
                $filter.append(`<option value="">All Types</option>`);
                (data.job_types || []).forEach(function(t) {
                    $filter.append(`<option value="${t}"${data.selected_type === t ? ' selected' : ''}>${t}</option>`);
                });

                let countText = Object.entries(data.job_type_counts || {}).map(([t, c]) => `${t}: ${c}`).join(' | ');
                $('#job-type-counts').text(countText);
                currentType = data.selected_type || '';
            });
        }

        function loadWorkerStatus() {
            $.getJSON('{{ route("admin.queue.status") }}', function(data) {
                let $badge = $('#worker-status-badge');
                if (data.status === 'running' || data.worker_running) {
                    $badge.removeClass('bg-secondary bg-warning bg-danger').addClass('bg-success').text('Running');
                } else if (data.status === 'paused') {
                    $badge.removeClass('bg-secondary bg-success bg-danger').addClass('bg-warning text-dark').text('Paused');
                } else {
                    $badge.removeClass('bg-secondary bg-success bg-warning').addClass('bg-danger').text('Inactive / Stopped');
                }

                let q = data.queues || {};
                let summary = `Queues — <strong>Embeddings:</strong> ${q.embeddings || 0} | <strong>Recommendations:</strong> ${q.recommendations || 0} | <strong>Default:</strong> ${q.default || 0} | <strong>Failed Jobs:</strong> ${data.failed_jobs || 0}`;
                $('#queue-depths-summary').html(summary);
            });
        }

        $(function() {
            loadQueue();
            loadWorkerStatus();

            $('#refresh-queue').click(function() {
                loadQueue(currentType);
                loadWorkerStatus();
            });

            $('#start-worker').click(function() {
                $.post('{{ route("admin.queue.start") }}', {_token: '{{ csrf_token() }}'}, function(data) {
                    loadWorkerStatus();
                    loadQueue(currentType);
                });
            });

            $('#stop-worker').click(function() {
                if (confirm('Stop the master queue worker?')) {
                    $.post('{{ route("admin.queue.stop") }}', {_token: '{{ csrf_token() }}'}, function(data) {
                        loadWorkerStatus();
                    });
                }
            });

            $('#pause-worker').click(function() {
                $.post('{{ route("admin.queue.pause") }}', {_token: '{{ csrf_token() }}'}, function(data) {
                    loadWorkerStatus();
                });
            });

            $('#resume-worker').click(function() {
                $.post('{{ route("admin.queue.resume") }}', {_token: '{{ csrf_token() }}'}, function(data) {
                    loadWorkerStatus();
                });
            });

            $('#retry-failed-btn').click(function() {
                $.post('{{ route("admin.queue.retry") }}', {_token: '{{ csrf_token() }}'}, function(data) {
                    alert(data.message || 'Queued failed jobs for retry.');
                    loadWorkerStatus();
                    loadQueue(currentType);
                });
            });

            $(document).on('click', '.remove-job', function() {
                if (confirm('Remove this job from the queue?')) {
                    $.post(`/admin/queue/remove/${$(this).data('id')}`, {_token: '{{ csrf_token() }}'}, function(data) {
                        loadQueue(currentType);
                        loadWorkerStatus();
                    });
                }
            });

            $('#job-type-filter').on('change', function() {
                loadQueue($(this).val());
            });

            $('#clear-queue-btn').on('click', function () {
                if (confirm('Are you sure you want to clear the queue? This cannot be undone.')) {
                    $.post('{{ route("admin.queue.clear") }}', {confirm: true, _token: '{{ csrf_token() }}'}, function (data) {
                        loadQueue(currentType);
                        loadWorkerStatus();
                    });
                }
            });
        });
    </script>
@endsection
