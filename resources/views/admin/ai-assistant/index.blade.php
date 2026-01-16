@extends('layouts.app')

@section('title', 'AI Assistant')

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4">AI Book Management Assistant</h1>

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

            <!-- Main Chat Interface -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-chat-dots"></i> New Request
                    </h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.ai-assistant.process') }}" method="POST">
                        @csrf
                        <div class="mb-3">
                            <label for="message" class="form-label">What would you like to do?</label>
                            <textarea
                                class="form-control"
                                id="message"
                                name="message"
                                rows="4"
                                required
                                placeholder="Examples:
- Find all sci-fi books by Brandon Sanderson
- Change the genre of all Terry Pratchett books to Fantasy
- Delete all books with no author
- Show me books missing cover images
- Update the author of 'The Stand' to 'Stephen King'"
                            ></textarea>
                            <div class="form-text">
                                Describe what you want to search for or change. The AI will show you a preview before making any changes.
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send"></i> Process Request
                        </button>
                    </form>
                </div>
            </div>

            <!-- Recent Sessions -->
            @if($recentSessions->count() > 0)
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history"></i> Recent Sessions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Intent</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentSessions as $session)
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($session->created_at)->format('M d, Y H:i') }}</td>
                                    <td>
                                        <span class="badge bg-info">{{ ucfirst($session->last_intent) }}</span>
                                    </td>
                                    <td>
                                        @if($session->status === 'pending_approval')
                                            <span class="badge bg-warning">Pending Approval</span>
                                        @elseif($session->status === 'completed')
                                            <span class="badge bg-success">Completed</span>
                                        @elseif($session->status === 'partially_completed')
                                            <span class="badge bg-warning">Partially Completed</span>
                                        @elseif($session->status === 'cancelled')
                                            <span class="badge bg-secondary">Cancelled</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.ai-assistant.session', ['sessionId' => $session->id]) }}"
                                           class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            <!-- Help Section -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-question-circle"></i> How It Works
                    </h5>
                </div>
                <div class="card-body">
                    <ol>
                        <li><strong>Describe what you want:</strong> Use natural language to describe what you want to search for or change</li>
                        <li><strong>Preview the changes:</strong> The AI will show you exactly what will be changed before any action is taken</li>
                        <li><strong>Refine if needed:</strong> You can ask follow-up questions or request modifications</li>
                        <li><strong>Approve and execute:</strong> When you're happy with the preview, approve the changes to execute them</li>
                    </ol>

                    <h6 class="mt-3">Example Requests:</h6>
                    <ul>
                        <li><strong>Search:</strong> "Show me all fantasy books published after 2020"</li>
                        <li><strong>Update:</strong> "Change the genre of all Brandon Sanderson books to Fantasy"</li>
                        <li><strong>Tag:</strong> "Mark all Terry Pratchett books as Comedy"</li>
                        <li><strong>Find issues:</strong> "Show me books with missing authors"</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
