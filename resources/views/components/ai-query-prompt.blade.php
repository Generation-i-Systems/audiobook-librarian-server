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

<div class="ai-query-prompt-container mb-4">
    <div class="card border-primary">
        <div class="card-header bg-primary text-white py-2" id="ai-assistant-header" style="cursor: pointer;">
            <div class="d-flex align-items-center justify-content-between">
                <h5 class="mb-0 d-flex align-items-center">
                    <i class="fas fa-robot me-2"></i> AI Library Assistant
                </h5>
                <form action="{{ route('admin.ai-query.process') }}" method="POST" class="flex-grow-1 ms-3 me-2 ai-quick-form" id="ai-quick-form" style="max-width: 600px;">
                    @csrf
                    @if(isset($contextQueryId))
                        <input type="hidden" name="context_query_id" value="{{ $contextQueryId }}">
                    @endif
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        id="ai-query-quick-input"
                        name="prompt"
                        required
                        placeholder="{{ isset($contextQueryId) ? 'Follow-up question...' : 'Ask a question (click for examples)...' }}"
                        style="background: rgba(255,255,255,0.9);">
                </form>
                <i class="fas fa-chevron-down" id="ai-assistant-toggle"></i>
            </div>
        </div>
        <div class="collapse" id="ai-assistant-details">
            <div class="card-body">
                @if(session('error'))
                    <div class="alert alert-danger alert-dismissible fade show">
                        {{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                <form action="{{ route('admin.ai-query.process') }}" method="POST" id="ai-full-form">
                    @csrf
                    @if(isset($contextQueryId))
                        <input type="hidden" name="context_query_id" value="{{ $contextQueryId }}">
                    @endif
                    <div class="mb-3">
                        <label for="ai-query-full-input" class="form-label">{{ isset($contextQueryId) ? 'Follow-up question:' : 'Ask a question or request an operation:' }}</label>
                        <textarea
                            class="form-control"
                            id="ai-query-full-input"
                            name="prompt"
                            rows="3"
                            required
                            placeholder="Example: What author has published in the most genres?"></textarea>
                        <small class="form-text text-muted">
                            <strong>Examples:</strong>
                            <ul class="mb-0 mt-1">
                                <li><strong>Research:</strong> "What author has published in the most genres?"</li>
                                <li><strong>Find Books:</strong> "List books where the directory doesn't match the genre"</li>
                                <li><strong>Bulk Update:</strong> "Move all books by Homer to the Classic genre"</li>
                            </ul>
                        </small>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const header = document.getElementById('ai-assistant-header');
    const details = document.getElementById('ai-assistant-details');
    const toggle = document.getElementById('ai-assistant-toggle');
    const quickForm = document.getElementById('ai-quick-form');
    const quickInput = document.getElementById('ai-query-quick-input');
    const fullForm = document.getElementById('ai-full-form');
    const fullInput = document.getElementById('ai-query-full-input');
    const loadingOverlay = document.getElementById('ai-loading-overlay');

    let isExpanded = false;

    function showLoading() {
        if (loadingOverlay) {
            loadingOverlay.style.display = 'flex';
        }
    }

    header.addEventListener('click', function(e) {
        if (e.target === quickInput || quickInput.contains(e.target)) {
            return;
        }

        isExpanded = !isExpanded;

        if (isExpanded) {
            details.classList.add('show');
            toggle.classList.remove('fa-chevron-down');
            toggle.classList.add('fa-chevron-up');
            fullInput.value = quickInput.value;
            quickInput.value = '';
            setTimeout(() => fullInput.focus(), 100);
        } else {
            details.classList.remove('show');
            toggle.classList.remove('fa-chevron-up');
            toggle.classList.add('fa-chevron-down');
        }
    });

    quickInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            showLoading();
            quickForm.submit();
        }
    });

    fullInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            showLoading();
            fullForm.submit();
        }
    });

    quickForm.addEventListener('submit', function(e) {
        e.stopPropagation();
        showLoading();
    });

    fullForm.addEventListener('submit', function(e) {
        showLoading();
    });

    // Hide loading if page loads (for back button navigation)
    window.addEventListener('pageshow', function(event) {
        if (loadingOverlay) {
            loadingOverlay.style.display = 'none';
        }
    });
});
</script>
