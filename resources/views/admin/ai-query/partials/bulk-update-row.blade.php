@php
    $changes = $item['changes'] ?? [];
    $itemName = $item['title'] ?? 'Book #' . ($item['book_id'] ?? $item['id']);

    // Normalize changes structure (handle both old keyed format and new indexed format if needed)
    // New ToolExecutor returns indexed array of objects: { type, field, current, proposed ... }

    $isIndexed = isset($changes[0]);
@endphp

<div id="row-{{ $item['book_id'] ?? $item['id'] }}" class="bulk-update-row-wrapper">
    @if(empty($changes) && empty($item['error']))
        <div class="alert alert-info py-1 mb-2">
            No changes proposed for <strong>{{ $itemName }}</strong>
        </div>
    @elseif(!empty($item['error']))
        <div class="alert alert-danger py-1 mb-2">
            Error for <strong>{{ $itemName }}</strong>: {{ $item['error'] }}
        </div>
    @else
        <div class="card mb-3 border-secondary">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <div class="form-check">
                    <input class="form-check-input bulk-checkbox" type="checkbox" name="selected_ids[]" value="{{ $item['book_id'] ?? $item['id'] }}" id="check-{{ $item['book_id'] ?? $item['id'] }}" checked>
                    <label class="form-check-label fw-bold" for="check-{{ $item['book_id'] ?? $item['id'] }}">
                        {{ $itemName }}
                    </label>
                </div>
                <button type="button" class="btn btn-xs btn-outline-primary" onclick="openRefineModal({{ $item['book_id'] ?? $item['id'] }})">
                    <i class="fas fa-magic"></i> Refine
                </button>
            </div>
            <div class="card-body py-2">
                @foreach($changes as $key => $change)
                    @php
                        // Handle new format
                        if ($isIndexed) {
                            $field = $change['field'] ?? 'Unknown';
                            $type = $change['type'] ?? 'unknown';
                            $from = $change['current'] ?? 'null';
                            $to = $change['proposed'] ?? 'null';

                            // Format arrays
                            if (is_array($from)) $from = implode(', ', $from);
                            if (is_array($to)) $to = implode(', ', $to);

                            // Special handling for paths/covers
                            if ($type === 'cover_update') {
                                $from = basename($from); // Show filename
                                // $to is a URL
                            }
                        } else {
                            // Old format (fallback)
                            $field = $key;
                            $from = is_array($change['from']) ? implode(', ', $change['from']) : $change['from'];
                            $to = is_array($change['to']) ? implode(', ', $change['to']) : $change['to'];
                            $type = 'update';
                        }
                    @endphp

                    <div class="row align-items-center mb-1">
                        <div class="col-md-3 text-muted small text-uppercase fw-bold">
                            {{ str_replace('_', ' ', $field) }}
                        </div>
                        <div class="col-md-9">
                            @if($type === 'cover_update')
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-secondary me-2">Current Cover</span>
                                    <i class="fas fa-arrow-right mx-2 text-muted"></i>
                                    <a href="{{ $to }}" target="_blank" class="badge bg-success text-decoration-none">
                                        <i class="fas fa-image"></i> New Image URL
                                    </a>
                                    <img src="{{ $to }}" style="height: 30px; margin-left: 10px; border-radius: 4px;" onerror="this.style.display='none'">
                                </div>
                            @elseif($type === 'file_delete')
                                <span class="badge bg-danger">DELETE FILE: {{ $from }}</span>
                            @else
                                <span class="text-decoration-line-through text-danger me-2">{{ $from }}</span>
                                <i class="fas fa-long-arrow-alt-right text-muted mx-1"></i>
                                <span class="text-success fw-bold">{{ $to }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
