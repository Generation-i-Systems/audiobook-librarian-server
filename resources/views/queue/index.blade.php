@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Import Queue Management</h1>
        <div class="mb-3">
            <button id="refresh-queue" class="btn btn-secondary">Refresh Queue</button>
            <button id="start-worker" class="btn btn-success">Start Worker</button>
            <span id="worker-status" class="ms-3"></span>
        </div>
        <table class="table table-bordered" id="import-queue-table">
            <thead>
                <tr>
                    <th>ID</th>
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function loadQueue() {
            $.getJSON('/admin/queue/list', function(data) {
                let rows = '';
                data.jobs.forEach(function(job) {
                    rows += `<tr><td>${job.id}</td><td>${job.directory||''}</td><td>${job.attempts}</td><td>${job.available_at}</td><td>${job.created_at}</td><td><button class="btn btn-danger btn-sm remove-job" data-id="${job.id}">Remove</button></td></tr>`;
                });
                $('#import-queue-table tbody').html(rows);
            });
        }
        function loadWorkerStatus() {
            $.getJSON('/admin/queue/status', function(data) {
                let txt = data.worker_running ? 'Worker Running' : 'Worker Stopped';
                txt += ` | Pending Jobs: ${data.pending_jobs}`;
                $('#worker-status').text(txt);
            });
        }
        $(document).ready(function() {
            loadQueue();
            loadWorkerStatus();
            $('#refresh-queue').click(function() {
                loadQueue();
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
                        loadQueue();
                        loadWorkerStatus();
                    });
                }
            });
        });
    </script>
@endsection
