@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start mb-3 gap-3">
        <div>
            <h1 class="h3 mb-1">Resolve Duplicate Directory</h1>
            <p class="text-muted mb-0">
                <strong>Shared path:</strong> <code>{{ $issue['directoryPath'] ?? '—' }}</code>
            </p>
        </div>
        <a href="{{ route('admin.library-repair.index') }}" class="btn btn-outline-secondary btn-sm">← Back</a>
    </div>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @php
        $staticFields = ['duration', 'audio_file_count', 'created_at', 'updated_at'];
        $mergeableFields = array_keys(array_filter(
            $compareFields,
            fn($label, $field) => !in_array($field, $staticFields, true),
            ARRAY_FILTER_USE_BOTH
        ));
        $firstBookId = $books->first()?->id;
        $hasFiles = collect($fileGroups)->flatten(1)->isNotEmpty();
        $groupLabels = ['audio' => 'Audio', 'image' => 'Images', 'other' => 'Other'];
    @endphp

    <form method="POST" action="{{ route('admin.library-repair.resolve-duplicate', $issue['id']) }}">
        @csrf

        {{-- Hidden inputs managed by JS --}}
        <input type="hidden" name="keep_book_id" id="keep_book_id" value="{{ $firstBookId }}">
        @foreach($mergeableFields as $field)
            <input type="hidden" name="field_sources[{{ $field }}]" id="field_source_{{ $field }}" value="{{ $firstBookId }}">
        @endforeach
        <input type="hidden" name="field_sources[cover_image]" id="field_source_cover_image" value="{{ $firstBookId }}">

        @php
            $bookCount = $books->count();
            $labelPct = 12;
            $bookPct = (int) floor((100 - $labelPct) / max(1, $bookCount));
        @endphp
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-0" id="compare-table" style="table-layout:fixed; width:100%;">
                <colgroup>
                    <col style="width:{{ $labelPct }}%;">
                    @foreach($books as $book)
                        <col style="width:{{ $bookPct }}%;">
                    @endforeach
                </colgroup>
                <thead class="table-light sticky-top">
                    <tr>
                        <th></th>
                        @foreach($books as $book)
                            <th class="book-col-header text-center"
                                data-book-id="{{ $book->id }}"
                                style="cursor:pointer;"
                                onclick="selectBook({{ $book->id }})"
                                title="Click to use all fields from this book">
                                <div class="fw-semibold">
                                    Book #{{ $book->id }}
                                    @if($book->trashed())
                                        <span class="badge text-bg-danger ms-1">Trashed</span>
                                    @endif
                                </div>
                                <div class="small text-muted text-truncate">{{ $book->title ?: '(no title)' }}</div>
                                <div class="mt-1">
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

                    {{-- Cover image row --}}
                    @php
                        $coverValues = $books->map(fn($b) => $b->cover_image ?? '')->all();
                        $coversdiffer = count(array_unique($coverValues)) > 1;
                    @endphp
                    <tr>
                        <td class="fw-semibold text-muted small text-uppercase table-light">Cover</td>
                        @foreach($books as $index => $book)
                            <td
                                id="cell_cover_image_{{ $book->id }}"
                                class="{{ $coversdiffer ? 'book-cell' : 'table-secondary' }}"
                                @if($coversdiffer)
                                    style="cursor:pointer;"
                                    data-book-id="{{ $book->id }}"
                                    data-field="cover_image"
                                    onclick="selectCover({{ $book->id }})"
                                @endif
                            >
                                @php $url = $coverUrls[$index] ?? asset('images/placeholder.png'); @endphp
                                <img src="{{ $url }}" alt="Cover" style="max-height:120px; max-width:100%; display:block; margin:0 auto;">
                            </td>
                        @endforeach
                    </tr>

                    {{-- Metadata field rows --}}
                    @foreach($compareFields as $field => $label)
                        @php
                            $isStatic = in_array($field, $staticFields, true);
                            $differs = $hasDiff[$field] ?? false;
                        @endphp
                        <tr>
                            <td class="fw-semibold text-muted small text-uppercase table-light">
                                {{ $label }}
                                @if($field === 'duration' || $field === 'audio_file_count')
                                    <div class="fw-normal" style="font-size:0.7em;">(follows files)</div>
                                @endif
                            </td>
                            @foreach($books as $index => $book)
                                @php $val = $fieldValues[$field][$index] ?? ''; @endphp
                                <td
                                    @if(!$isStatic && $differs)
                                        class="book-cell"
                                        style="cursor:pointer;"
                                        data-book-id="{{ $book->id }}"
                                        data-field="{{ $field }}"
                                        id="cell_{{ $field }}_{{ $book->id }}"
                                        onclick="selectField('{{ $field }}', {{ $book->id }})"
                                    @else
                                        class="table-secondary text-muted"
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

                    {{-- File rows --}}
                    @if($hasFiles)
                        <tr class="table-secondary">
                            <td colspan="{{ $books->count() + 1 }}" class="fw-semibold small text-uppercase py-1">
                                Files in shared directory
                                <span class="text-muted fw-normal">(checked = keep, unchecked = move to trash)</span>
                            </td>
                        </tr>
                        @foreach($groupLabels as $groupKey => $groupLabel)
                            @php $files = $fileGroups[$groupKey] ?? []; @endphp
                            @if(count($files) > 0)
                                <tr class="table-light">
                                    <td class="small text-muted fw-semibold ps-3">{{ $groupLabel }}</td>
                                    <td colspan="{{ $books->count() }}" class="py-1">
                                        <label class="small text-muted me-3" style="cursor:pointer;">
                                            <input class="form-check-input group-select-all me-1"
                                                type="checkbox"
                                                data-group="{{ $groupKey }}"
                                                id="select_all_{{ $groupKey }}"
                                                checked>
                                            select all / none
                                        </label>
                                    </td>
                                </tr>
                                @foreach($files as $file)
                                    <tr>
                                        <td class="text-muted small ps-3" style="font-size:0.8em;">
                                            <span class="badge text-bg-light border">{{ strtolower($file['extension'] ?? '') }}</span>
                                        </td>
                                        <td colspan="{{ $books->count() }}">
                                            <label class="d-flex align-items-center gap-2 mb-0" style="cursor:pointer;">
                                                <input class="form-check-input file-checkbox"
                                                    type="checkbox"
                                                    name="keep_files[]"
                                                    value="{{ $file['name'] }}"
                                                    data-group="{{ $groupKey }}"
                                                    checked>
                                                <span class="text-break">{{ $file['name'] }}
                                                    <span class="text-muted ms-1">{{ number_format(($file['size'] ?? 0) / 1048576, 1) }} MB</span>
                                                </span>
                                            </label>
                                        </td>
                                    </tr>
                                @endforeach
                            @endif
                        @endforeach
                    @endif

                    {{-- Submit row: button + summary in keeper column; cancel always visible --}}
                    <tr class="table-light">
                        <td></td>
                        @foreach($books as $book)
                            <td class="py-2" id="submit_cell_{{ $book->id }}">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <button type="submit" class="btn btn-danger btn-sm" id="submit_{{ $book->id }}"
                                        style="display:none;">
                                        Save &amp; Resolve
                                    </button>
                                    <span class="text-muted small" id="summary_{{ $book->id }}" style="display:none;"></span>
                                </div>
                            </td>
                        @endforeach
                    </tr>

                </tbody>
            </table>
        </div>

    </form>
