@php
    $entityType = $results['entity_type'] ?? 'books';
    $parseConfig = $results['parse_config'] ?? [];
@endphp

<div class="card">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0">
            <i class="fas fa-search"></i>
            Parse & Update Preview
            @if(isset($results['preview']))
                ({{ count($results['preview']) }} items found)
            @endif
        </h5>
    </div>
    <div class="card-body">
        @if(isset($results['preview']) && count($results['preview']) > 0)
            <div class="alert alert-info mb-3">
                <strong><i class="fas fa-info-circle"></i> Parsing Pattern:</strong>
                @if(isset($parseConfig['source_field']))
                    Extracting from <strong>{{ $parseConfig['source_field'] }}</strong> field
                @endif
                @if(isset($parseConfig['action']['details']))
                    - {{ $parseConfig['action']['details'] }}
                @endif
            </div>

            <form action="{{ route('admin.ai-query.apply-bulk-update') }}" method="POST" id="parse-update-form">
                @csrf
                <input type="hidden" name="query_id" value="{{ $queryId }}">

                <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                        <i class="fas fa-check-square"></i> Select All
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectNone()">
                        <i class="fas fa-square"></i> Select None
                    </button>
                    <button type="submit" class="btn btn-sm btn-success ms-3" onclick="return confirmParseUpdate()">
                        <i class="fas fa-save"></i> Apply Selected Changes
                    </button>
                </div>

                <div class="parse-update-preview" style="max-height: 600px; overflow-y: auto;">
                    @foreach($results['preview'] as $item)
                        @php
                            $itemName = $item['title'] ?? 'Item #' . $item['id'];
                            $parsedData = $item['parsed_data'] ?? [];
                            $actionType = $item['action']['type'] ?? 'unknown';
                        @endphp

                        <div class="card mb-2">
                            <div class="card-body py-2">
                                <div class="form-check">
                                    <input
                                        class="form-check-input bulk-checkbox"
                                        type="checkbox"
                                        name="selected_ids[]"
                                        value="{{ $item['id'] }}"
                                        id="item-{{ $item['id'] }}">
                                    <label class="form-check-label" for="item-{{ $item['id'] }}">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <strong>{{ $itemName }}</strong>
                                                <div class="text-muted small mt-1">
                                                    <i class="fas fa-quote-left"></i>
                                                    {{ $item['original_value'] }}
                                                </div>
                                            </div>
                                            <div class="ms-3 text-end">
                                                @if($actionType === 'add_series')
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-plus"></i> Add Series
                                                    </span>
                                                    <div class="small mt-1">
                                                        <strong>{{ $parsedData['series_name'] ?? '' }}</strong>
                                                        @if(isset($parsedData['series_number']))
                                                            #{{ $parsedData['series_number'] }}
                                                        @endif
                                                    </div>
                                                @else
                                                    <span class="badge bg-primary">
                                                        {{ ucfirst(str_replace('_', ' ', $actionType)) }}
                                                    </span>
                                                    <div class="small mt-1">
                                                        @foreach($parsedData as $key => $value)
                                                            <strong>{{ $key }}:</strong> {{ $value }}<br>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
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

                function confirmParseUpdate() {
                    const selected = document.querySelectorAll('.bulk-checkbox:checked').length;
                    if (selected === 0) {
                        alert('Please select at least one item to update.');
                        return false;
                    }
                    return confirm(`Are you sure you want to apply parsed updates to ${selected} item(s)?`);
                }
            </script>
        @else
            <p class="text-muted">No items matched the parsing pattern.</p>
        @endif
    </div>
</div>
