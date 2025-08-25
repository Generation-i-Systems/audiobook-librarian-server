@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Messages</h1>

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif

        <div class="mb-4">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newMessageModal">
                <i class="fas fa-plus"></i> New Message
            </button>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Content</th>
                                <th>From</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($messages as $message)
                                <tr class="{{ $message['is_read'] ?? false ? '' : 'table-info' }}">
                                    <td>
                                        @if(!($message['is_read'] ?? false))
                                            <span class="badge bg-warning text-dark">New</span>
                                        @else
                                            <span class="badge bg-light text-secondary">Read</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="#" data-bs-toggle="modal" data-bs-target="#messageModal{{ $message['id'] }}">
                                            {{ \Illuminate\Support\Str::limit($message['content'] ?? '', 50) }}
                                        </a>
                                    </td>
                                    <td>
                                        @php
                                            $sender = collect($users)->firstWhere('id', $message['from_user_id'] ?? null);
                                        @endphp
                                        {{ $sender ? ($sender['name'] ?? $sender['email'] ?? 'Unknown') : 'System' }}
                                    </td>
                                    <td>{{ \Carbon\Carbon::parse($message['created_at'] ?? now())->diffForHumans() }}</td>
                                    <td>
                                        @if(!($message['acknowledged_at'] ?? false))
                                            <form action="{{ route('admin.messages.acknowledge', $message['id']) }}" method="POST" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-success" title="Acknowledge">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                        @else
                                            <span class="badge bg-success">Acknowledged</span>
                                        @endif
                                    </td>
                                </tr>

                                <!-- Message Modal -->
                                <div class="modal fade" id="messageModal{{ $message['id'] }}" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Message Details</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p><strong>From:</strong> {{ $sender ? ($sender['name'] ?? $sender['email'] ?? 'Unknown') : 'System' }}</p>
                                                <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($message['created_at'] ?? now())->format('M j, Y g:i A') }}</p>
                                                <hr>
                                                <p>{{ $message['content'] ?? '' }}</p>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                @if(!($message['acknowledged_at'] ?? false))
                                                    <form action="{{ route('admin.messages.acknowledge', ['messageId' => $message['id']]) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-success">Acknowledge</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center">No messages found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- New Message Modal -->
    <div class="modal fade" id="newMessageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">New Message</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('admin.messages.store') }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="to_user_id" class="form-label">Recipient</label>
                            <select class="form-select" id="to_user_id" name="to_user_id" required>
                                <option value="">Select a user</option>
                                @foreach($users as $user)
                                    <option value="{{ $user['id'] }}">
                                        {{ $user['name'] ?? $user['email'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="content" class="form-label">Message</label>
                            <textarea class="form-control" id="content" name="content" rows="5" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Send Message</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @if($errors->any())
        <script>
            $(document).ready(function() {
                $('#newMessageModal').modal('show');
            });
        </script>
    @endif
@endpush
