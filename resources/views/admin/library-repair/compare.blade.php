@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Resolve Duplicate Directory</h1>
            <p class="text-muted mb-0">
                <strong>Shared path:</strong> <code>{{ $issue['directoryPath'] ?? '—' }}</code>
            </p>
        </div>
        <a href="{{ route('admin.library-repair.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @php
        $staticFields = ['duration', 'audio_file_count', 'created_at', 'updated_at'];
        $mergeableFields = array_keys(array_filter($compareFields, fn($label, $field) => !in_array($field, $staticFields, true), ARRAY_FILTER_USE_BOTH));
        $firstBookId = $books->first()?->id;
    @endphp

    <form method="POST" action="{{ route('admin.library-repair.resolve-duplicate', $issue['id']) }}">
        @csrf

        {{-- Hidden: keeper book and field sources (managed by JS) --}}
        <input type="hidden" name="keep_book_id" id="keep_book_id" value="{{ $firstBookId }}">
        @foreach($mergeableFields as $field)
            <input type="hidden" name="field_sources[{{ $field }}]" id="field_source_{{ $field }}" value="{{ $firstBookId }}">
        @endforeach

        {{-- Metadata comparison table --}}
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <strong>Metadata</strong>
                <span class="text-muted small">Click a column header to select all fields from that book, or click individual cells to override per field.</span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle mb-0" id="compare-table">
                    <thead>
                        <tr>
                            <th class="table-light" style="min-width:130px;">Field</th>
                            @foreach($books as $book)
                                <th class="book-header text-center"
                                    style="min-width:200px; cursor:pointer;"
                                    data-book-id="{{ $book->id }}"
                                    onclick="selectBook({{ $book->id }})">
                                    <div class="d-flex flex-column align-items-center gap-1">
                                        <span class="fw-semibold">
                                            Book #{{ $book->id }}
                                            @if($book->trashed())
                                                <span class="badge text-bg-danger ms-1">Trashed</span>
                                            @endif
                                        </span>
                                        <span class="badge rounded-pill text-bg-secondary select-badge" id="badge_{{ $book->id }}">
                                            Click to select all
                                        </span>
                                        <a href="{{ route('admin.books.edit', $book->id) }}" target="_blank"
                                            class="small link-primary" onclick="event.stopPropagation()">
                                            Edit &rarr;
                                        </a>
                                    </div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($compareFields as $field => $label)
                            @php
                                $isStatic = in_array($field, $staticFields, true);
                                $differs = $hasDiff[$field] ?? false;
                            @endphp
                            <tr>
                                <td class="fw-semibold text-muted small text-uppercase table-light">
                                    {{ $label }}
                                    @if($field === 'duration' || $field === 'audio_file_count')
                                        <div class="text-muted fw-normal" style="font-size:0.7em;">(follows files)</div>
                                    @endif
                                </td>
                                @foreach($books as $index => $book)
                                    @php
                                        $val = $fieldValues[$field][$index] ?? '';
                                    @endphp
                                    <td
                                        @if(!$isStatic && $differs)
                                            class="book-cell"
                                            style="cursor:pointer;"
                                            data-book-id="{{ $book->id }}"
                                            data-field="{{ $field }}"
                                            id="cell_{{ $field }}_{{ $book->id }}"
                                            onclick="selectField('{{ $field }}', {{ $book->id }})"
                                        @elseif($isStatic)
                                            class="table-light text-muted"
                                        @else
                                            class="table-light"
                                        @endif
                                    >
                                        @if($field === 'description' && $val !== '')
                                            <span class="small" style="max-height:80px;overflow-y:auto;display:block;">{{ Str::limit($val, 200) }}</span>
                                        @elseif($val !== '')
                                            {{ $val }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- File listing --}}
        @php
            $groupLabels = ['audio' => 'Audio', 'image' => 'Images', 'other' => 'Other'];
            $defaultChecked = ['audio', 'image'];
        @endphp
        @foreach($groupLabels as $groupKey => $groupLabel)
            @php $files = $fileGroups[$groupKey] ?? []; @endphp
            @if(count($files) > 0)
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center gap-3">
                        <strong>{{ $groupLabel }} Files</strong>
                        <span class="badge text-bg-secondary">{{ count($files) }}</span>
                        <div class="form-check ms-auto mb-0">
                            <input class="form-check-input group-select-all"
                                type="checkbox"
                                id="select_all_{{ $groupKey }}"
                                data-group="{{ $groupKey }}"
                                @if(in_array($groupKey, $defaultChecked)) checked @endif>
                            <label class="form-check-label small" for="select_all_{{ $groupKey }}">Select all</label>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <tbody>
                                @foreach($files as $file)
                                    <tr>
                                        <td style="width:40px;">
                                            <input class="form-check-input file-checkbox"
                                                type="checkbox"
                                                name="keep_files[]"
                                                value="{{ $file['name'] }}"
                                                data-group="{{ $groupKey }}"
                                                @if(in_array($groupKey, $defaultChecked)) checked @endif>
                                        </td>
                                        <td>{{ $file['name'] }}</td>
                                        <td class="text-muted small text-end">
                                            {{ number_format(($file['size'] ?? 0) / 1048576, 1) }} MB
                                        </td>
                                        <td>
                                            <span class="badge text-bg-light text-muted">{{ strtolower($file['extension'] ?? '') }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endforeach

        {{-- Keeper selector + submit --}}
        <div class="card mb-4">
            <div class="card-header"><strong>Choose Keeper Book</strong></div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Unchecked files above will be moved to trash. Non-keeper book DB records will be trashed (shared directory files otherwise stay).
                </p>
                <div class="d-flex flex-column gap-2 mb-4">
                    @foreach($books as $book)
                        <div class="form-check border rounded p-3">
                            <input class="form-check-input keeper-radio" type="radio"
                                name="_keep_book_display" id="keep_{{ $book->id }}"
                                value="{{ $book->id }}"
                                @if($loop->first) checked @endif
                                onchange="setKeeper({{ $book->id }})">
                            <label class="form-check-label w-100" for="keep_{{ $book->id }}">
                                <div class="fw-semibold">
                                    Book #{{ $book->id }} — {{ $book->title ?: '(no title)' }}
                                    @if($book->trashed())
                                        <span class="badge text-bg-danger ms-1">Already trashed</span>
                                    @endif
                                </div>
                                @if($book->authors->isNotEmpty())
                                    <div class="text-muted small">{{ $book->authors->pluck('name')->join(', ') }}</div>
                                @endif
                                <div class="text-muted small">
                                    Created: {{ $book->created_at?->format('Y-m-d H:i') ?? '—' }}
                                </div>
                            </label>
                        </div>
                    @endforeach
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-danger">Save &amp; Resolve</button>
                    <a href="{{ route('admin.library-repair.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </div>

    </form>
</div>

<script>
(function () {
    const mergeableFields = @json($mergeableFields);
    const bookIds = @json($books->pluck('id')->all());
    const selectedSource = {};

    // Initialize all fields to first book
    mergeableFields.forEach(function (field) {
        selectedSource[field] = bookIds[0];
    });

    function updateHiddenInputs() {
        mergeableFields.forEach(function (field) {
            const el = document.getElementById('field_source_' + field);
            if (el) el.value = selectedSource[field];
        });
    }

    function updateCellHighlights() {
        // Reset all book headers
        bookIds.forEach(function (id) {
            const header = document.querySelector('[data-book-id="' + id + '"].book-header');
            if (header) header.classList.remove('table-primary', 'table-secondary');

            const badge = document.getElementById('badge_' + id);
            if (badge) {
                badge.classList.remove('text-bg-primary');
                badge.classList.add('text-bg-secondary');
                badge.textContent = 'Click to select all';
            }
        });

        // Highlight cells and headers
        mergeableFields.forEach(function (field) {
            const chosen = selectedSource[field];
            bookIds.forEach(function (id) {
                const cell = document.getElementById('cell_' + field + '_' + id);
                if (!cell) return;
                if (id === chosen) {
                    cell.classList.add('table-primary');
                    cell.classList.remove('table-secondary');
                } else {
                    cell.classList.remove('table-primary');
                }
            });
        });

        // If all fields for a book are selected, highlight its header
        bookIds.forEach(function (id) {
            const allMine = mergeableFields.every(function (field) {
                return selectedSource[field] === id;
            });
            const header = document.querySelector('[data-book-id="' + id + '"].book-header');
            const badge = document.getElementById('badge_' + id);
            if (allMine) {
                if (header) header.classList.add('table-primary');
                if (badge) {
                    badge.classList.remove('text-bg-secondary');
                    badge.classList.add('text-bg-primary');
                    badge.textContent = 'Selected';
                }
            }
        });
    }

    window.selectBook = function (bookId) {
        mergeableFields.forEach(function (field) {
            selectedSource[field] = bookId;
        });
        updateHiddenInputs();
        updateCellHighlights();
    };

    window.selectField = function (field, bookId) {
        selectedSource[field] = bookId;
        updateHiddenInputs();
        updateCellHighlights();
    };

    window.setKeeper = function (bookId) {
        document.getElementById('keep_book_id').value = bookId;
    };

    // Group select-all checkboxes
    document.querySelectorAll('.group-select-all').forEach(function (masterCb) {
        masterCb.addEventListener('change', function () {
            const group = masterCb.dataset.group;
            document.querySelectorAll('.file-checkbox[data-group="' + group + '"]').forEach(function (cb) {
                cb.checked = masterCb.checked;
            });
        });
    });

    // Individual file checkbox → update group master
    document.querySelectorAll('.file-checkbox').forEach(function (cb) {
        cb.addEventListener('change', function () {
            const group = cb.dataset.group;
            const groupCbs = document.querySelectorAll('.file-checkbox[data-group="' + group + '"]');
            const allChecked = Array.from(groupCbs).every(function (c) { return c.checked; });
            const masterCb = document.getElementById('select_all_' + group);
            if (masterCb) masterCb.checked = allChecked;
        });
    });

    // Initialize UI
    updateCellHighlights();
})();
</script>
@endsection
