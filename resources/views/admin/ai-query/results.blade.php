@extends('layouts.app')

@section('content')
{{-- Loading overlay --}}
<div id="ai-loading-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; justify-content: center; align-items: center;">
    <div class="text-center">
        <div class="spinner-border text-light" role="status" style="width: 4rem; height: 4rem; border-width: 0.4rem;">
            <span class="visually-hidden">Loading...</span>
        </div>
        <div class="mt-3 text-white">
            <h4><i class="fas fa-robot"></i> AI is thinking...</h4>
            <p class="text-muted">This may take a few seconds</p>
        </div>
    </div>
</div>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-robot"></i> AI Library Assistant - Conversation</h1>
        <div>
            <a href="{{ route('admin.books.index') }}" class="btn btn-success me-2">
                <i class="fas fa-plus"></i> New Conversation
            </a>
            <a href="{{ route('admin.books.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to Books
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body p-0">
            <div class="conversation-container" style="max-height: 800px; overflow-y: auto;">
                @foreach($conversation as $index => $query)
                    {{-- User message --}}
                    <div class="p-2 bg-light border-bottom">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fas fa-user-circle text-primary mt-1"></i>
                            <div class="flex-grow-1">
                                <div class="mb-1 query-prompt-{{ $index }}" id="prompt-{{ $index }}">{{ $query['prompt'] }}</div>
                                <div class="edit-prompt-form-{{ $index }}" style="display: none;">
                                    <form action="{{ route('admin.ai-query.edit-prompt') }}" method="POST" onsubmit="return confirmEditPrompt({{ $index }}, {{ count($conversation) }})">
                                        @csrf
                                        <input type="hidden" name="query_id" value="{{ $query['id'] }}">
                                        <input type="hidden" name="conversation_id" value="{{ $conversationId }}">
                                        <textarea class="form-control form-control-sm mb-2" name="prompt" rows="2" required>{{ $query['prompt'] }}</textarea>
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="fas fa-save"></i> Save & Rerun
                                        </button>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEditPrompt({{ $index }})">
                                            Cancel
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div>
                                @if($loop->last)
                                    <button type="button" class="btn btn-sm btn-link text-muted p-0" onclick="editPrompt({{ $index }})" title="Edit this prompt">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form action="{{ route('admin.ai-query.process') }}" method="POST" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="prompt" value="{{ $query['prompt'] }}">
                                        @if($index > 0)
                                            <input type="hidden" name="context_query_id" value="{{ $conversationId }}">
                                        @endif
                                        <button type="submit" class="btn btn-sm btn-link text-muted p-0" title="Rerun this query">
                                            <i class="fas fa-redo"></i>
                                        </button>
                                    </form>
                                @else
                                    <button type="button" class="btn btn-sm btn-link text-muted p-0" onclick="editPrompt({{ $index }})" title="Edit this prompt (will reset conversation)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- AI response --}}
                    <div class="p-2 {{ $loop->last ? '' : 'border-bottom' }}">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fas fa-robot text-success mt-1"></i>
                            <div class="flex-grow-1">
                                <div class="mb-1">
                                    <span class="badge bg-info badge-sm">{{ ucfirst(str_replace('_', ' ', $query['operation_type'])) }}</span>
                                    <small class="text-muted ms-1">{{ $query['explanation'] }}</small>
                                </div>

                                @if($loop->last)
                                    {{-- Show full results for the latest query --}}
                                    <div class="mt-2">
                                        @if($query['operation_type'] === 'research')
                                            @include('admin.ai-query.partials.research-results', ['results' => $query['results']])
                                        @elseif($query['operation_type'] === 'list')
                                            @include('admin.ai-query.partials.list-results', ['results' => $query['results']])
                                        @elseif($query['operation_type'] === 'bulk_update')
                                            @include('admin.ai-query.partials.bulk-update-results', ['results' => $query['results'], 'queryId' => $query['id']])
                                        @elseif($query['operation_type'] === 'parse_update')
                                            @include('admin.ai-query.partials.parse-update-results', ['results' => $query['results'], 'queryId' => $query['id']])
                                        @endif
                                    </div>
                                @else
                                    {{-- Show summary for older queries --}}
                                    <small class="text-muted">
                                        @if($query['operation_type'] === 'list' && isset($query['results']['items']))
                                            → {{ count($query['results']['items']) }} items
                                        @elseif(in_array($query['operation_type'], ['bulk_update', 'parse_update']) && isset($query['results']['preview']))
                                            → {{ count($query['results']['preview']) }} items to update
                                        @elseif($query['operation_type'] === 'research')
                                            → Completed
                                        @endif
                                    </small>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Prompt input for next message --}}
    <div class="card border-primary">
        <div class="card-body">
            <form action="{{ route('admin.ai-query.process') }}" method="POST">
                @csrf
                <input type="hidden" name="context_query_id" value="{{ $conversationId }}">
                <div class="mb-3">
                    <label for="follow-up-prompt" class="form-label">
                        <i class="fas fa-comment-dots"></i> Continue the conversation
                    </label>
                    <textarea
                        class="form-control"
                        id="follow-up-prompt"
                        name="prompt"
                        rows="2"
                        required
                        placeholder="Ask a follow-up question or refine your request..."
                        autofocus></textarea>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted me-3">Press Ctrl+Enter to submit</small>
                        <label class="text-muted small me-2">Context items:</label>
                        <select name="context_limit" class="form-select form-select-sm d-inline-block" style="width: auto;">
                            <option value="10">10 items</option>
                            <option value="25">25 items</option>
                            <option value="50" selected>50 items</option>
                            <option value="100">100 items</option>
                            <option value="200">200 items</option>
                            <option value="0">All items</option>
                        </select>
                        <small class="text-muted ms-2" title="Number of results sent to AI for context">
                            <i class="fas fa-info-circle"></i>
                        </small>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const loadingOverlay = document.getElementById('ai-loading-overlay');

        function showLoading() {
            loadingOverlay.style.display = 'flex';
        }

        function hideLoading() {
            loadingOverlay.style.display = 'none';
        }

        function editPrompt(index) {
            document.querySelector('.query-prompt-' + index).style.display = 'none';
            document.querySelector('.edit-prompt-form-' + index).style.display = 'block';
        }

        function cancelEditPrompt(index) {
            document.querySelector('.query-prompt-' + index).style.display = 'block';
            document.querySelector('.edit-prompt-form-' + index).style.display = 'none';
        }

        function confirmEditPrompt(index, totalQueries) {
            const queriesAfter = totalQueries - index - 1;
            if (queriesAfter > 0) {
                return confirm(
                    `WARNING: Editing this prompt will remove ${queriesAfter} follow-up ` +
                    `${queriesAfter === 1 ? 'query' : 'queries'} after it and reset the conversation to this point.\n\n` +
                    `Are you sure you want to continue?`
                );
            }
            return true;
        }

        // Show loading on follow-up prompt submission
        document.getElementById('follow-up-prompt').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                showLoading();
                this.closest('form').submit();
            }
        });

        // Show loading on all form submissions to AI
        document.querySelectorAll('form[action*="ai-query"]').forEach(form => {
            form.addEventListener('submit', function(e) {
                showLoading();
            });
        });

        // Hide loading if page loads (for back button navigation)
        window.addEventListener('pageshow', function(event) {
            hideLoading();
        });
    </script>

    {{-- Generated queries (collapsible) --}}
    <div class="card mt-4 border-secondary">
        <div class="card-header bg-light text-muted py-2">
            <h6 class="mb-0 d-flex align-items-center justify-content-between">
                <span><i class="fas fa-code me-2"></i>Generated SQL Queries</span>
                <div>
                    <button class="btn btn-sm btn-outline-primary me-2" onclick="toggleEditMode()">
                        <i class="fas fa-edit"></i> Edit & Rerun
                    </button>
                    <button class="btn btn-sm btn-link text-muted p-0" type="button" data-bs-toggle="collapse" data-bs-target="#generatedQueries">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
            </h6>
        </div>
        <div class="collapse" id="generatedQueries">
            <div class="card-body bg-light py-2">
                <form id="custom-query-form" action="{{ route('admin.ai-query.execute-custom') }}" method="POST">
                    @csrf
                    <input type="hidden" name="conversation_id" value="{{ $conversationId }}">
                    <input type="hidden" name="query_id" value="{{ $latestQuery['id'] }}">

                    @foreach($latestQuery['queries'] as $index => $query)
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <div class="small text-muted">
                                    <span class="badge bg-secondary me-1">{{ $query['type'] }}</span>
                                    {{ $query['purpose'] }}
                                </div>
                            </div>

                            {{-- View mode --}}
                            <pre class="bg-white p-2 rounded border border-secondary small mb-0 query-view" style="max-height: 200px; overflow-y: auto;"><code>{{ is_array($query['query']) ? json_encode($query['query'], JSON_PRETTY_PRINT) : $query['query'] }}</code></pre>

                            {{-- Edit mode --}}
                            <div class="query-edit" style="display: none;">
                                <textarea
                                    class="form-control font-monospace small"
                                    name="queries[{{ $index }}][query]"
                                    rows="5"
                                    style="font-size: 12px;">{{ is_array($query['query']) ? json_encode($query['query'], JSON_PRETTY_PRINT) : $query['query'] }}</textarea>
                                <input type="hidden" name="queries[{{ $index }}][type]" value="{{ $query['type'] }}">
                                <input type="hidden" name="queries[{{ $index }}][purpose]" value="{{ $query['purpose'] }}">
                            </div>
                        </div>
                    @endforeach

                    <div class="query-edit" style="display: none;">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-play"></i> Run Custom Query
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEditMode()">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleEditMode() {
            const viewElements = document.querySelectorAll('.query-view');
            const editElements = document.querySelectorAll('.query-edit');

            viewElements.forEach(el => {
                el.style.display = el.style.display === 'none' ? 'block' : 'none';
            });

            editElements.forEach(el => {
                el.style.display = el.style.display === 'none' ? 'block' : 'none';
            });
        }
    </script>
</div>
@endsection
