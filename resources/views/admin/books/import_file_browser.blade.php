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
    <div class="d-flex justify-content-end">
        <button id="import-select-btn" class="btn btn-success" disabled>Select</button>
    </div>
</div>
