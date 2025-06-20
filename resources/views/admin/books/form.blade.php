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
        @php
            $displayPath = old('directoryPath') ?? request()->get('import_path') ?? ($book['directoryPath'] ?? null) ?? ($initial['directoryPath'] ?? '');
        @endphp
        <input type="hidden" id="directoryPath" name="directoryPath" value="{{ $displayPath }}">
        <button type="button" class="btn btn-info mb-3" id="autofill-modal-btn"><i class="fas fa-magic"></i> Autofill Book Metadata</button>
        @if(isset($book))
        <button type="button" class="btn btn-secondary mb-3 ms-2" id="raw-json-edit-btn"><i class="fas fa-code"></i> Raw JSON Edit</button>
        @endif

        <div class="mb-3">
            <label for="title">Title:</label>
            <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title"
                value="{{ old('title') ?? request()->get('title') ?? ($book['title'] ?? null) ?? ($initial['title'] ?? '') }}" required>
            @error('title')
                <span class="invalid-feedback d-block">{{ $message }}</span>
            @enderror
        </div>
        <div class="mb-3">
            <label class="form-label">Authors</label>
            <div id="authors-group">
                @php
                    $authors = old('author') ?? (request()->get('author') ? [request()->get('author')] : null) ?? ($book['author'] ?? null) ?? ($initial['author'] ?? []);
                    if (!is_array($authors))
                        $authors = [$authors];
                @endphp
                @php $authorsCount = count($authors); @endphp
                @foreach($authors as $idx => $author)
                    <div class="input-group author-row align-items-start mb-3">
                        @php
                            if ($author instanceof \MongoDB\Model\BSONArray) {
                                $author = (array) $author;
                            }
                            if (is_array($author)) {
                                $author = implode(', ', $author);
                            }
                        @endphp
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
            <label class="form-label">Narrators</label>
            <div id="narrators-group">
                @php
                    $narrators = old('narrator') ?? ($book['narrator'] ?? null) ?? ($initial['narrator'] ?? []);
                    if (!is_array($narrators))
                        $narrators = [$narrators];
                @endphp
                @php $narratorsCount = count($narrators); @endphp
                @foreach($narrators as $idx => $narrator)
                    <div class="input-group narrator-row align-items-start mb-3">
                        @php
                            if ($narrator instanceof \MongoDB\Model\BSONArray) {
                                $narrator = (array) $narrator;
                            }
                            if (is_array($narrator)) {
                                $narrator = implode(', ', $narrator);
                            }
                        @endphp
                        <input type="text" name="narrator[]" class="form-control w-auto narrator-autocomplete" style="max-width:300px; height:32px;"
                            value="{{ $narrator }}">
                        <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                            <button type="button" class="btn btn-outline-danger btn-sm remove-row p-0 mb-0"
                                style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                            @if($idx === $narratorsCount - 1)
                                <button type="button" class="btn btn-primary btn-sm add-narrator-row p-0 mt-1"
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
                    $oldSeries = old('series') ?? (request()->get('series') ? [request()->get('series')] : null);
                    $oldSeriesNumbers = old('seriesNumber') ?? old('seriesNumber', []);

                    // Handle existing book data
                    if (isset($book) && !empty($book['series'])) {
                        if (is_array($book['series'])) {
                            // Handle new format: ["Series Name" => number, ...]
                            foreach ($book['series'] as $name => $number) {
                                if (!empty($name)) {
                                    $seriesList[] = [
                                        'seriesName' => $name,
                                        'number' => $number,
                                    ];
                                }
                            }
                        }
                        // Handle single series as string (legacy format)
                        else if (is_string($book['series'])) {
                            $seriesList[] = [
                                'seriesName' => $book['series'],
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
                                    'seriesName' => $seriesName,
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
                                        'seriesName' => $name,
                                        'number' => $number
                                    ];
                                }
                            }
                        } else if (is_string($initial['series'])) {
                            $seriesList[] = [
                                'seriesName' => $initial['series'],
                                'number' => $initial['seriesNumber'] ?? $initial['seriesNumber'] ?? ''
                            ];
                        }
                    }

                    // Ensure we have at least one empty series row
                    if (empty($seriesList)) {
                        $seriesList[] = ['seriesName' => '', 'number' => ''];
                    }
                    $seriesCount = count($seriesList);
                @endphp
                @foreach($seriesList as $idx => $series)
                    @php
                        $name = isset($series['seriesName']) ? $series['seriesName'] : (isset($series['name']) ? $series['name'] : '');
                        $number = isset($series['number']) ? $series['number'] : '';
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
                    $genres = old('genre') ?? request()->get('genre') ?? ($book['genre'] ?? null) ?? ($initial['genre'] ?? []);
                    if (!is_array($genres)) {
                        $genres = [$genres];
                    }
                    $genresCount = count($genres);
                @endphp
                @foreach($genres as $idx => $genre)
                    <div class="input-group genre-row align-items-start mb-3">
                        <select name="genre[]" class="form-select w-auto" style="max-width:200px; height:32px;" required>
                            <option value="">Select a genre</option>
                            @foreach($genreList as $g)
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
// Function to initialize book form UI elements
function initBookFormUI() {
    // Restore add/remove buttons for authors
    // Handle add-author-row buttons
    document.querySelectorAll('.add-author-row').forEach(btn => {
        btn.onclick = function() {
            const group = document.getElementById('authors-group');
            const idx = group.children.length;
            const row = document.createElement('div');
            row.className = 'input-group author-row align-items-start mb-3';
            row.innerHTML = `<input type="text" name="author[]" class="form-control w-auto author-autocomplete" style="max-width:300px; height:32px;" required>
                <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-author p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                    <button type="button" class="btn btn-primary btn-sm add-author-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                </div>`;
            group.appendChild(row);
            initBookFormUI();
        };
    });
    // Handle remove-author buttons
    document.querySelectorAll('.remove-author').forEach(btn => {
        btn.onclick = function() {
            const row = btn.closest('.author-row');
            if (row) row.remove();
        };
    });
    // Series
    // Handle add-series-row buttons
    document.querySelectorAll('.add-series-row').forEach(btn => {
        btn.onclick = function() {
            const group = document.getElementById('series-group');
            const idx = group.children.length;
            const row = document.createElement('div');
            row.className = 'input-group series-row align-items-start mb-3';
            row.innerHTML = `<input type="text" name="series[${idx}][name]" class="form-control w-auto series-autocomplete" style="max-width:200px; height:32px;" placeholder="Series Name">
                <input type="text" name="series[${idx}][number]" class="form-control w-auto ms-2" style="max-width:100px; height:32px;" placeholder="Number">
                <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-series p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                    <button type="button" class="btn btn-primary btn-sm add-series-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                </div>`;
            group.appendChild(row);
            initBookFormUI();
        };
    });
    // Handle remove-series buttons
    document.querySelectorAll('.remove-series').forEach(btn => {
        btn.onclick = function() {
            const row = btn.closest('.series-row');
            if (row) row.remove();
        };
    });
    // Genres
    // Handle add-genre-row buttons
    document.querySelectorAll('.add-genre-row').forEach(btn => {
        btn.onclick = function() {
            const group = document.getElementById('genres-group');
            const row = document.createElement('div');
            row.className = 'input-group genre-row align-items-start mb-3';
            row.innerHTML = `<input type="text" name="genre[]" class="form-control w-auto genre-autocomplete" style="max-width:200px; height:32px;" required>
                <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-genre p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                    <button type="button" class="btn btn-primary btn-sm add-genre-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                </div>`;
            group.appendChild(row);
            initBookFormUI();
        };
    });
    // Handle remove-genre buttons
    document.querySelectorAll('.remove-genre').forEach(btn => {
        btn.onclick = function() {
            const row = btn.closest('.genre-row');
            if (row) row.remove();
        };
    });
    // Autocomplete (if using a plugin, re-initialize here)
    // Example: $('.author-autocomplete').autocomplete(...)
    // You may need to re-attach your autocomplete plugin here if used
}

