@php
    $isDelete = $results['is_delete'] ?? false;
    $entityType = $results['entity_type'] ?? 'books';
@endphp

<div class="card">
    <div class="card-header {{ $isDelete ? 'bg-danger text-white' : 'bg-warning' }}">
        <h5 class="mb-0">
            <i class="fas {{ $isDelete ? 'fa-trash-alt' : 'fa-edit' }}"></i>
            {{ $isDelete ? 'Bulk Delete Preview' : 'Bulk Update Preview' }}
            @if(isset($results['preview']))
                ({{ count($results['preview']) }} items)
            @endif
        </h5>
    </div>
    <div class="card-body">
        @if(isset($results['preview']) && count($results['preview']) > 0)
            <form action="{{ route('admin.ai-query.apply-bulk-update') }}" method="POST" id="bulk-update-form">
                @csrf
                <input type="hidden" name="query_id" value="{{ $queryId }}">

                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                        <i class="fas fa-check-square"></i> Select All
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectNone()">
                        <i class="fas fa-square"></i> Select None
                    </button>
                    <button type="submit" class="btn btn-sm btn-success ms-3" onclick="return confirmBulkUpdate()">
                        <i class="fas fa-save"></i> Apply Selected Changes
                    </button>
                </div>

                <div class="bulk-update-preview" style="max-height: 600px; overflow-y: auto;">
                    @foreach($results['preview'] as $item)
                        @php
                            $itemName = $item['before']['title'] ?? $item['before']['name'] ?? 'Item #' . $item['id'];
                            $itemEntityType = $item['entity_type'] ?? $entityType;
                            $changes = $item['changes'] ?? [];

                            // Determine if we can use compact one-line format
                            $useCompactFormat = $isDelete || (count($changes) === 1 && !$isDelete);

                            // Build compact change summary for one-line display
                            $compactChangeSummary = '';
                            if (!$isDelete && count($changes) === 1) {
                                foreach ($changes as $field => $change) {
                                    $fromValue = is_array($change['from']) ? implode(', ', $change['from']) : $change['from'];
                                    $toValue = is_array($change['to']) ? implode(', ', $change['to']) : $change['to'];
                                    $compactChangeSummary = ucfirst(str_replace('_', ' ', $field)) . ': ' .
                                        '<span class="text-muted">' . $fromValue . '</span> → ' .
                                        '<span class="text-success fw-bold">' . $toValue . '</span>';
                                }
                            }
                        @endphp

                        @if($useCompactFormat)
                            {{-- Compact one-line format for deletes and single-field changes --}}
                            <div class="form-check py-1 border-bottom">
                                <input
                                    class="form-check-input bulk-checkbox"
                                    type="checkbox"
                                    name="selected_ids[]"
                                    value="{{ $item['id'] }}"
                                    id="item-{{ $item['id'] }}">
                                <label class="form-check-label" for="item-{{ $item['id'] }}">
                                    <strong>{{ $itemName }}</strong>
                                    @if($isDelete)
                                        <span class="badge bg-danger ms-2">Will be deleted</span>
                                    @else
                                        <span class="ms-2">- {!! $compactChangeSummary !!}</span>
                                    @endif
                                </label>
                            </div>
                        @else
                            {{-- Full card format for complex multi-field changes --}}
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="form-check mb-2">
                                        <input
                                            class="form-check-input bulk-checkbox"
                                            type="checkbox"
                                            name="selected_ids[]"
                                            value="{{ $item['id'] }}"
                                            id="item-{{ $item['id'] }}">
                                        <label class="form-check-label fw-bold" for="item-{{ $item['id'] }}">
                                            {{ $itemName }}
                                        </label>
                                    </div>

                                    @if(!empty($changes))
                                        <div class="changes-list">
                                            @foreach($changes as $field => $change)
                                                <div class="mb-2">
                                                    <strong>{{ ucfirst(str_replace('_', ' ', $field)) }}:</strong><br>
                                                    <span class="badge bg-danger">
                                                        From: {{ is_array($change['from']) ? implode(', ', $change['from']) : $change['from'] }}
                                                    </span>
                                                    <i class="fas fa-arrow-right mx-2"></i>
                                                    <span class="badge bg-success">
                                                        To: {{ is_array($change['to']) ? implode(', ', $change['to']) : $change['to'] }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <p class="text-muted mb-0">No changes</p>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </form>

            <script>
                function selectAll() {
                    document.querySelectorAll('.bulk-checkbox').forEach(cb => cb.checked = true);
                }

                function selectNone() {
                    document.querySelectorAll('.bulk-checkbox').forEach(cb => cb.checked = false);
                }

                function confirmBulkUpdate() {
                    const selected = document.querySelectorAll('.bulk-checkbox:checked').length;
                    if (selected === 0) {
                        alert('Please select at least one item to update.');
                        return false;
                    }
                    return confirm(`Are you sure you want to apply changes to ${selected} item(s)?`);
                }
            </script>
        @else
            <p class="text-muted">No items to update.</p>
        @endif
    </div>
</div>
