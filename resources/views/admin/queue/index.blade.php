@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Import Queue Management</h1>
        <div class="mb-3">
            <button id="refresh-queue" class="btn btn-secondary">Refresh Queue</button>
            <button id="start-worker" class="btn btn-success">Start Worker</button>
            <button type="button" class="btn btn-danger" id="clear-queue-btn">
                Clear Queue
            </button>
            <span id="worker-status" class="ms-3"></span>
        </div>
        <div class="mb-3">
            <label for="job-type-filter" class="form-label">Filter by Job Type:</label>
            <select id="job-type-filter" class="form-select" style="width:auto; display:inline-block;"></select>
            <span id="job-type-counts" class="ms-3"></span>
        </div>
        <table class="table table-bordered" id="import-queue-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Directory</th>
                    <th>Attempts</th>
                    <th>Available At</th>
                    <th>Created At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <script>
        let currentType = '';
        function loadQueue(type = '') {
            let url = '/admin/queue/list';
            if (type) url += '?type=' + encodeURIComponent(type);
            $.getJSON(url, function(data) {
                let rows = '';
                data.jobs.forEach(function(job) {
                    rows += `<tr><td>${job.id}</td><td>${job.type||''}</td><td>${job.directory||''}</td><td>${job.attempts}</td><td>${job.available_at}</td><td>${job.created_at}</td><td><button class="btn btn-danger btn-sm remove-job" data-id="${job.id}">Remove</button></td></tr>`;
                });
                $('#import-queue-table tbody').html(rows);
                // Populate job type filter
                let $filter = $('#job-type-filter');
                $filter.empty();
                $filter.append(`<option value="">All Types</option>`);
                data.job_types.forEach(function(type) {
                    $filter.append(`<option value="${type}"${data.selected_type === type ? ' selected' : ''}>${type}</option>`);
                });
                // Show job type counts
                let countText = Object.entries(data.job_type_counts).map(([type, count]) => `${type}: ${count}`).join(' | ');
                $('#job-type-counts').text(countText);
                currentType = data.selected_type || '';
            });
        }
        function loadWorkerStatus() {
            $.getJSON('/admin/queue/status', function(data) {
                let txt = data.worker_running ? 'Worker Running' : 'Worker Stopped';
                txt += ` | Pending Jobs: ${data.pending_jobs}`;
                $('#worker-status').text(txt);
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
                $.post('/admin/queue/start', {_token: '{{ csrf_token() }}'}, function(data) {
                    loadWorkerStatus();
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
                if (confirm('Are you sure you want to clear the entire import queue? This cannot be undone.')) {
                    $.ajax({
                        url: "{{ route('admin.queue.clear') }}",
                        type: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').val()
                        },
                        success: function (data) {
                            location.reload();
                        },
                        error: function (xhr) {
                            alert('Failed to clear queue.');
                        }
                    });
                }
            });
        });
    </script>
@endsection
