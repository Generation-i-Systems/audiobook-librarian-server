@extends(isset($layout) ? $layout : 'layouts.app')

@section('content')
@dump($book)
<div class="container">
    @if(empty($isModal))
        <h1>{{ isset($book) ? 'Edit Book' : 'Create New Book' }}</h1>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if(session('errors') && session('errors')->has('general'))
        <div class="alert alert-danger">{{ session('errors')->first('general') }}</div>
    @endif
    @if ($errors && $errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ isset($book) ? route('admin.books.update', ['book' => $book['id'] ?? 0]) : route('admin.books.store') }}"
        method="POST" enctype="multipart/form-data" id="book-{{ isset($book) ? 'edit' : 'form' }}" class="mt-3">
        @csrf
        @if(isset($book))
            @method('PUT')
        @endif
        @if(isset($book) && $book['directory_path'])
            <input type="hidden" name="original_directory_path" value="{{ $book['directory_path'] }}">
        @elseif(old('directory_path'))
            <input type="hidden" name="original_directory_path" value="{{ old('directory_path') }}">
        @endif
        <button type="button" class="btn btn-info mb-3" id="autofill-btn"><i class="fas fa-search"></i> Autofill from
            Google Books</button>
        <div class="mb-3">
            <label for="title">Title:</label>
            <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title"
                value="{{ old('title', isset($book) ? $book['title'] : ($initial['title'] ?? null)) }}" required>
            @error('title')
                <span class="invalid-feedback d-block">{{ $message }}</span>
            @enderror
        </div>
        <div class="mb-3">
            <label class="form-label">Authors</label>
            <div id="authors-group">
                @php
                    $authors = old('author', isset($book) ? (is_array($book['author']) ? $book['author'] : [$book['author']]) : ($initial['author'] ?? []));
                    if (!is_array($authors))
                        $authors = [$authors];
                @endphp
                @php $authorsCount = count($authors); @endphp
                @foreach($authors as $idx => $author)
                    <div class="input-group author-row align-items-start mb-3">
                        <input type="text" name="author[]" class="form-control w-auto" style="max-width:300px; height:32px;"
                            value="{{ $author }}" required>
                        <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                            <button type="button" class="btn btn-outline-danger btn-sm remove-author p-0 mb-0"
                                style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                            @if($idx === $authorsCount - 1)
                                <button type="button" class="btn btn-primary btn-sm add-author-row p-0 mt-1"
                                    style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

        </div>
        <div class="mb-3">
            <label class="form-label">Series</label>
            <div id="series-group">
                @php
                    $seriesList = [];
                    $oldSeries = old('series', []);
                    $oldSeriesNumbers = old('series_number', []);

                    // Log the raw series data for debugging
                    Log::debug('Series Data:', [
                        'book_series' => $book['series'] ?? null,
                        'old_series' => $oldSeries,
                        'old_series_numbers' => $oldSeriesNumbers,
                    ]);

                    // Handle existing book data
                    if (isset($book) && !empty($book['series'])) {
                        if (is_array($book['series'])) {
                            // Handle new format: ["Series Name" => number, ...]
                            foreach ($book['series'] as $name => $number) {
                                if (!empty($name)) {
                                    $seriesList[] = [
                                        'name' => $name,
                                        'number' => $number,
                                    ];
                                }
                            }
                        }
                        // Handle single series as string (legacy format)
                        else if (is_string($book['series'])) {
                            $seriesList[] = [
                                'name' => $book['series'],
                                'number' => $book['series_number'] ?? ''
                            ];
                        }
                    }


                    // Handle old input (validation errors)
                    if (!empty($oldSeries)) {
                        $seriesList = []; // Reset to use old input
                        foreach ($oldSeries as $i => $seriesName) {
                            if (!empty($seriesName)) {
                                $seriesList[] = [
                                    'name' => $seriesName,
                                    'number' => $oldSeriesNumbers[$i] ?? ''
                                ];
                            }
                        }
                    }

                    // Handle initial data if no series found yet
                    if (empty($seriesList) && !empty($initial['series'])) {
                        if (is_array($initial['series'])) {
                            foreach ($initial['series'] as $name => $number) {
                                if (!empty($name)) {
                                    $seriesList[] = [
                                        'name' => $name,
                                        'number' => $number
                                    ];
                                }
                            }
                        } else if (is_string($initial['series'])) {
                            $seriesList[] = [
                                'name' => $initial['series'],
                                'number' => $initial['series_number'] ?? ''
                            ];
                        }
                    }


                    // Ensure we have at least one empty series row
                    if (empty($seriesList)) {
                        $seriesList[] = ['name' => '', 'number' => ''];
                    }
                    $seriesCount = count($seriesList);
                @endphp
                @foreach($seriesList as $idx => $series)
                    @php
                        $name = $series['name'] ?? '';
                        $number = $series['number'] ?? '';
                        \Log::debug('Rendering series input', [
                            'series' => $series,
                            'name' => $name,
                            'number' => $number,
                            'debug_backtrace' => array_map(function($t) {
                                return ($t['file'] ?? 'unknown') . ':' . ($t['line'] ?? '?') . ' ' . ($t['function'] ?? '?');
                            }, array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), 0, 3))
                        ]);
                    @endphp
                    <div class="input-group series-row align-items-start mb-3">
                        <input type="text" name="series[]" class="form-control w-auto" style="max-width:200px; height:32px;"
                            placeholder="Series Name" value="{{ $name }}">
                        <input type="number" name="series_number[]" class="form-control w-auto"
                            style="max-width:100px; height:32px;" placeholder="Number" value="{{ $number }}" min="1" step="any">
                        <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                            <button type="button" class="btn btn-outline-danger btn-sm remove-series p-0 mb-0"
                                style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                            @if($idx === $seriesCount - 1)
                                <button type="button" class="btn btn-primary btn-sm add-series-row p-0 mt-1"
                                    style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

        </div>
        <div class="form-group">
            <label>Genres</label>
            <div id="genres-group">
                @php
                    $genres = old('genre', isset($book) ? (is_array($book['genre']) ? $book['genre'] : [$book['genre']]) : ($initial['genre'] ?? []));
                    if (!is_array($genres))
                        $genres = [$genres];
                @endphp
                @php $genresCount = count($genres); @endphp
                @foreach($genres as $idx => $genre)
                    <div class="input-group genre-row align-items-start mb-3">
                        <select name="genre[]" class="form-select w-auto" style="max-width:200px; height:32px;" required>
                            <option value="">Select a genre</option>
                            @foreach(config('genres.list', []) as $g)
                                <option value="{{ $g }}" {{ $genre === $g ? 'selected' : '' }}>{{ $g }}</option>
                            @endforeach
                        </select>
                        <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                            <button type="button" class="btn btn-outline-danger btn-sm remove-genre p-0 mb-0"
                                style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                            @if($idx === $genresCount - 1)
                                <button type="button" class="btn btn-primary btn-sm add-genre-row p-0 mt-1"
                                    style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="form-group">
            <label for="published_year">Published Year (Optional):</label>
            <input type="number" class="form-control @error('published_year') is-invalid @enderror" id="published_year"
                name="published_year" min="1000" max="9999"
                value="{{ old('published_year', isset($book) ? $book['published_year'] : ($initial['published_year'] ?? null)) }}">
            @error('published_year')
                <span class="invalid-feedback d-block">{{ $message }}</span>
            @enderror
        </div>
        <div class="form-group mb-3" style="margin-top: 0.5rem;">
            <a href="#" class="text-decoration-none" id="show-files-link">
                <i class="fas fa-folder-open me-1"></i>View Directory Files
            </a>
            <div id="directory-files-list"
                class="w-100 mt-2"
                style="max-height:220px; overflow-y:auto; border:1px solid #ccc; border-radius:4px; background:#fafbfc; padding:8px; display:none; position: relative;">
                <span class="text-muted">No files loaded yet.</span>
            </div>
        </div>
        @php
            $dirPath = isset($book) ? $book['directory_path'] : ($directory_path ?? $initial['directory_path'] ?? null);
            $coverImg = isset($book) ? $book['cover_image'] : ($initial['cover_image'] ?? null);
            $coverAuto = $coverAuto ?? null;
            $coverCandidates = $coverCandidates ?? [];
            $coverOptions = [];
            $addedCovers = [];

            // Get just the filename for the current cover
            $currentCoverFilename = !empty($coverImg) ? basename($coverImg) : null;

            // Always show current cover if it exists
            if (isset($book) && !empty($currentCoverFilename)) {
                $coverOptions[] = [
                    'type' => 'current',
                    'value' => $currentCoverFilename,
                    'src' => route('image.proxy', ['dir' => $dirPath, 'file' => $currentCoverFilename]),
                    'label' => 'Current Cover',
                    'display_name' => $currentCoverFilename,
                ];
                $addedCovers[] = $currentCoverFilename;
            }

            // Add Google Books cover if available and not the same as current cover
            if (!empty($coverAuto) && $coverAuto !== $currentCoverFilename) {
                $coverOptions[] = [
                    'type' => 'google',
                    'value' => $coverAuto,
                    'src' => route('image.proxy', ['dir' => $dirPath, 'file' => $coverAuto]),
                    'label' => 'Google Books',
                    'display_name' => $coverAuto,
                ];
                $addedCovers[] = $coverAuto;
            }

            // Add other candidates (already filtered in controller)
            if (!empty($coverCandidates)) {
                foreach ($coverCandidates as $candidate) {
                    if (!in_array($candidate, $addedCovers)) {
                        $coverOptions[] = [
                            'type' => 'candidate',
                            'value' => $candidate,
                            'src' => route('image.proxy', ['dir' => $dirPath, 'file' => $candidate]),
                            'label' => 'Candidate',
                            'display_name' => $candidate,
                        ];
                        $addedCovers[] = $candidate;
                    }
                }
            }
        @endphp

        @if (!empty($coverOptions))
        <div class="mb-3" id="cover-candidates-group">
            <label class="form-label">Select Cover Image:</label>
            <div class="d-flex flex-wrap gap-3" id="cover-candidates-list">
                @foreach($coverOptions as $option)
                <div class="text-center">
                    <label class="d-flex flex-column align-items-center">
                        <input type="radio" name="cover_image_candidate" value="{{ $option['value'] }}"
                            @if((isset($biggestCover) && $biggestCover === $option['value']) || (empty($biggestCover) && $option['type'] === 'current')) checked @endif class="mb-2">
                                                <img src="{{ $option['src'] }}" alt="{{ $option['label'] }}"
                                                    style="max-width:100px;max-height:140px;border:1px solid #ccc;">
                                            </label>
                                            <div class="mt-1" style="font-size:12px;word-break:break-all;">
                                                {{ $option['label'] }}<br>
                                                <small class="text-muted">{{ $option['display_name'] ?? $option['value'] }}</small>
                                            </div>
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

        <div id="google-books-matches-table-wrapper" style="display:none;">
            <label>Google Books: Select a Match</label>
            <table class="table table-bordered table-sm" id="google-books-matches-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Title</th>
                        <th>Authors</th>
                        <th>Year</th>
                        <th>Cover</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div class="form-group">
            <label for="cover_image">Cover Image (Optional):</label>
            <input type="file" class="form-control-file @error('cover_image') is-invalid @enderror" id="cover_image"
                name="cover_image">
            @error('cover_image')
                <span class="invalid-feedback d-block">{{ $message }}</span>
            @enderror
        </div>
        <div class="form-group">
            <label for="description">Description (Optional):</label>
            <textarea class="form-control @error('description') is-invalid @enderror" id="description"
                name="description"
                rows="3">{{ old('description', isset($book) ? $book['description'] : ($initial['description'] ?? null)) }}</textarea>
            @error('description')
                <span class="invalid-feedback d-block">{{ $message }}</span>
            @enderror
        </div>
        <div class="form-group">
            <label for="book_files">Directory Path:</label>
            <input type="text" class="form-control @error('directory_path') is-invalid @enderror" id="directory_path"
                name="directory_path" value="{{ old('directory_path', $dirPath) }}">
            @error('directory_path')
                <span class="invalid-feedback d-block">{{ $message }}</span>
            @enderror
        </div>
        <button type="submit" class="btn btn-primary"
            id="modal-{{ isset($book) ? 'update' : 'create' }}-btn">{{ isset($book) ? 'Update' : 'Create' }}</button>
        @if(!empty($isModal))
            <button type="button" class="btn btn-secondary" id="modal-cancel-btn">Cancel</button>
        @else
            <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Cancel</a>
        @endif
    </form>