</div>

<script>
(function () {
    const mergeableFields = @json($mergeableFields);
    const bookIds = @json($books->pluck('id')->all());
    const coversdiffer = {{ $coversdiffer ? 'true' : 'false' }};
    // selectedSource[field] = bookId whose value to use (defaults to keeper)
    const selectedSource = {};
    let keeperId = bookIds[0];

    mergeableFields.forEach(function (field) {
        selectedSource[field] = keeperId;
    });
    selectedSource['cover_image'] = keeperId;

    function updateHiddenInputs() {
        document.getElementById('keep_book_id').value = keeperId;
        mergeableFields.forEach(function (field) {
            const el = document.getElementById('field_source_' + field);
            if (el) el.value = selectedSource[field];
        });
    }

    function updateHighlights() {
        // Header: keeper column always highlighted
        bookIds.forEach(function (id) {
            const header = document.querySelector('.book-col-header[data-book-id="' + id + '"]');
            if (header) header.classList.toggle('table-primary', id === keeperId);
        });

        // Submit row: button, cancel, and summary appear only in keeper column
        const counts = {};
        bookIds.forEach(function (id) { counts[id] = 0; });
        mergeableFields.forEach(function (f) {
            if (counts[selectedSource[f]] !== undefined) counts[selectedSource[f]]++;
        });
        bookIds.forEach(function (id) {
            const btn = document.getElementById('submit_' + id);
            const summary = document.getElementById('summary_' + id);
            const isKeeper = id === keeperId;
            if (btn) btn.style.display = isKeeper ? '' : 'none';
            if (summary) {
                summary.style.display = isKeeper ? '' : 'none';
                if (isKeeper) {
                    const overrideCount = bookIds
                        .filter(function (bid) { return bid !== keeperId; })
                        .reduce(function (sum, bid) { return sum + (counts[bid] || 0); }, 0);
                    const parts = bookIds
                        .filter(function (bid) { return bid !== keeperId && counts[bid] > 0; })
                        .map(function (bid) {
                            return counts[bid] + ' field' + (counts[bid] === 1 ? '' : 's') + ' from Book #' + bid;
                        });
                    summary.textContent = overrideCount > 0
                        ? 'Merging in: ' + parts.join(', ')
                        : 'No fields merged from other book';
                }
            }
        });

        // Cells: highlight whichever cell is the chosen source for that field
        document.querySelectorAll('.book-cell').forEach(function (cell) {
            const field = cell.dataset.field;
            const bookId = parseInt(cell.dataset.bookId, 10);
            cell.classList.toggle('table-primary', selectedSource[field] === bookId);
        });

        // Cover image cells
        if (coversdiffer) {
            bookIds.forEach(function (id) {
                const cell = document.getElementById('cell_cover_image_' + id);
                if (cell) cell.classList.toggle('table-primary', selectedSource['cover_image'] === id);
            });
        }
    }

    // Clicking a header: set that book as keeper, reset all field sources to keeper
    window.selectBook = function (bookId) {
        keeperId = bookId;
        mergeableFields.forEach(function (field) { selectedSource[field] = keeperId; });
        selectedSource['cover_image'] = keeperId;
        document.getElementById('field_source_cover_image').value = keeperId;
        updateHiddenInputs();
        updateHighlights();
    };

    window.selectCover = function (bookId) {
        selectedSource['cover_image'] = bookId;
        document.getElementById('field_source_cover_image').value = bookId;
        updateHighlights();
    };

    // Clicking a non-keeper cell: pull that field's value from the other book
    window.selectField = function (field, bookId) {
        selectedSource[field] = bookId;
        updateHiddenInputs();
        updateHighlights();
    };

    // Group select-all
    document.querySelectorAll('.group-select-all').forEach(function (master) {
        master.addEventListener('change', function () {
            document.querySelectorAll('.file-checkbox[data-group="' + master.dataset.group + '"]')
                .forEach(function (cb) { cb.checked = master.checked; });
        });
    });

    // Per-file checkbox updates master
    document.querySelectorAll('.file-checkbox').forEach(function (cb) {
        cb.addEventListener('change', function () {
            const group = cb.dataset.group;
            const all = document.querySelectorAll('.file-checkbox[data-group="' + group + '"]');
            const master = document.getElementById('select_all_' + group);
            if (master) master.checked = Array.from(all).every(function (c) { return c.checked; });
        });
    });

    // Initialize
    updateHiddenInputs();
    updateHighlights();
})();
</script>
@endsection
