<div class="import-file-browser">
    <div class="mb-3">
        <label for="import-root-select" class="form-label">Root Directory</label>
        <select id="import-root-select" class="form-select">
            <!-- JS will populate options -->
        </select>
    </div>
    <div class="mb-3">
        <label for="import-path-input" class="form-label">Current Path</label>
        <input type="text" id="import-path-input" class="form-control" readonly>
    </div>
    <div id="import-directory-list" class="border rounded p-2 bg-light mb-3" style="min-height: 200px;">
        <!-- JS will populate directory/file list here -->
    </div>
    <div class="card mb-3" style="border-left: 4px solid #007bff;">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="enable-ai-processing" checked>
                        <label class="form-check-label" for="enable-ai-processing">
                            <strong>🤖 AI Enhancement</strong> <span class="badge bg-primary">New</span>
                            <br><small class="text-muted">Improve metadata extraction using AI</small>
                        </label>
                    </div>
                </div>
                <div class="col-md-6" id="ai-model-selection" style="display: block;">
                    <label for="ai-model-select" class="form-label"><strong>AI Model</strong></label>
                    <select id="ai-model-select" class="form-select form-select-sm">
                        <option value="gemini-2.5-flash-lite">🆓 Gemini 2.5 Flash Lite (Free - Recommended)</option>
                        <option value="gpt-4o-mini">⭐ GPT-4o Mini (Best Value - ~$0.22/1000 books)</option>
                        <option value="claude-3-5-haiku">💎 Claude 3.5 Haiku (Premium - ~$1.20/1000 books)</option>
                        <option value="gemini-2.0-flash-lite">🆓 Gemini 2.0 Flash Lite (Free - Fast)</option>
                        <option value="gpt-3.5-turbo">GPT-3.5 Turbo (~$0.60/1000 books)</option>
                        <option value="claude-3-5-sonnet">Claude 3.5 Sonnet (High Quality - ~$4.50/1000 books)</option>
                        <option value="gpt-4o">GPT-4o (Latest - ~$3.75/1000 books)</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-between">
        <div>
            <small class="text-muted">
                <span id="ai-cost-info">💰 Cost estimate will be shown based on selection</span>
            </small>
        </div>
        <div>
            <button id="import-select-btn" class="btn btn-success" disabled>
                <span id="import-btn-text">Select & Process with AI</span>
            </button>
        </div>
    </div>
</div>