</div>
<script>
    // Function to update the + button position to always be on the last row
    function updateAddRowButtons(groupSelector, rowSelector, buttonClass) {
        const group = document.querySelector(groupSelector);
        const rows = group.querySelectorAll(rowSelector);

        // Hide all + buttons first
        group.querySelectorAll(buttonClass).forEach(btn => {
            btn.style.display = 'none';
        });

        // Show only on the last row if there are rows
        if (rows.length > 0) {
            const lastRow = rows[rows.length - 1];
            const addButton = lastRow.querySelector(buttonClass);
            if (addButton) {
                addButton.style.display = 'flex';
            }
        }
    }

    // Dynamic add/remove for authors, genres, series
    document.addEventListener('DOMContentLoaded', function () {
        // Initialize + buttons on page load
        updateAddRowButtons('#authors-group', '.author-row', '.add-author-row');
        updateAddRowButtons('#series-group', '.series-row', '.add-series-row');
        updateAddRowButtons('#genres-group', '.genre-row', '.add-genre-row');

        // Authors
        document.getElementById('authors-group').addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-author')) {
                const row = e.target.closest('.author-row');
                row.remove();
                updateAddRowButtons('#authors-group', '.author-row', '.add-author-row');
            } else if (e.target.classList.contains('add-author-row')) {
                let group = document.getElementById('authors-group');
                let div = document.createElement('div');
                div.className = 'input-group author-row align-items-start mb-3';
                div.innerHTML = `
                                <input type="text" name="author[]" class="form-control w-auto author-autocomplete" style="max-width:300px; height:32px;" required>
                                <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-author p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                                    <button type="button" class="btn btn-primary btn-sm add-author-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                                </div>`;
                group.appendChild(div);
                enableAuthorAutocomplete(div.querySelector('.author-autocomplete'));
                updateAddRowButtons('#authors-group', '.author-row', '.add-author-row');
            }
        });

        function enableAuthorAutocomplete(input) {
            if (!window.jQuery || !window.jQuery.ui) return;
            $(input).autocomplete({
                source: function (request, response) {
                    $.ajax({
                        url: '/api/browse',
                        data: { type: 'author', search: request.term },
                        success: function (data) {
                            response((data.data || []).map(function (item) { return item.name; }));
                        }
                    });
                },
                minLength: 2
            });
        }
        // Enable on existing author rows
        $(function () { $('.author-autocomplete').each(function () { enableAuthorAutocomplete(this); }); });

        // Function to handle series data updates
        function handleSeriesUpdate() {
            // This function can be used to handle any updates to series data
        }

        // Add input event listeners to existing series inputs
        const seriesInputs = document.querySelectorAll('input[name="series[]"], input[name="series_number[]"]');
        seriesInputs.forEach(input => {
            input.addEventListener('input', handleSeriesUpdate);
        });

        // Set up mutation observer to track changes to series number inputs
        const observeSeriesNumberChanges = () => {
            const seriesNumberInputs = document.querySelectorAll('input[name="series_number[]"]');
            seriesNumberInputs.forEach(input => {
                if (input.hasAttribute('data-observed')) return;
                input.setAttribute('data-observed', 'true');

                const observer = new MutationObserver((mutations) => {
                    mutations.forEach(mutation => {
                        if (mutation.type === 'attributes' && mutation.attributeName === 'value') {
                            // Handle value changes if needed
                        }
                    });
                });

                observer.observe(input, {
                    attributes: true,
                    attributeOldValue: true,
                    attributeFilter: ['value']
                });

                input.addEventListener('input', handleSeriesUpdate);
            });
        };

        // Run initially and after adding new rows
        observeSeriesNumberChanges();

        // Show files link click handler
        document.getElementById('show-files-link').addEventListener('click', function(e) {
            e.preventDefault();
            const filesList = document.getElementById('directory-files-list');

            // Toggle display of files list
            if (filesList.style.display === 'none') {
                loadDirectoryFiles(false);
            } else {
                filesList.style.display = 'none';
            }
        });

        // Series
        document.getElementById('series-group').addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-series')) {
                const row = e.target.closest('.series-row');
                row.remove();
                updateAddRowButtons('#series-group', '.series-row', '.add-series-row');
            } else if (e.target.classList.contains('add-series-row')) {
                let group = document.getElementById('series-group');
                let div = document.createElement('div');
                div.className = 'input-group series-row align-items-start mb-3';
                div.innerHTML = `
                                <input type="text" name="series[]" class="form-control w-auto series-autocomplete" style="max-width:200px; height:32px;" placeholder="Series Name">
                                <input type="number" name="series_number[]" class="form-control w-auto" style="max-width:100px; height:32px;" placeholder="Number" min="1" step="any">
                                <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-series p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                                    <button type="button" class="btn btn-primary btn-sm add-series-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                                </div>`;
                group.appendChild(div);

                // Add input event listeners to the new series inputs
                const newSeriesInput = div.querySelector('input[name="series[]"]');
                const newSeriesNumberInput = div.querySelector('input[name="series_number[]"]');

                if (newSeriesInput && newSeriesNumberInput) {
                    newSeriesInput.addEventListener('input', handleSeriesUpdate);
                    newSeriesNumberInput.addEventListener('input', handleSeriesUpdate);
                }

                enableSeriesAutocomplete(div.querySelector('.series-autocomplete'));
                updateAddRowButtons('#series-group', '.series-row', '.add-series-row');
            }
        });

        function enableSeriesAutocomplete(input) {
            if (!window.jQuery || !window.jQuery.ui) return;
            $(input).autocomplete({
                source: function (request, response) {
                    // Get all author values
                    let authors = [];
                    document.querySelectorAll('input[name="author[]"]').forEach(function (a) {
                        if (a.value.trim()) authors.push(a.value.trim());
                    });
                    $.ajax({
                        url: '/api/browse',
                        data: { type: 'series', search: request.term, authors: authors },
                        success: function (data) {
                            response((data.data || []).map(function (item) { return item.name; }));
                        }
                    });
                },
                minLength: 2
            });
        }
        // Enable on existing series rows
        $(function () { $('.series-autocomplete').each(function () { enableSeriesAutocomplete(this); }); });
        // Genres
        document.getElementById('genres-group').addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-genre')) {
                const row = e.target.closest('.genre-row');
                row.remove();
                updateAddRowButtons('#genres-group', '.genre-row', '.add-genre-row');
            } else if (e.target.classList.contains('add-genre-row')) {
                let group = document.getElementById('genres-group');
                let div = document.createElement('div');
                div.className = 'input-group genre-row align-items-start mb-3';
                div.innerHTML = `
                                <select name="genre[]" class="form-select w-auto" style="max-width:200px; height:32px;"></select>
                                <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-genre p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                                    <button type="button" class="btn btn-primary btn-sm add-genre-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                                </div>`;
                group.appendChild(div);
                populateGenreDropdown(div.querySelector('select[name="genre[]"]'));
                updateAddRowButtons('#genres-group', '.genre-row', '.add-genre-row');
            }
        });

        // Function to populate genre dropdown with options
        function populateGenreDropdown(select) {
            // Use the genres from the config that are already in the template
            const genres = @json(config('genres.list', []));

            // Clear existing options except the first one (Select a genre)
            while (select.options.length > 1) {
                select.remove(1);
            }

            // Add genre options
            genres.forEach(function (genre) {
                const option = document.createElement('option');
                option.value = genre;
                option.textContent = genre;
                select.appendChild(option);
            });
        }

        // Initialize all genre selects on page load
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('select[name="genre[]"]').forEach(function (select) {
                // Make sure the select has options
                if (select.options.length <= 1) {
                    populateGenreDropdown(select);
                }
            });
        });
    });

    (function () {
        window.googleBooksMoreMatches = false;
        window.googleBooksMatchLimit = 10;

        function initTomSelect(selector) {
            let el = document.querySelector(selector);
            if (!el) return;
            let ajaxUrl = el.getAttribute('data-url');
            let selected = el.getAttribute('data-selected');
            let selectedName = el.getAttribute('data-selected-name');
            new TomSelect(selector, {
                create: true,
                persist: false,
                valueField: 'id',
                labelField: 'name',
                searchField: 'name',
                maxOptions: 20,
                loadThrottle: 300,
                load: function (query, callback) {
                    let url = ajaxUrl + '?q=' + encodeURIComponent(query || '');
                    fetch(url)
                        .then(response => response.json())
                        .then(json => {
                            callback(json.data || []);
                        })
                        .catch(() => {
                            callback();
                        });
                },
                onFocus: function () {
                    this.refreshOptions(false);
                },
                onInitialize: function () {
                    if (selected && selectedName) {
                        this.addOption({ id: selected, name: selectedName });
                        this.setValue(selected, true);
                    }
                }
            });
        }
        initTomSelect('#author-select');
        initTomSelect('#series-select');

        // Function to add a new author row
        function addAuthorRow(authorName = '') {
            const group = document.getElementById('authors-group');
            const div = document.createElement('div');
            div.className = 'input-group author-row align-items-start mb-3';
            div.innerHTML = `
                <input type="text" name="author[]" class="form-control w-auto" value="${authorName}" style="max-width:200px; height:32px;">
                <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-author p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                    <button type="button" class="btn btn-primary btn-sm add-author-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                </div>`;
            group.appendChild(div);

            // Add event listeners for the new row
            const removeBtn = div.querySelector('.remove-author');
            const addBtn = div.querySelector('.add-author-row');

            removeBtn.addEventListener('click', function() {
                div.remove();
                updateAddRowButtons('#authors-group', '.author-row', '.add-author-row');
            });

            addBtn.addEventListener('click', function() {
                addAuthorRow();
                updateAddRowButtons('#authors-group', '.author-row', '.add-author-row');
            });
        }

        // Autofill handler - ensure it is always bound after TomSelect
        function bindAutofillBtn() {
            $('#autofill-btn').off('click').on('click', function () {
                const title = $('#title').val().trim();
                // Get all author inputs and collect non-empty values
                const authorInputs = $('input[name="author[]"]').filter(function() {
                    return $(this).val().trim() !== '';
                });

                if (!title) {
                    $('#title').addClass('is-invalid');
                    if (!$('#title').next('.invalid-feedback').length) {
                        $('#title').after('<span class="invalid-feedback d-block">Title is required.</span>');
                    }
                    return;
                }

                if (authorInputs.length === 0) {
                    $('#title').addClass('is-invalid');
                    if (!$('#title').next('.invalid-feedback').length) {
                        $('#title').after('<span class="invalid-feedback d-block">At least one author is required.</span>');
                    }
                    return;
                }

                // Get the first author's name for the Google Books search
                const authorName = $(authorInputs[0]).val().trim();
                const series = $('#series-select option:selected').text() || '';
                const seriesNumber = $('#series_number').val() || '';
                $('#autofill-btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Searching...');
                fetch(`{{ route('admin.books.googleBooks') }}?title=${encodeURIComponent(title)}&author=${encodeURIComponent(authorName)}&series=${encodeURIComponent(series)}&series_number=${encodeURIComponent(seriesNumber)}&limit=${window.googleBooksMatchLimit}${window.googleBooksMoreMatches ? '&more=1' : ''}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.match_type === 'close') {
                            if (data.published_year) {
                                $('#published_year').val(data.published_year);
                            }
                            if (data.description) {
                                $('#description').val(data.description);
                            }
                            if (data.cover_image_url) {
                                const proxiedUrl = googleBooksProxyUrl(data.cover_image_url);
                                $('#cover-preview-img').attr('src', proxiedUrl);
                                $('#cover-preview-group').show();
                            }
                            let hidden = $('#cover_image_url');
                            if (!hidden.length) {
                                $('<input>').attr({ type: 'hidden', id: 'cover_image_url', name: 'cover_image_url' }).appendTo('#book-{{ isset($book) ? 'edit' : 'form' }}');
                                hidden = $('#cover_image_url');
                            }
                            hidden.val(data.cover_image_url || '');
                            $('#google-books-matches-table-wrapper').hide();
                            // Change button to get more matches
                            $('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Get More Matches');
                            window.googleBooksMoreMatches = true;
                            window.googleBooksMatchLimit = 10;
                        } else if (data.match_type === 'list' && data.matches && data.matches.length > 0) {
                            // Show table of matches
                            const $tbody = $('#google-books-matches-table tbody');
                            $tbody.empty();
                            data.matches.forEach((match, idx) => {
                                const row = `<tr>
                                            <td><input type="radio" name="google_books_match_select" value="${idx}"></td>
                                            <td>${match.title}</td>
                                            <td>${match.authors}</td>
                                            <td>${match.published_year}</td>
                                            <td>${match.cover_image_url ? `<img src='${googleBooksProxyUrl(match.cover_image_url)}' style='max-height:60px;'>` : ''}</td>
                                        </tr>`;
                                $tbody.append(row);
                            });
                            $('#google-books-matches-table-wrapper').show();
                            $('input[name="google_books_match_select"]').off('change').on('change', function () {
                                const idx = $(this).val();
                                const match = data.matches[idx];
                                if (match.published_year) {
                                    $('#published_year').val(match.published_year);
                                }
                                if (match.description) {
                                    $('#description').val(match.description);
                                }

                                // Handle authors - split by common delimiters and add to authors list
                                if (match.authors) {
                                    // Clear existing authors except the first one
                                    $('.author-row:not(:first)').remove();

                                    // Split authors by common delimiters (comma, 'and', '&')
                                    const authorDelimiters = /,|\s+and\s+|\s*&\s*/i;
                                    const authors = match.authors.split(authorDelimiters)
                                        .map(author => author.trim())
                                        .filter(author => author.length > 0);

                                    // Update first author
                                    const firstAuthorInput = $('input[name="author[]"]').first();
                                    firstAuthorInput.val(authors[0] || '');
                                    firstAuthorInput.trigger('input'); // Trigger any bound events

                                    // Add additional authors if they exist
                                    for (let i = 1; i < authors.length; i++) {
                                        addAuthorRow(authors[i]);
                                    }
                                }
                                if (match.cover_image_url) {
                                    const proxiedUrl = googleBooksProxyUrl(match.cover_image_url);
                                    $('#google-books-candidate').remove();
                                    var candidateHtml = '<div class="text-center" id="google-books-candidate">' +
                                        '<label>' +
                                        '<input type="radio" name="cover_image_candidate" value="' + match.cover_image_url + '">' +
                                        '<br>' +
                                        '<img src="' + proxiedUrl + '" alt="Google Books Cover" style="max-width:100px;max-height:140px;border:1px solid #ccc;margin-top:4px;">' +
                                        '</label>' +
                                        '<div style="font-size:12px;word-break:break-all;">Google Books</div>' +
                                        '</div>';

                                    if ($('#cover-candidates-list').length) {
                                        $('#cover-candidates-list').append(candidateHtml);
                                    } else {
                                        $('#cover-preview-img').attr('src', proxiedUrl);
                                        $('#cover-preview-group').show();
                                    }
                                }
                                let hidden = $('#cover_image_url');
                                if (!hidden.length) {
                                    $('<input>').attr({ type: 'hidden', id: 'cover_image_url', name: 'cover_image_url' }).appendTo('#book-{{ isset($book) ? 'edit' : 'form' }}');
                                    hidden = $('#cover_image_url');
                                }
                                hidden.val(match.cover_image_url || '');
                            });
                            // Handle button for more matches
                            if (data.maxed || window.googleBooksMatchLimit >= 40) {
                                $('#autofill-btn').prop('disabled', true).html('<i class="fas fa-check"></i> All Results Shown');
                            } else {
                                $('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Get More Matches');
                                window.googleBooksMoreMatches = true;
                                window.googleBooksMatchLimit += 10;
                            }
                        } else {
                            $('#google-books-matches-table-wrapper').hide();
                            $('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Autofill from Google Books');
                            window.googleBooksMoreMatches = false;
                            window.googleBooksMatchLimit = 10;
                        }
                    })
                    .catch(() => {
                        $('#autofill-btn').prop('disabled', false).html('<i class="fas fa-search"></i> Autofill from Google Books');
                        $('#title').addClass('is-invalid');
                        $('#title').after('<span class="invalid-feedback d-block">Failed to fetch book info.</span>');
                    });
            });
        }
        // Bind after TomSelect initialized
        bindAutofillBtn();
        // If TomSelect is re-initialized dynamically, you must re-bind this as well.

        $(document).off('submit.bookModalAjax').on('submit.bookModalAjax', 'form[id^="book-"]', function (e) {
            var $form = $(this);
            var $modal = $form.closest('.modal');
            if ($modal.length) {
                e.preventDefault();
                var url = $form.attr('action');
                var method = 'POST';
                var formData = new FormData(this);
                formData.set('_method', 'POST');
                var csrfToken = $form.find('input[name="_token"]').val();
                if (csrfToken) formData.append('_token', csrfToken);
                $.ajax({
                    url: url,
                    type: method,
                    data: formData,
                    processData: false,
                    contentType: false,
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    success: function (data) {
                        var modalEl = $modal[0];
                        var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                        if (bsModal) bsModal.hide();
                        $(document).trigger('book:updated', data);
                    },
                    error: function (xhr) {
                        let msg = 'Failed to save book.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            msg = xhr.responseJSON.message;
                        }
                        $form.find('#title').addClass('is-invalid');
                        $form.find('#title').after('<span class="invalid-feedback d-block">' + msg + '</span>');
                    }
                });
                return false;
            }
        });

        if (typeof window.bootstrap !== 'undefined' && ($('#modal-cancel-btn').length)) {
            $('#modal-cancel-btn').on('click', function () {
                var modalEl = $(this).closest('.modal')[0];
                var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                if (bsModal) bsModal.hide();
            });
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.querySelector('#book-{{ isset($book) ? 'edit' : 'form' }}');
            if (!form) return;

            form.addEventListener('submit', function (e) {
                // Clear previous validation
                const invalidFields = form.querySelectorAll('.is-invalid');
                invalidFields.forEach(field => field.classList.remove('is-invalid'));
                const errorMessages = form.querySelectorAll('.invalid-feedback');
                errorMessages.forEach(msg => msg.remove());

                // Ensure directory_path doesn't have a leading slash
                const dirPathInput = form.querySelector('input[name="directory_path"]');
                if (dirPathInput && dirPathInput.value) {
                    dirPathInput.value = dirPathInput.value.replace(/^\/+/, '');
                }

                let hasError = false;

                // Validate title
                const titleInput = form.querySelector('input[name="title"]');
                if (!titleInput || !titleInput.value.trim()) {
                    titleInput.classList.add('is-invalid');
                    const error = document.createElement('div');
                    error.className = 'invalid-feedback d-block';
                    error.textContent = 'Title is required.';
                    titleInput.parentNode.insertBefore(error, titleInput.nextSibling);
                    hasError = true;
                }

                // Validate at least one author
                const authorInputs = form.querySelectorAll('input[name="author[]"]');
                let hasAuthor = false;
                authorInputs.forEach(input => {
                    if (input.value.trim()) hasAuthor = true;
                });

                if (!hasAuthor) {
                    const authorGroup = document.getElementById('authors-group');
                    if (authorGroup) {
                        const error = document.createElement('div');
                        error.className = 'invalid-feedback d-block';
                        error.textContent = 'At least one author is required.';
                        authorGroup.parentNode.insertBefore(error, authorGroup.nextSibling);
                        hasError = true;
                    }
                }

                // Validate at least one genre
                const genreSelects = form.querySelectorAll('select[name="genre[]"]');
                let hasGenre = false;
                genreSelects.forEach(select => {
                    if (select.value) hasGenre = true;
                });

                if (!hasGenre) {
                    const genreGroup = document.getElementById('genres-group');
                    if (genreGroup) {
                        const error = document.createElement('div');
                        error.className = 'invalid-feedback d-block';
                        error.textContent = 'At least one genre is required.';
                        genreGroup.parentNode.insertBefore(error, genreGroup.nextSibling);
                        hasError = true;
                    }
                }

                if (hasError) {
                    e.preventDefault();
                    const firstError = form.querySelector('.is-invalid');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    return false;
                }
            });
        });

        $(document).on('change', 'input[name="google_books_match_select"]', function () {
            const idx = $(this).val();
            let matches = window.googleBooksMatches || [];
            if (!matches.length && window.lastGoogleBooksAjaxData && window.lastGoogleBooksAjaxData.matches) {
                matches = window.lastGoogleBooksAjaxData.matches;
            }
            const match = matches[idx];
            if (match) {
                if (match.title) {
                    $('#title').val(match.title);
                }
                if (match.authors) {
                    let authorSelect = $('#author-select')[0].tomselect;
                    if (authorSelect) {
                        let authorName = match.authors.split(',')[0].trim();
                        authorSelect.addOption({ id: authorName, name: authorName });
                        authorSelect.setValue(authorName, true);
                    } else {
                        $('#author-select').val(match.authors.split(',')[0].trim());
                    }
                }
            }
        });

        function setGoogleBooksMatches(matches) {
            window.googleBooksMatches = matches;
        }

        function loadDirectoryFiles() {
            const dirPath = $("#directory_path").val() || $("input[name='directory_path']").val() || $("input[name='original_directory_path']").val();
            const filesList = $('#directory-files-list');
            const linkText = $('#show-files-link div');

            console.log('Loading files for directory:', dirPath); // Debug log

            if (!dirPath) {
                console.log('No directory path found'); // Debug log
                filesList.html('<div class="p-3 text-danger">Please select a directory first.</div>').show();
                return;
            }

            // Update link text to show loading state
            const originalText = linkText.html();
            linkText.html('<i class="fas fa-spinner fa-spin me-2"></i>Loading files...');

            filesList.html('<div class="text-center p-3"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> Loading files...</div>').show();

            // Show loading state immediately
            filesList.show();

            // Use the correct endpoint from routes
            const url = '{{ route('admin.books.filesAjax') }}';
            const params = { directory: dirPath };

            console.log('Making request to:', url, 'with params:', params); // Debug log

            $.ajax({
                url: url,
                method: 'GET',
                data: params,
                dataType: 'json',
                success: function(response) {
                    console.log('Raw response:', response); // Debug log
                    // Reset link text
                    linkText.html(originalText);

                    let html = '';

                    // Check if response is a string that needs parsing
                    let files = [];
                    if (typeof response === 'string') {
                        try {
                            response = JSON.parse(response);

                        } catch (e) {
                            console.error('Error parsing response:', e);
                        }
                    }

                    // Handle different response structures
                    if (response && response.files && Array.isArray(response.files)) {
                        files = response.files; // Object with files array
                    } else if (response && Array.isArray(response)) {
                        files = response; // Direct array of files
                    } else if (response && response.data && Array.isArray(response.data)) {
                        files = response.data; // Object with data array
                    }

                    if (files && files.length > 0) {
                        html = '<div class="list-group list-group-flush">';
                        files.forEach(function(file) {
                            if (!file) return;

                            const filename = typeof file === 'string' ? file : (file.name || file.filename || '');
                            if (!filename) return;

                            const isImage = /(\.(jpg|jpeg|png|gif|webp))$/i.test(filename);
                            const isAudio = /(\.(mp3|m4b|m4a|ogg|wav|flac))$/i.test(filename);
                            let icon = '📄';

                            if (isImage) icon = '🖼️';
                            else if (isAudio) icon = '🔊';

                            html += `
                                <div class="list-group-item p-2">
                                    <div class="d-flex align-items-center">
                                        <span class="me-2">${icon}</span>
                                        <span class="text-truncate">${filename}</span>
                                    </div>
                                </div>`;
                        });
                        html += '</div>';
                    } else {
                        html = '<div class="p-3 text-muted text-center">No files found in this directory.</div>';
                    }
                    filesList.html(html).show();
                    filesList.css('display', 'block'); // Force display block to ensure visibility
                },
                error: function(xhr, status, error) {
                    console.error('Error loading directory files:', status, error);
                    console.error('Response text:', xhr.responseText);
                    linkText.html(originalText);
                    filesList.html('<div class="p-3 text-danger">Error loading files. Please check the console for details.</div>');
                }
            });
        }

        // Toggle files list on link click
        $('#show-files-link').on('click', function(e) {
            e.preventDefault();
            const filesList = $('#directory-files-list');

            if (filesList.is(':visible') && filesList.css('display') !== 'none') {
                filesList.slideUp(120);
            } else {
                loadDirectoryFiles();
                filesList.slideDown(120);
            }
        });

        // Reload files if directory changes while list is visible
        $('input[name="directory_path"], input[name="original_directory_path"]').on('change', function() {
            if ($('#directory-files-list').is(':visible')) {
                loadDirectoryFiles();
            }
        });

        // Helper for proxying Google Books cover images
        function googleBooksProxyUrl(url) {
            if (url && url.match(/^https?:\/\/books\.google\.com\//)) {
                return '/google-books-cover/' + btoa(url);
            }
            return url;
        }
    })();
</script>
@endsection
