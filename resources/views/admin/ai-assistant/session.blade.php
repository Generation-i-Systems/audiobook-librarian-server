@extends('layouts.app')

@section('title', 'AI Assistant Session')

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>AI Assistant Session #{{ $session->id }}</h1>
                <div>
                    <a href="{{ route('admin.ai-assistant.index') }}" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
            </div>

            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if (session('error'))
                <div class="alert alert-danger alert-dismissible fade show">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if (session('warning'))
                <div class="alert alert-warning alert-dismissible fade show">
                    {{ session('warning') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <div class="row">
                <!-- Conversation History -->
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-chat-left-text"></i> Conversation
                            </h5>
                        </div>
                        <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                            @foreach($conversation as $message)
                                @if($message['role'] === 'user')
                                    <div class="mb-3">
                                        <div class="badge bg-info mb-1">You</div>
                                        <div class="p-2 bg-light rounded">
                                            {{ $message['content'] }}
                                        </div>
                                        <small class="text-muted">{{ \Carbon\Carbon::parse($message['timestamp'])->format('H:i') }}</small>
                                    </div>
                                @else
                                    <div class="mb-3">
                                        <div class="badge bg-success mb-1">AI Assistant</div>
                                        <div class="p-2 bg-light rounded border">
                                            {{ $message['content'] }}
                                        </div>
                                        <small class="text-muted">{{ \Carbon\Carbon::parse($message['timestamp'])->format('H:i') }}</small>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        <!-- Refinement Form -->
                        @if($session->status === 'pending_approval')
                        <div class="card-footer">
                            <form action="{{ route('admin.ai-assistant.refine', ['sessionId' => $session->id]) }}" method="POST">
                                @csrf
                                <div class="mb-2">
                                    <label for="refine-message" class="form-label small">Refine your request:</label>
                                    <textarea
                                        class="form-control form-control-sm"
                                        id="refine-message"
                                        name="message"
                                        rows="2"
                                        placeholder="e.g., 'Only show books from 2020 or later' or 'Also change the narrator'"
                                    ></textarea>
                                </div>
                                <button type="submit" class="btn btn-sm btn-primary w-100">
                                    <i class="bi bi-send"></i> Send
                                </button>
                            </form>
                        </div>
                        @endif
                    </div>
                </div>

                <!-- Operations Preview -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-list-check"></i> Operations Preview
                                <span class="badge bg-secondary">{{ count($operations) }} item(s)</span>
                            </h5>
                            <div>
                                @if($session->status === 'pending_approval')
                                    <span class="badge bg-warning">Pending Approval</span>
                                @elseif($session->status === 'completed')
                                    <span class="badge bg-success">Completed</span>
                                @elseif($session->status === 'partially_completed')
                                    <span class="badge bg-warning">Partially Completed</span>
                                @endif
                            </div>
                        </div>
                        <div class="card-body">
                            @if(empty($operations))
                                <p class="text-muted">No operations to preview</p>
                            @else
                                <form action="{{ route('admin.ai-assistant.execute', ['sessionId' => $session->id]) }}" method="POST" id="execute-form">
                                    @csrf

                                    <!-- Select All/None -->
                                    @if($session->status === 'pending_approval')
                                    <div class="mb-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="select-all">Select All</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="select-none">Select None</button>
                                    </div>
                                    @endif

                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    @if($session->status === 'pending_approval')
                                                    <th width="40"></th>
                                                    @endif
                                                    <th>Operation</th>
                                                    <th>Title</th>
                                                    <th>Details</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($operations as $index => $operation)
                                                <tr>
                                                    @if($session->status === 'pending_approval')
                                                    <td>
                                                        <input type="checkbox" class="form-check-input operation-checkbox"
                                                               name="selected_books[]"
                                                               value="{{ $operation['book_id'] ?? $index }}"
                                                               checked>
                                                    </td>
                                                    @endif
                                                    <td>
                                                        @if($operation['type'] === 'update')
                                                            <span class="badge bg-warning">Update</span>
                                                        @elseif($operation['type'] === 'delete')
                                                            <span class="badge bg-danger">Delete</span>
                                                        @elseif($operation['type'] === 'create')
                                                            <span class="badge bg-success">Create</span>
                                                        @else
                                                            <span class="badge bg-info">Display</span>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        <strong>{{ $operation['title'] ?? $operation['current']['title'] ?? 'N/A' }}</strong>
                                                        @if(isset($operation['author']))
                                                            <br><small class="text-muted">by {{ $operation['author'] }}</small>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if($operation['type'] === 'update')
                                                            <ul class="list-unstyled mb-0 small">
                                                                @foreach($operation['changes'] as $field => $newValue)
                                                                <li>
                                                                    <strong>{{ ucfirst($field) }}:</strong>
                                                                    <span class="text-muted">{{ $operation['current'][$field] ?? 'null' }}</span>
                                                                    →
                                                                    <span class="text-success">{{ $newValue }}</span>
                                                                </li>
                                                                @endforeach
                                                            </ul>
                                                        @elseif($operation['type'] === 'delete')
                                                            <span class="text-danger">
                                                                Will be deleted {{ $operation['delete_files'] ? '(including files)' : '(database only)' }}
                                                            </span>
                                                        @elseif(isset($operation['genre']))
                                                            <span class="badge bg-secondary">{{ $operation['genre'] }}</span>
                                                            @if(isset($operation['series']))
                                                                <span class="badge bg-info">{{ $operation['series'] }}</span>
                                                            @endif
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if($session->status === 'completed' || $session->status === 'partially_completed')
                                                            @php
                                                                $wasExecuted = false;
                                                                if (isset($executionResults['results'])) {
                                                                    foreach ($executionResults['results'] as $result) {
                                                                        if (isset($result['book_id']) && $result['book_id'] == ($operation['book_id'] ?? null)) {
                                                                            $wasExecuted = $result['success'] ?? false;
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                            @endphp
                                                            @if($wasExecuted)
                                                                <i class="bi bi-check-circle text-success"></i>
                                                            @else
                                                                <i class="bi bi-x-circle text-danger"></i>
                                                            @endif
                                                        @endif
                                                    </td>
                                                </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                    @if($session->status === 'pending_approval')
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <button type="submit" class="btn btn-success" id="execute-btn">
                                            <i class="bi bi-play-circle"></i> Execute Selected Operations
                                        </button>
                                        <form action="{{ route('admin.ai-assistant.cancel', ['sessionId' => $session->id]) }}"
                                              method="POST"
                                              class="d-inline"
                                              onsubmit="return confirm('Are you sure you want to cancel this session?')">
                                            @csrf
                                            <button type="submit" class="btn btn-outline-danger">
                                                <i class="bi bi-x-circle"></i> Cancel Session
                                            </button>
                                        </form>
                                    </div>
                                    @endif
                                </form>

                                <!-- Execution Results -->
                                @if($session->status === 'completed' || $session->status === 'partially_completed')
                                <div class="mt-4">
                                    <h6>Execution Summary:</h6>
                                    <ul>
                                        <li>Executed: {{ count($executionResults['results'] ?? []) }} operations</li>
                                        @if(!empty($executionResults['errors']))
                                        <li class="text-danger">Errors: {{ count($executionResults['errors']) }}</li>
                                        @endif
                                    </ul>

                                    @if(!empty($executionResults['errors']))
                                    <div class="alert alert-danger">
                                        <h6>Errors:</h6>
                                        <ul>
                                            @foreach($executionResults['errors'] as $error)
                                            <li>{{ $error['error'] }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                    @endif
                                </div>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select all/none functionality
    const selectAllBtn = document.getElementById('select-all');
    const selectNoneBtn = document.getElementById('select-none');
    const checkboxes = document.querySelectorAll('.operation-checkbox');

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            checkboxes.forEach(cb => cb.checked = true);
        });
    }

    if (selectNoneBtn) {
        selectNoneBtn.addEventListener('click', function() {
            checkboxes.forEach(cb => cb.checked = false);
        });
    }

    // Confirm execution
    const executeForm = document.getElementById('execute-form');
    if (executeForm) {
        executeForm.addEventListener('submit', function(e) {
            const checkedCount = document.querySelectorAll('.operation-checkbox:checked').length;
            if (checkedCount === 0) {
                e.preventDefault();
                alert('Please select at least one operation to execute');
                return false;
            }

            const operationType = '{{ $operations[0]['type'] ?? 'update' }}';
            let message = `Are you sure you want to execute ${checkedCount} operation(s)?`;

            if (operationType === 'delete') {
                message += '\n\nWARNING: This will delete data and cannot be undone!';
            }

            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    }
});
</script>
@endsection
