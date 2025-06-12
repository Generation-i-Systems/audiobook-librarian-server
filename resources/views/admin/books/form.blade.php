@extends(isset($layout) ? $layout : 'layouts.app')

@section('content')
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

    <form action="{{ isset($book) ? route('admin.books.update', ['book' => $book['id']]) : route('admin.books.store') }}"
        method="POST" enctype="multipart/form-data" id="book-form" class="mt-3">
        @csrf
        @if(isset($book))
            @method('PUT')
        @endif
        @if(isset($book) && !empty($book['directoryPath']))
            <input type="hidden" name="originalDirectoryPath" value="{{ $book['directoryPath'] }}">
        @elseif(old('directoryPath'))
            <input type="hidden" name="originalDirectoryPath" value="{{ old('directoryPath') }}">
        @elseif(isset($initial['directoryPath']) && !empty($initial['directoryPath']))
            <input type="hidden" name="originalDirectoryPath" value="{{ $initial['directoryPath'] }}">
        @endif
        <!-- DEBUG: This input is required for JS to load directory files -->
        <input type="hidden" id="directoryPath" name="directoryPath" value="{{ old('directoryPath', isset($book) && !empty($book['directoryPath']) ? $book['directoryPath'] : ($initial['directoryPath'] ?? '') ) }}">
        <button type="button" class="btn btn-info mb-3" id="autofill-modal-btn"><i class="fas fa-magic"></i> Autofill Book Metadata</button>