// Initialize directory resync functionality
document.addEventListener('DOMContentLoaded', function() {
    const resyncBtn = document.getElementById('resync-directory-btn');
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
                .then(response => response.json())
                .then(data => {
                    resyncBtn.disabled = false;
                    resyncBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Resync Title/Author/Series';
                    if (data.success) {
                        if (data.title) {
                            document.getElementById('title').value = data.title;
                        }
                        if (data.authors) {
                            const authorsGroup = document.getElementById('authors-group');
                            authorsGroup.innerHTML = '';
                            data.authors.forEach(function(author, idx) {
                                const row = document.createElement('div');
                                row.className = 'input-group author-row align-items-start mb-3';
                                row.innerHTML = `<input type="text" name="author[]" class="form-control w-auto author-autocomplete" style="max-width:300px; height:32px;" value="${author}" required>
                                    <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-author p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                                        <button type="button" class="btn btn-primary btn-sm add-author-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                                    </div>`;
                                authorsGroup.appendChild(row);
                            });
                        }
                        if (data.series) {
                            const seriesGroup = document.getElementById('series-group');
                            seriesGroup.innerHTML = '';
                            data.series.forEach(function(series, idx) {
                                const row = document.createElement('div');
                                row.className = 'input-group series-row align-items-start mb-3';
                                row.innerHTML = `<input type="text" name="series[${idx}][name]" class="form-control w-auto series-autocomplete" style="max-width:200px; height:32px;" value="${series.name}" placeholder="Series Name">
                                    <input type="text" name="series[${idx}][number]" class="form-control w-auto ms-2" style="max-width:100px; height:32px;" value="${series.number}" placeholder="Number">
                                    <div class="d-flex flex-column flex-shrink-0 ms-2 align-items-center" style="min-width:40px;">
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-series p-0 mb-0" style="width:40px; height:32px; display:flex; align-items:center; justify-content:center;">&times;</button>
                                        <button type="button" class="btn btn-primary btn-sm add-series-row p-0 mt-1" style="width:40px; height:28px; display:flex; align-items:center; justify-content:center;">+</button>
                                    </div>`;
                                seriesGroup.appendChild(row);
                            });
                        }
                        // TODO: Handle genres if needed
                        if (typeof window.initBookForm === 'function') {
                            window.initBookForm('#book-form');
                        }
                    }
                })
                .catch(err => {
                    resyncBtn.disabled = false;
                    resyncBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Resync Title/Author/Series';
                    alert('Resync failed.');
                });
        });
    }
});
</script>
        <div id="directory-files-list" class="mt-2 mb-3" style="display:none; border: 1px solid #ddd; padding: 10px; border-radius: 4px;">
            {{-- Files will be listed here by JavaScript --}}
        </div>
        <button type="submit" class="btn btn-primary" id="modal-{{ isset($book) ? 'update' : 'create' }}-btn">{{ isset($book) ? 'Update' : 'Create' }}</button>
        @if(!empty($isModal))
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="modal-cancel-btn">Cancel</button>
        @else
            <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Cancel</a>
        @endif
    </form>
