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
                        @include('admin.ai-query.partials.bulk-update-row', ['item' => $item])
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

                // Refinement Modal Logic
                let currentRefineId = null;
                const refineLoadingSpinner = `<div class="spinner-border spinner-border-sm text-light" role="status"></div> Processing...`;

                function openRefineModal(itemId) {
                    currentRefineId = itemId;
                    document.getElementById('refine_item_id').value = itemId;
                    document.getElementById('refine_instruction').value = '';
                    new bootstrap.Modal(document.getElementById('refineModal')).show();
                }

                function submitRefinement() {
                    const instruction = document.getElementById('refine_instruction').value;
                    if (!instruction) return;

                    const btn = document.getElementById('btn-submit-refine');
                    const originalBtnText = btn.innerHTML;
                    btn.innerHTML = refineLoadingSpinner;
                    btn.disabled = true;

                    fetch("{{ route('admin.ai-query.refine-item') }}", {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        },
                        body: JSON.stringify({
                            query_id: {{ $queryId }},
                            item_id: currentRefineId,
                            instruction: instruction
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update the row content
                            const row = document.getElementById('row-' + currentRefineId);
                            if (row && data.html) {
                                row.innerHTML = data.html;
                                // Unwrap if the partial returned a wrapper (it checks row-id, likely wrapper)
                                // Actually, my partial has <div id="row-...">
                                // So row.innerHTML = data.html would result in <div id="row..."><div id="row...">...</div></div>
                                // Better to replace the whole element.
                                row.outerHTML = data.html;
                            }

                            // Close modal
                            const modalEl = document.getElementById('refineModal');
                            const modal = bootstrap.Modal.getInstance(modalEl);
                            modal.hide();
                        } else {
                            alert('Error: ' + (data.error || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred during refinement.');
                    })
                    .finally(() => {
                        btn.innerHTML = originalBtnText;
                        btn.disabled = false;
                    });
                }
            </script>

            <!-- Refine Modal -->
            <div class="modal fade" id="refineModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Refine Item</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" id="refine_item_id">
                            <div class="mb-3">
                                <label for="refine_instruction" class="form-label">Instruction</label>
                                <textarea class="form-control" id="refine_instruction" rows="3" placeholder="e.g., Use the UK cover, Fix the title spelling..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="btn-submit-refine" onclick="submitRefinement()">Apply Refinement</button>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <p class="text-muted">No items to update.</p>
        @endif
    </div>
</div>