<!-- Autofill Modal -->
<div class="modal fade" id="autofillModal" tabindex="-1" aria-labelledby="autofillModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="autofillModalLabel">Autofill Book Metadata</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="autofill-flash-message" class="alert alert-danger d-none" role="alert"></div>
        <form id="autofill-search-form" class="mb-3">
          <div class="row g-2">
            <div class="col-md-5">
              <input type="text" class="form-control" name="title" placeholder="Title" required>
            </div>
            <div class="col-md-4">
              <input type="text" class="form-control" name="author" placeholder="Author">
            </div>
            <div class="col-md-3">
              <input type="text" class="form-control" name="series" placeholder="Series (optional)">
            </div>
          </div>
          <div class="mt-3 text-end">
            <button type="submit" class="btn btn-primary">Search</button>
          </div>
        <div id="autofill-results-section" style="display:none;">
          <label>Matches:</label>
          <table class="table table-bordered table-sm" id="autofill-results-table">
            <thead>
              <tr>
                <th></th>
                <th>Title</th>
                <th>Authors</th>
                <th>Year</th>
                <th>Source</th>
                <th>Cover</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>
        <div class="mb-3">
            <label for="title">Title:</label>
            <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title"
                value="{{ old('title', isset($book) && !empty($book['title']) ? $book['title'] : ($initial['title'] ?? null)) }}" required>
            @error('title')
                <span class="invalid-feedback d-block">{{ $message }}</span>
            @enderror
        </div>
        <div class="mb-3">
            <label class="form-label">Authors</label>
            <div id="authors-group">
                @php
                    $authors = old('author', isset($book) && !empty($book['author']) ? (is_array($book['author']) ? $book['author'] : [$book['author']]) : ($initial['author'] ?? []));
                    if (!is_array($authors))
                        $authors = [$authors];
                @endphp
                @php $authorsCount = count($authors); @endphp
                @foreach($authors as $idx => $author)
                    <div class="input-group author-row align-items-start mb-3">
                        <input type="text" name="author[]" class="form-control w-auto author-autocomplete" style="max-width:300px; height:32px;"
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
                    $oldSeriesNumbers = old('seriesNumber', old('seriesNumber', []));

                    // Log the raw series data for debugging
                    Log::debug('Series Data:', [
                        'book_series' => isset($book) && !empty($book['series']) ? $book['series'] : null,
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
                                'number' => $book['seriesNumber'] ?? $book['seriesNumber'] ?? ''
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
                                'number' => $initial['seriesNumber'] ?? $initial['seriesNumber'] ?? ''
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
                        $name = isset($series['name']) ? $series['name'] : '';
                        $number = isset($series['number']) ? $series['number'] : '';
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
                        <input type="text" name="series[]" class="form-control w-auto series-autocomplete" style="max-width:200px; height:32px;"
                            placeholder="Series Name" value="{{ $name }}">
                        <input type="number" name="seriesNumber[]" class="form-control w-auto"
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
                    $genres = old('genre', old('genre', isset($book) && !empty($book['genre']) ? (is_array($book['genre']) ? $book['genre'] : [$book['genre']]) : ($initial['genre'] ?? [])));
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
            <label for="publishedYear">Published Year (Optional):</label>
            <input type="number" class="form-control @error('publishedYear') is-invalid @enderror" id="publishedYear"
                name="publishedYear" min="1000" max="9999"
                value="{{ old('publishedYear', old('publishedYear', isset($book) && !empty($book['publishedYear']) ? $book['publishedYear'] : (isset($book) && !empty($book['published_year']) ? $book['published_year'] : ($initial['publishedYear'] ?? $initial['published_year'] ?? null)))) }}">
            @error('publishedYear')
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
            $directoryPath = isset($book) && !empty($book['directoryPath']) ? $book['directoryPath'] : ($directoryPath ?? ($initial['directoryPath'] ?? null));
            $coverImg = isset($book) && !empty($book['coverImage']) ? $book['coverImage'] : ($initial['coverImage'] ?? null);
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
                    'src' => route('cover.proxy', ['path' => $directoryPath . '/' . $currentCoverFilename]),
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
                    'src' => route('cover.proxy', ['path' => $directoryPath . '/' . $coverAuto]),
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
                            'src' => route('cover.proxy', ['path' => $directoryPath . '/' . $candidate]),
                            'label' => 'Candidate',
                            'display_name' => $candidate,
                        ];
                        $addedCovers[] = $candidate;
                    }
                }
            }
        @endphp
        </form>
        @if (!empty($coverOptions))
        <div class="mb-3" id="cover-candidates-group">
            <label class="form-label">Select Cover Image:</label>
            <div class="d-flex flex-wrap gap-3" id="cover-candidates-list">
                @foreach($coverOptions as $option)
                <div class="text-center">
                    <label class="d-flex flex-column align-items-center">
                        <input type="radio" name="coverImageCandidate" value="{{ $option['value'] }}"
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
            <label for="coverImage">Cover Image (Optional):</label>
            <input type="file" class="form-control-file @error('coverImage') is-invalid @enderror" id="coverImage"
                name="coverImage">
            @error('coverImage')
                <span class="invalid-feedback d-block">{{ $message }}</span>
            @enderror
        </div>
        <div class="form-group">
            <label for="description">Description (Optional):</label>
            <textarea class="form-control @error('description') is-invalid @enderror" id="description"
                name="description"
                rows="3">{{ old('description', isset($book) && !empty($book['description']) ? $book['description'] : ($initial['description'] ?? null)) }}</textarea>
            @error('description')
                <span class="invalid-feedback d-block">{{ $message }}</span>
            @enderror
        </div>
        <div class="form-group d-flex align-items-center">
            <label for="directoryPath" class="me-2">Directory Path:</label>
            <input type="text" class="form-control @error('directoryPath') is-invalid @enderror me-2" id="directoryPath"
                name="directoryPath" value="{{ old('directoryPath', $directoryPath ?? ($initial['directoryPath'] ?? '')) }}" style="max-width: 400px;">
            <button type="button" class="btn btn-outline-secondary" id="resync-path-btn" title="Resync title, author, and series from path">
                <i class="fas fa-sync-alt"></i> Resync Title/Author/Series
            </button>
            @error('directoryPath')
                <span class="invalid-feedback d-block ms-2">{{ $message }}</span>
            @enderror
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const resyncBtn = document.getElementById('resync-path-btn');
            if (resyncBtn) {
                resyncBtn.addEventListener('click', function() {
                    const path = document.getElementById('directoryPath').value;
                    if (!path) {
                        alert('Please enter a directory path first.');
                        return;
                    }
                    resyncBtn.disabled = true;
                    resyncBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Resyncing...';
                    fetch('/admin/books/resync-from-path', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('input[name=_token]').value
                        },
                        body: JSON.stringify({ directoryPath: path })
                    })
                    .then(resp => resp.json())
                    .then(data => {
                        if (data.success) {
                            if (data.title) document.getElementById('title').value = data.title;
                            if (data.authors && Array.isArray(data.authors)) {
                                const authorsGroup = document.getElementById('authors-group');
                                if (authorsGroup) {
                                    authorsGroup.innerHTML = '';
                                    data.authors.forEach((author, idx) => {
                                        const div = document.createElement('div');
                                        div.className = 'input-group author-row align-items-start mb-3';
                                        div.innerHTML = `<input type="text" name="author[]" class="form-control w-auto author-autocomplete" style="max-width:300px; height:32px;" value="${author}" required>`;
                                        authorsGroup.appendChild(div);
                                    });
                                }
                            }
                            if (data.series && Array.isArray(data.series)) {
                                const seriesGroup = document.getElementById('series-group');
                                if (seriesGroup) {
                                    seriesGroup.innerHTML = '';
                                    data.series.forEach((item, idx) => {
                                        const div = document.createElement('div');
                                        div.className = 'input-group series-row align-items-start mb-3';
                                        div.innerHTML = `<input type="text" name="series[]" class="form-control w-auto series-autocomplete" style="max-width:200px; height:32px;" placeholder="Series Name" value="${item.name}">
                                            <input type="number" name="seriesNumber[]" class="form-control w-auto" style="max-width:100px; height:32px;" placeholder="Number" value="${item.number}" min="1" step="any">`;
                                        seriesGroup.appendChild(div);
                                    });
                                }
                            }
                        } else {
                            alert(data.message || 'Failed to reparse directory.');
                        }
                    })
                    .catch(() => alert('Error contacting server.'))
                    .finally(() => {
                        resyncBtn.disabled = false;
                        resyncBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Resync Title/Author/Series';
                    });
                });
            }
        });
        </script>
        <div id="directory-files-list" class="mt-2 mb-3" style="display:none; border: 1px solid #ddd; padding: 10px; border-radius: 4px;">
            {{-- Files will be listed here by JavaScript --}}
        </div>
        <button type="submit" class="btn btn-primary"
            id="modal-{{ isset($book) ? 'update' : 'create' }}-btn">{{ isset($book) ? 'Update' : 'Create' }}</button>
        @if(!empty($isModal))
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="modal-cancel-btn">Cancel</button>
        @else
            <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Cancel</a>
        @endif
    </form>