</div>

@push('scripts')
<script type="text/javascript">
    // Define global route objects for book form
    window.BOOK_FORM_ROUTES = {
        index: "{{ route('admin.books.index') }}",
        search: "{{ route('admin.books.search') }}",
        googleBooks: "{{ route('admin.books.googleBooks') }}",
        audible: "{{ route('admin.books.audible') }}",
        filesAjax: "{{ route('admin.books.filesAjax') }}",
        authorsAutocomplete: "{{ route('admin.books.autocomplete.authors') }}",
        seriesAutocomplete: "{{ route('admin.books.autocomplete.series') }}",
        narratorsAutocomplete: "{{ route('admin.books.autocomplete.narrators') }}"
    };

    // Set other global variables
    window.APP_URL = "{{ config('app.url') }}";
    window.GENRE_OPTIONS = @json(config('genres.list', []));
    window.AUDIBLE_SEARCH_URL = "{{ route('admin.books.audible') }}";

    // Debug: Confirm jQuery and jQuery UI are loaded
    console.log('window.jQuery:', typeof window.jQuery, window.jQuery ? 'OK' : 'MISSING');
    console.log('$.fn.autocomplete:', typeof $.fn.autocomplete, $.fn.autocomplete ? 'OK' : 'MISSING');
</script>

{{-- Include form.js script --}}
<script src="{{ asset('js/admin/books/form.js') }}"></script>
<script type="text/javascript">
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
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    var autofillModal = document.getElementById('autofillModal');
    if (autofillModal) {
        autofillModal.addEventListener('show.bs.modal', function () {
            // Get current form values
            var title = document.querySelector('input[name="title"]')?.value || '';
            var author = '';
            var authorField = document.querySelector('input[name="author[]"]');
            if (authorField) {
                author = authorField.value;
            }
            var series = '';
            var seriesField = document.querySelector('input[name="series[]"]');
            if (seriesField) {
                series = seriesField.value;
            }
            // Set modal fields
            document.getElementById('autofill-title').value = title;
            document.getElementById('autofill-author').value = author;
            document.getElementById('autofill-series').value = series;
        });
    }
});
</script>
@endpush
<!-- Raw JSON Edit Modal -->
<div class="modal fade" id="rawJsonModal" tabindex="-1" aria-labelledby="rawJsonModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="rawJsonModalLabel">Edit Book Raw JSON</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="raw-json-error" class="alert alert-danger d-none" role="alert" style="display:none;"></div>
        <textarea id="raw-json-textarea" class="form-control font-monospace" rows="18" style="font-size:0.98em;"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="save-raw-json-btn">Save JSON</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="autofillModal" tabindex="-1" aria-labelledby="autofillModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="autofillModalLabel">Autofill Book Metadata</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="autofill-search-form" class="mb-3">
          <div class="row g-2 mb-2 align-items-end">
            <div class="col-md-3">
              <label for="autofill-source" class="form-label">Source</label>
              <select class="form-select" id="autofill-source" name="source" required>
                <option value="google">Google Books</option>
                <option value="audible">Audible</option>
                <option value="audiobookbay">AudiobookBay</option>
                <option value="hardcover">Hardcover</option>
              </select>
            </div>
            <div class="col-md-3">
              <label for="autofill-title" class="form-label">Title</label>
              <input type="text" class="form-control" id="autofill-title" name="title" maxlength="120" autocomplete="off">
            </div>
            <div class="col-md-3">
              <label for="autofill-author" class="form-label">Author</label>
              <input type="text" class="form-control" id="autofill-author" name="author" maxlength="120" autocomplete="off">
            </div>
            <div class="col-md-3">
              <label for="autofill-series" class="form-label">Series</label>
              <input type="text" class="form-control" id="autofill-series" name="series" maxlength="120" autocomplete="off">
            </div>
          </div>
          <div class="row g-2 mb-2 align-items-end">
            <div class="col-md-6">
              <label for="autofill-api-id" class="form-label">API ID (ASIN, Google ID, etc)</label>
              <input type="text" class="form-control" id="autofill-api-id" name="api_id" placeholder="e.g. B00XXXXXXX, google:zyTCAlFPjgYC, ..." autocomplete="off">
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <button type="submit" class="btn btn-primary w-100" id="autofill-search-btn">Search</button>
            </div>
            <div class="col-md-4">
              <div id="autofill-modal-feedback" class="alert alert-danger d-none" style="display:none;"></div>
            </div>
          </div>
        </form>
        <div class="table-responsive" id="autofill-results-wrapper" style="display:none;">
          <table class="table table-bordered table-hover align-middle mb-0" id="autofill-results-table">
            <thead class="table-light">
              <tr>
                <th scope="col">Select</th>
                <th scope="col">Cover</th>
                <th scope="col">Title</th>
                <th scope="col">Author</th>
                <th scope="col">Series</th>
                <th scope="col">Year</th>
                <th scope="col">Source</th>
              </tr>
            </thead>
            <tbody>
              <!-- Results will be injected here by JS -->
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="autofill-apply-btn" disabled>Apply</button>
      </div>
    </div>
  </div>
</div>

@endsection