</div>

<script>
    window.BOOK_FORM_ROUTES = {
        index: "{{ route('admin.books.index') }}",
        googleBooks: "{{ route('admin.books.googleBooks') }}",
        filesAjax: "{{ route('admin.books.filesAjax') }}",
        authorsAutocomplete: "{{ route('admin.books.autocomplete.authors') }}",
        seriesAutocomplete: "{{ route('admin.books.autocomplete.series') }}",
    };
    window.APP_URL = "{{ config('app.url') }}";
    window.GENRE_OPTIONS = @json(config('genres.list', []));
</script>
<script>
// Debug: Confirm jQuery and jQuery UI are loaded before form.js
console.log('window.jQuery:', typeof window.jQuery, window.jQuery ? 'OK' : 'MISSING');
console.log('$.fn.autocomplete:', typeof $.fn.autocomplete, $.fn.autocomplete ? 'OK' : 'MISSING');

// Always call initBookForm on DOM ready for this form
$(function() {
    var formSelector = '#book-form';
    if (typeof window.initBookForm === 'function') {
        console.log('Calling initBookForm for selector', formSelector);
        window.initBookForm(formSelector);
    } else {
        console.error('initBookForm is not defined!');
    }
});
</script>
<script src="{{ asset('js/admin/books/form.js') }}"></script>
@endsection
