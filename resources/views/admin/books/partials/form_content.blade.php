        <div class="container-fluid" style="max-width: 1400px;">
            @php
    $currentCover = null;
    if (isset($book) && !empty($book['coverImage'])) {
        $currentCover = $book['coverImage'];
    } elseif (!empty($initial['coverImage'])) {
        $currentCover = $initial['coverImage'];
    }

    $coverUrl = null;
    if ($currentCover && is_string($currentCover)) {
        // Handle both remote URLs and local paths
        if (str_starts_with($currentCover, 'http://') || str_starts_with($currentCover, 'https://')) {
            // Remote URL - use as-is
            $coverUrl = $currentCover;
        } else {
            // Local path - construct cover URL
            $bookDir = isset($book) && !empty($book['directoryPath']) ? $book['directoryPath'] : ($directoryPath ?? ($initial['directoryPath'] ?? null));
            if ($bookDir) {
                // Extract just the basename in case $currentCover is a full path like "Author - Title/cover.jpg"
                $coverBasename = basename($currentCover);
                $coverPath = $bookDir . '/' . $coverBasename;
                $encodedPath = str_replace(['%2F'], ['/'], rawurlencode($coverPath));
                $coverUrl = url('/cover/' . $encodedPath);
            }
        }
    }
            @endphp
            @if(empty($isModal))
                <div class="d-flex justify-content-between align-items-start" style="margin-bottom: 10px;">
                    <div>
                        <h1 style="margin-bottom: 30px;">{{ isset($book) ? 'Edit Book' : 'Create New Book' }}</h1>
                        <div class="mb-3">
                            <button type="button" class="btn btn-info" id="autofill-modal-btn" aria-label="Autofill Book Metadata"><i
                                    class="fas fa-magic me-2"></i>Autofill Book Metadata</button>
                             <button type="button" class="btn btn-success ms-2" id="magic-autofill-btn"
                                  title="Auto-search and apply first result from Audible" aria-label="Magic Autofill"><i class="fas fa-magic"></i></button>
                            @if(!empty($initial['sourcePath']) || !empty($initial['sourceRelPath']))
                                <button type="button" class="btn btn-primary ms-2" id="ai-extract-btn"
                                    title="Extract and enrich metadata using AI" aria-label="AI Extraction"><i class="fas fa-robot me-2"></i>AI Extract</button>
                            @endif
                            @if(isset($book))
                                <button type="button" class="btn btn-secondary ms-2" id="raw-json-edit-btn"><i
                                        class="fas fa-code me-2"></i>Raw JSON Edit</button>
                            @endif
                        </div>
                    </div>
                    <div id="cover-preview-container">
                        <div class="position-relative" style="cursor: pointer;" id="cover-preview-trigger">
                            <img src="{{ $coverUrl ?? asset('images/placeholder.png') }}" alt="Book Cover"
                                id="current-cover-image"
                                style="height: 120px; border: 2px solid #dee2e6; border-radius: 4px; {{ !$coverUrl ? 'opacity: 0.5;' : '' }}">
                            <div class="position-absolute top-0 end-0 bg-primary text-white rounded-circle"
                                style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin: -8px;">
                                <i class="fas fa-{{ $coverUrl ? 'edit' : 'plus' }}" style="font-size: 12px;"></i>
                            </div>
                        </div>
                    </div>
                </div>
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

            <form
                action="{{ isset($book) ? route('admin.books.update', ['book' => $book['id']]) : route('admin.books.store') }}"
                method="POST" enctype="multipart/form-data" id="book-form" class="mt-3">
                @php
                    $currentUrl = request()->url();
                    $referer = request()->headers->get('referer');

                    // Function to check if a URL is an edit or create form
                    $isFormUrl = function($url) {
                        if (!$url) return false;
                        return preg_match('/\/(edit|create)($|\?)/', $url);
                    };

                    // Only use referer if it's not a form URL and not the current page
                    // We also check that it's an internal URL to books.thelin.org
                    $isInternal = $referer && (strpos($referer, config('app.url')) !== false || strpos($referer, 'localhost') !== false);

                    $safeReferer = ($isInternal && !$isFormUrl($referer) && strpos($referer, $currentUrl) === false)
                        ? $referer
                        : (session('last_admin_list_url') ?? route('admin.books.index'));

                    // Priority: old input > passed returnUrl > request returnUrl > safe referer
                    $finalReturnUrl = old('returnUrl') ?? ($returnUrl ?? null) ?? request('returnUrl') ?? $safeReferer;
                @endphp
                @if($finalReturnUrl)
                    <input type="hidden" name="returnUrl" value="{{ $finalReturnUrl }}">
                @endif
                @if(request('close_on_success') || session('close_on_success'))
                    <input type="hidden" name="close_on_success" value="1">
                @endif
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

                <!-- Hidden inputs for cover image URLs (preserved on validation failure) -->
                <input type="hidden" id="coverImageUrl" name="coverImageUrl" value="{{ old('coverImageUrl', '') }}">
                <input type="hidden" id="audibleCoverImageUrl" name="audibleCoverImageUrl"
                    value="{{ old('audibleCoverImageUrl', '') }}">

                <!-- Import-related hidden fields -->
                @if(!empty($initial['sourcePath']))
                    <input type="hidden" name="sourcePath" value="{{ $initial['sourcePath'] }}">
                @endif
                @if(!empty($initial['sourceRoot']))
                    <input type="hidden" name="sourceRoot" value="{{ $initial['sourceRoot'] }}">
                @endif
                @if(!empty($initial['sourceRelPath']))
                    <input type="hidden" name="sourceRelPath" value="{{ $initial['sourceRelPath'] }}">
                @endif
                @if(!empty($initial['sourceType']))
                    <input type="hidden" name="sourceType" value="{{ $initial['sourceType'] }}">
                @endif
                @if(!empty($initial['importMode']))
                    <input type="hidden" name="importMode" value="{{ $initial['importMode'] ? '1' : '0' }}">
                @endif

                {{-- Needs Review Warning Card --}}
                @if(isset($book) && !empty($book['needsReview']))
                    <div class="card mb-3 border-warning">
                        <div class="card-header bg-warning text-dark">
                            <i class="fas fa-exclamation-triangle me-2"></i>Review Status
                        </div>
                        <div class="card-body">
                            <p class="mb-2"><strong>This book has been flagged for review.</strong></p>

                            @if(!empty($book['needsReviewReasons']) && is_array($book['needsReviewReasons']))
                                <p class="text-muted small mb-3">Check the boxes below to <strong>KEEP</strong> those reasons. Unchecked reasons will be removed when you save. If all are unchecked, the needs review flag will be cleared.</p>

                                @foreach($book['needsReviewReasons'] as $index => $reason)
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox"
                                               name="needsReviewReasons[]" value="{{ $reason }}"
                                               id="reason-{{ $index }}">
                                        <label class="form-check-label" for="reason-{{ $index }}">
                                            {{ $reason }}
                                        </label>
                                    </div>
                                @endforeach
                            @else
                                <p class="text-muted small mb-3">
                                    <em>No specific reasons were recorded for this review flag.</em>
                                </p>
                                <p class="text-muted small mb-3">
                                    To clear the review flag, check the box below and save.
                                </p>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox"
                                           name="clearNeedsReview" value="1"
                                           id="clearNeedsReview">
                                    <label class="form-check-label" for="clearNeedsReview">
                                        <strong>Clear review flag</strong>
                                    </label>
                                </div>
                            @endif

                            <input type="hidden" name="needsReviewPresent" value="1">
                        </div>
                    </div>
                @endif

                {{-- Basic Information Card --}}
                <div class="book-form-card">
                    <h5 class="book-form-section-title" data-card="basic-info">
                        <span><i class="fas fa-book me-2"></i>Basic Information</span>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </h5>
                    <div class="card-content">
                        <div class="row">
                            <div class="col-md-6">
                                <label for="title" class="form-label">Title</label>
                                <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title"
                                    value="{{ old('title', isset($book) ? ($book['title'] ?? null) : (request()->get('title') ?? ($initial['title'] ?? ''))) }}"
                                    placeholder="Enter book title" required>
                                @error('title')
                                    <span class="invalid-feedback d-block">{{ $message }}</span>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Series</label>
                                <div id="series-group">
                                    {{-- Only use canonical format: array of objects with seriesName and number --}}
                                    @php
    $seriesList = old('series');
    if (empty($seriesList)) {
        if (isset($book)) {
            $seriesList = $book['series'] ?? [];
        } else {
            $reqSeries = request()->get('series');
            if ($reqSeries && is_string($reqSeries)) {
                $seriesList = [['seriesName' => $reqSeries, 'number' => '']];
            } else {
                $seriesList = $reqSeries ?? ($initial['series'] ?? []);
            }
        }
    }
    // Ensure $seriesList is an array of objects with seriesName/number
    if (!is_array($seriesList) || (isset($seriesList[0]) && !is_array($seriesList[0]))) {
        $seriesList = [];
    }
    // If empty, provide one empty row
    if (empty($seriesList)) {
        $seriesList[] = ['seriesName' => '', 'number' => ''];
    }
                                    @endphp
                                    @foreach($seriesList as $idx => $series)
                                        <div class="d-flex align-items-start mb-2 series-row">
                                            <input type="number" name="series[{{ $idx }}][number]" class="form-control width-80 form-control-height-32 flex-shrink-0"
                                                placeholder="#"
                                                value="{{ $series['number'] ?? '' }}" step="any">
                                            <input type="text" name="series[{{ $idx }}][seriesName]"
                                                class="form-control series-autocomplete ms-2 form-control-height-32 form-control-flex-1"
                                                placeholder="Series Name" value="{{ $series['seriesName'] ?? '' }}">
                                            <div class="form-check ms-2 d-flex align-items-center form-control-height-32"
                                                title="Collection (not a primary series)">
                                                <input type="checkbox" name="series[{{ $idx }}][isCollection]"
                                                    class="form-check-input" value="1" {{ ($series['isCollection'] ?? false) ? 'checked' : '' }} style="margin-top:0;">
                                                <label class="form-check-label ms-1 small">Collection</label>
                                            </div>
                                            <datalist id="series-list"></datalist> {{-- Populated by JavaScript via autocomplete --}}
                                            @if(!empty($series['seriesName']))
                                                <button type="button" class="btn btn-sm btn-outline-primary ms-2 rename-series-btn btn-size-32 flex-shrink-0"
                                                    data-series-name="{{ $series['seriesName'] }}"
                                                    title="Rename this series" aria-label="Rename series">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                             @else
                                                <div class="btn-size-32 ms-2 flex-shrink-0" role="presentation" aria-hidden="true"></div>
                                            @endif
                                            <div class="d-flex flex-column ms-2" style="gap:2px;">
                                                <button type="button" class="btn btn-outline-danger btn-sm remove-series" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria-label="Remove series">&times;</button>
                                                <button type="button" class="btn btn-primary btn-sm add-series-row" style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria-label="Add series">+</button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Authors</label>
                                <div id="authors-group">
                                    @php
    $authors = old('author');
    if (empty($authors)) {
        if (isset($book)) {
            $authors = $book['author'] ?? [];
        } else {
            $authors = request()->get('author') ? [request()->get('author')] : ($initial['author'] ?? []);
        }
    }
    if (!is_array($authors)) {
        $authors = [$authors];
    }
    // Split authors by comma, '&', 'and' if any author contains these separators
    $splitAuthors = [];
    foreach ($authors as $author) {
        if (is_string($author)) {
            $author = trim($author);
            if ($author !== '') {
                // Split by comma, &, or " and " (case insensitive)
                $parts = preg_split('/\s*,\s*|\s*&\s*|\s+and\s+/i', $author);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $splitAuthors[] = $part;
                    }
                }
            } else {
                $splitAuthors[] = '';
            }
        } else {
            $splitAuthors[] = $author;
        }
    }
    $authors = $splitAuthors;
    if (empty($authors) || (count($authors) === 1 && ($authors[0] === null || $authors[0] === ''))) {
        $authors = [''];
    }
    $authorsCount = count($authors);
@endphp

@foreach($authors as $idx => $author)
    <div class="d-flex align-items-start mb-2 author-row">
        <input type="text" name="author[]" class="form-control author-autocomplete form-control-height-32 form-control-flex-1" value="{{ $author }}" placeholder="Author Name" required>
        <datalist id="author-list"></datalist> {{-- Populated by JavaScript via autocomplete --}}
        <div class="d-flex flex-column ms-2" style="gap:2px;">
            <button type="button" class="btn btn-outline-danger btn-sm remove-author"
                style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria-label="Remove author">&times;</button>
            @if($idx === $authorsCount - 1)
                <button type="button" class="btn btn-primary btn-sm add-author-row"
                    style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria-label="Add author">+</button>
            @endif
        </div>
    </div>
@endforeach
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Narrators</label>
                                <div id="narrators-group">
                                    @php
    $narrators = old('narrator');
    if (empty($narrators)) {
        if (isset($book)) {
            $narrators = $book['narrator'] ?? [];
        } else {
            $narrators = request()->get('narrator') ?? ($initial['narrator'] ?? []);
        }
    }
    if (!is_array($narrators))
        $narrators = [$narrators];
    if (empty($narrators) || (count($narrators) === 1 && ($narrators[0] === null || $narrators[0] === '')))
        $narrators = [''];
                                    @endphp
                                    @php $narratorsCount = count($narrators); @endphp
                                     @foreach($narrators as $idx => $narrator)
                                        <div class="d-flex align-items-start mb-2 narrator-row">
                                            <input type="text" name="narrator[]" class="form-control narrator-autocomplete form-control-height-32 form-control-flex-1" value="{{ $narrator }}" placeholder="Narrator Name">
                                            <datalist id="narrator-list"></datalist> {{-- Populated by JavaScript via autocomplete --}}
                                            <div class="d-flex flex-column ms-2" style="gap:2px;">
                                                <button type="button" class="btn btn-outline-danger btn-sm remove-narrator btn-size-32" aria-label="Remove narrator">&times;</button>
                                                @if($idx === $narratorsCount - 1)
                                                    <button type="button" class="btn btn-primary btn-sm add-narrator-row"
                                                        style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria-label="Add narrator">+</button>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Genres</label>
                                <div id="genres-group">
                                    @php
    $genres = old('genre');
    if (empty($genres)) {
        if (isset($book)) {
            $genres = $book['genre'] ?? null;
        } else {
            $genres = request()->get('genre') ?? ($initial['genre'] ?? []);
        }
    }
    if (!is_array($genres)) {
        $genres = [$genres];
    }
    if (empty($genres) || (count($genres) === 1 && ($genres[0] === null || $genres[0] === ''))) {
        $genres = [''];
    }
    $genresCount = count($genres);
                                    @endphp
                                    @foreach($genres as $idx => $genre)
                                        <div class="d-flex align-items-start mb-2 genre-row" data-original-genre="{{ $genre }}">
                                            <select name="genre[]" class="form-select form-control-height-32 form-control-flex-1" required>
                                                <option value="">Select a genre</option>
                                                @foreach($genreList as $g)
                                                    <option value="{{ $g }}" {{ $genre === $g ? 'selected' : '' }}>{{ $g }}</option>
                                                @endforeach
                                            </select>
                                            @if($idx === 0)
                                                <button type="button" class="btn btn-outline-info btn-sm ms-2 update-path-from-genre"
                                                    style="width:auto; height:32px; padding:0 8px; display:none; align-items:center; justify-content:center; white-space:nowrap;"
                                                    title="Update directory path to move book to this genre">
                                                    <i class="fas fa-folder-open me-1"></i>Update Path
                                                </button>
                                            @endif
                                            <div class="d-flex flex-column ms-2" style="gap:2px;">
                                                <button type="button" class="btn btn-outline-danger btn-sm remove-genre"
                                                    style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria-label="Remove genre">&times;</button>
                                                @if($idx === $genresCount - 1)
                                                    <button type="button" class="btn btn-primary btn-sm add-genre-row"
                                                        style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;" aria-label="Add genre">+</button>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Directory & Files Card --}}
                <div class="book-form-card">
                    <h5 class="book-form-section-title" data-card="directory">
                        <span><i class="fas fa-folder me-2"></i>Directory & Files</span>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </h5>
                    <div class="card-content">
                        <div class="mb-3">
                            <label for="directoryPath" class="form-label">Directory Path</label>
                            <div class="d-flex align-items-center flex-wrap" style="gap: 0.5rem;">
                                <div class="position-relative" style="flex: 1; min-width: 300px;">
                                    <input type="text" class="form-control @error('directoryPath') is-invalid @enderror"
                                        id="directoryPath" name="directoryPath"
                                        value="{{ old('directoryPath', $directoryPath ?? ($initial['directoryPath'] ?? '')) }}"
                                        style="padding-right: 35px;" placeholder="Path to book directory">
                                <button type="button" class="btn btn-link text-danger position-absolute"
                                        id="directory-not-found-btn"
                                        style="display: none; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 25px; height: 25px;"
                                        title="Directory not found - Click to browse"
                                        aria-label="Directory not found - Click to browse">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                <button type="button" class="btn btn-outline-secondary" id="update-path-from-fields-btn"
                                    title="Update directory path based on current genre, author, title, and series fields"
                                    aria-label="Update directory path based on current genre, author, title, and series fields">
                                    <i class="fas fa-folder-plus me-1"></i>Update Path from Fields
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="resync-path-btn"
                                    title="Parse directory path to populate title, author, and series fields"
                                    aria-label="Parse directory path to populate title, author, and series fields">
                                    <i class="fas fa-sync-alt me-1"></i>Parse Path to Fields
                                </button>
                                </div>
                            </div>
                            @error('directoryPath')
                                <span class="invalid-feedback d-block">{{ $message }}</span>
                            @enderror
                        </div>

                        <div id="planned-actions-preview" class="small text-muted mb-3" style="display:none;"></div>

                        <div class="mb-3">
                            <a href="#" class="text-decoration-none" id="show-files-link">
                                <i class="fas fa-folder-open me-1"></i>View Directory Files
                            </a>
                            <div id="directory-files-list-content" class="w-100 mt-2"
                                style="max-height:220px; overflow-y:auto; border:1px solid #ccc; border-radius:4px; background:#fafbfc; padding:8px; display:none; position: relative;">
                                <span class="text-muted">No files loaded yet.</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Additional Information Card --}}
                <div class="book-form-card">
                    <h5 class="book-form-section-title" data-card="additional-info">
                        <span><i class="fas fa-info-circle me-2"></i>Additional Information</span>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </h5>
                    <div class="card-content">
                        <div class="row">
                            <div class="col-md-9">
                                <label for="description" class="form-label">Description <span
                                        class="text-muted">(Optional)</span></label>
                                <textarea class="form-control @error('description') is-invalid @enderror" id="description"
                                    name="description" rows="3"
                                    placeholder="Enter book description">{{ old('description', isset($book) && !empty($book['description']) ? $book['description'] : ($initial['description'] ?? null)) }}</textarea>
                                @error('description')
                                    <span class="invalid-feedback d-block">{{ $message }}</span>
                                @enderror
                            </div>
                            <div class="col-md-3">
                                <label for="release_date" class="form-label">Release Date <span
                                        class="text-muted">(Optional)</span></label>
                                @php
    // Check both snake_case and camelCase versions
    $rawRelease = old('release_date', isset($book) && !empty($book['release_date']) ? $book['release_date'] : (isset($book) && !empty($book['releaseDate']) ? $book['releaseDate'] : ($initial['release_date'] ?? $initial['releaseDate'] ?? null)));
    $releaseDisplayValue = '';
    if (is_string($rawRelease) && $rawRelease !== '') {
        // If stored as YYYY-01-01, display as YYYY only; otherwise show the stored value
        $releaseDisplayValue = preg_match('/^\d{4}-01-01$/', $rawRelease) ? substr($rawRelease, 0, 4) : $rawRelease;
    }
                                @endphp
                                <input type="text" class="form-control @error('release_date') is-invalid @enderror"
                                    id="release_date" name="release_date" placeholder="YYYY or YYYY-MM-DD"
                                    value="{{ $releaseDisplayValue }}">
                                @error('release_date')
                                    <span class="invalid-feedback d-block">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                @php
    $directoryPath = isset($book) && !empty($book['directoryPath']) ? $book['directoryPath'] : ($directoryPath ?? ($initial['directoryPath'] ?? null));
    $coverImg = isset($book) && !empty($book['coverImage']) ? $book['coverImage'] : ($initial['coverImage'] ?? null);
    $coverAuto = $coverAuto ?? null;
    $coverCandidates = $coverCandidates ?? [];
    $coverOptions = [];
    $addedCovers = [];

    // Helper function to create a safe cover URL
    $createCoverUrl = function ($dir, $file) {
        if (is_string($dir) && !empty(trim($dir)) && is_string($file) && !empty(trim($file))) {
            // URL encode to handle special characters like curly braces
            $coverPath = $dir . '/' . $file;
            $encodedPath = str_replace(['%2F'], ['/'], rawurlencode($coverPath));
            return url('/cover/' . $encodedPath);
        }
        return asset('images/placeholder.png');
    };

    // Get just the filename for the current cover
    $currentCoverFilename = null;
    if (!empty($coverImg)) {
        if (is_string($coverImg)) {
            $currentCoverFilename = basename($coverImg);
        } elseif (is_array($coverImg) && isset($coverImg['data'])) {
            // This is embedded image data from import, we'll handle it later
            $currentCoverFilename = null;
        }
    }

    // Always show current cover if it exists
    if (isset($book) && !empty($currentCoverFilename)) {
        // Detect if current cover is from Audible or Google Books by filename pattern
        $coverType = 'current-NOT';
        $coverLabel = 'Current Cover';

        if (strpos($currentCoverFilename, 'audible_') === 0 || strpos($currentCoverFilename, 'cover_audible_') === 0) {
            $coverType = 'audible';
            $coverLabel = 'Current Cover (Audible)';
        } elseif (strpos($currentCoverFilename, 'googlebooks_') === 0 || strpos($currentCoverFilename, 'cover_googlebooks_') === 0) {
            $coverType = 'google';
            $coverLabel = 'Current Cover (Google Books)';
        }

        $coverOptions[] = [
            'type' => $coverType,
            'value' => $currentCoverFilename,
            'src' => $createCoverUrl($directoryPath, $currentCoverFilename),
            'label' => $coverLabel,
            'display_name' => $currentCoverFilename,
        ];
        $addedCovers[] = $currentCoverFilename;
    }

    // Handle embedded cover image from import
    if (!empty($coverImg) && is_array($coverImg) && isset($coverImg['data'])) {
        $mimeType = $coverImg['mime'] ?? 'image/jpeg';
        $imageData = base64_encode($coverImg['data']);
        $dataUri = 'data:' . $mimeType . ';base64,' . $imageData;

        $coverOptions[] = [
            'type' => 'embedded',
            'value' => 'embedded_from_import',
            'src' => $dataUri,
            'label' => 'Embedded Cover (from audio file)',
            'display_name' => 'Embedded Cover',
        ];
    }

    // Add Audible cover if available
    if (!empty($audibleCover) && !in_array($audibleCover, $addedCovers)) {
        $coverOptions[] = [
            'type' => 'audible',
            'value' => $audibleCover,
            'src' => $createCoverUrl($directoryPath, $audibleCover),
            'label' => 'Audible',
            'display_name' => $audibleCover,
        ];
        $addedCovers[] = $audibleCover;
    }

    // Add Google Books cover if available and not the same as current cover
    if (!empty($coverAuto) && $coverAuto !== $currentCoverFilename) {
        $coverOptions[] = [
            'type' => 'google',
            'value' => $coverAuto,
            'src' => $createCoverUrl($directoryPath, $coverAuto),
            'label' => 'Google Books',
            'display_name' => $coverAuto,
        ];
        $addedCovers[] = $coverAuto;
    }

    // Add other candidates (already filtered in controller)
    if (!empty($coverCandidates)) {
        foreach ($coverCandidates as $candidate) {
            if (!in_array($candidate, $addedCovers)) {
                // Check if this is an Audible cover by filename pattern
                $isAudible = (strpos($candidate, 'audible_') === 0 || strpos($candidate, 'cover_audible_') === 0);

                $coverOptions[] = [
                    'type' => $isAudible ? 'audible' : 'candidate',
                    'value' => $candidate,
                    'src' => $createCoverUrl($directoryPath, $candidate),
                    'label' => $isAudible ? 'Audible' : 'Candidate',
                    'display_name' => $candidate,
                ];
                $addedCovers[] = $candidate;
            }
        }
    }
                @endphp

                {{-- Cover Image Selection Modal --}}
                <div class="modal fade" id="coverImageModal" tabindex="-1" aria-labelledby="coverImageModalLabel"
                    aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="coverImageModalLabel">
                                    <i class="fas fa-images me-2"></i>Select Cover Image
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                @php
                                    $initialCoverSource = '';
                                    if (!empty($coverOptions)) {
                                        foreach ($coverOptions as $option) {
                                            if (
                                                (isset($biggestCover) && $biggestCover === $option['value']) ||
                                                (empty($biggestCover) && $option['type'] === 'current')
                                            ) {
                                                $initialCoverSource = $option['type'];
                                                break;
                                            }
                                        }
                                    }
                                @endphp

                                <input type="hidden" name="coverImageSource" id="coverImageSource" value="{{ $initialCoverSource }}">

                                <div class="mb-3" id="cover-candidates-group" style="display: {{ !empty($coverOptions) ? 'block' : 'none' }}">
                                    <div class="d-flex flex-wrap gap-3" id="cover-candidates-list" style="max-height: 400px; overflow-y: auto; padding: 10px; background: #f8f9fa; border-radius: 4px; border: 1px solid #dee2e6;">
                                        @forelse($coverOptions as $option)
                                            <div class="text-center">
                                                <label class="d-flex flex-column align-items-center">
                                                    <input type="radio" name="coverImageCandidate" value="{{ $option['value'] }}"
                                                        data-source="{{ $option['type'] }}"
                                                        @if((isset($biggestCover) && $biggestCover === $option['value']) || (empty($biggestCover) && $option['type'] === 'current')) checked @endif
                                                        class="mb-2">
                                                    <img src="{{ $option['src'] }}" alt="{{ $option['label'] }}"
                                                        style="max-width:100px;max-height:140px;border:1px solid #ccc;border-radius:4px;">
                                                </label>
                                                <div class="mt-1" style="font-size:12px;word-break:break-all;">
                                                    {{ $option['label'] }}<br>
                                                    <small class="text-muted">{{ $option['display_name'] ?? $option['value'] }}</small>
                                                </div>
                                            </div>
                                        @empty
                                            <div class="alert alert-info w-100 mb-0">
                                                <i class="fas fa-info-circle me-2"></i>No cover images found.
                                                <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="extract-cover-no-covers-btn" aria-label="Extract cover from audio files">
                                                    <i class="fas fa-music me-1"></i>Extract from Audio Files
                                                </button>
                                            </div>
                                        @endforelse
                                    </div>
                                </div>

                                <div id="embedded-cover-status" class="mt-3"></div>
                                <div id="embedded-cover-options" class="mt-3" style="display:none; min-height: 50px;"></div>

                                <div id="google-books-matches-table-wrapper" style="display:none;" class="mt-4">
                                    <label class="form-label">Google Books: Select a Match</label>
                                    <table class="table table-bordered table-sm mb-0" id="google-books-matches-table">
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

                                <div class="mt-3">
                                    <label for="coverImageUrlText" class="form-label">
                                        Cover Image URL <span class="text-muted">(Optional)</span>
                                    </label>
                                    <input type="text" class="form-control" id="coverImageUrlText"
                                        value="{{ old('audibleCoverImageUrl', old('coverImageUrl', '')) }}"
                                        placeholder="https://...">
                                </div>

                                <div class="mt-3">
                                    <label for="coverImage" class="form-label">
                                        Upload Cover Image <span class="text-muted">(Optional)</span>
                                    </label>
                                    <input type="file" class="form-control @error('coverImage') is-invalid @enderror" id="coverImage"
                                        name="coverImage">
                                    @error('coverImage')
                                        <span class="invalid-feedback d-block">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="mt-4" id="extract-embedded-cover-section">
                                    <button type="button" class="btn btn-outline-primary" id="extract-embedded-cover-btn"
                                aria-label="Extract cover from audio files">
                                        <i class="fas fa-music me-2"></i>Extract Cover from Audio Files
                                    </button>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" aria-label="Close modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                // Open cover image modal when clicking cover preview
                document.addEventListener('DOMContentLoaded', function() {
                    const coverPreviewTrigger = document.getElementById('cover-preview-trigger');

                    if (coverPreviewTrigger) {
                        coverPreviewTrigger.addEventListener('click', function() {
                            const modal = new bootstrap.Modal(document.getElementById('coverImageModal'));
                            modal.show();
                        });
                    }
                });

                // Collapsible card sections with summaries
                document.addEventListener('DOMContentLoaded', function() {
                    function generateSummary(card) {
                        const cardType = card.querySelector('.book-form-section-title').dataset.card;

                        if (cardType === 'basic-info') {
                            const title = document.getElementById('title')?.value || '';
                            const authors = Array.from(document.querySelectorAll('#authors-group input[name="author[]"]'))
                                .map(i => i.value).filter(v => v).join(' & ') || '';
                            const narrators = Array.from(document.querySelectorAll('#narrators-group input[name="narrator[]"]'))
                                .map(i => i.value).filter(v => v).join(' & ') || '';
                            const series = Array.from(document.querySelectorAll('#series-group .series-row'))
                                .map(row => {
                                    const name = row.querySelector('input[name*="[seriesName]"]')?.value || '';
                                    const num = row.querySelector('input[name*="[number]"]')?.value || '';
                                    return name ? (num ? `${num} in ${name}` : name) : '';
                                }).filter(v => v).join(', ') || '';
                            const genres = Array.from(document.querySelectorAll('#genres-group input[name="genre[]"]'))
                                .map(i => i.value).filter(v => v).join(', ') || '';

                            let summary = title;
                            if (series) summary += ` (${series})`;
                            if (authors) summary += ` by ${authors}`;
                            if (narrators) summary += ` narrated by ${narrators}`;
                            if (genres) summary += ` [${genres}]`;

                            return summary || 'No information entered';
                        }

                        if (cardType === 'additional-info') {
                            const description = document.getElementById('description')?.value || '';
                            const releaseDate = document.getElementById('release_date')?.value || '';

                            let summary = '';
                            if (releaseDate) summary += `Released: ${releaseDate}`;
                            if (description) {
                                const shortDesc = description.substring(0, 100) + (description.length > 100 ? '...' : '');
                                summary += (summary ? ' | ' : '') + shortDesc;
                            }

                            return summary || 'No additional information';
                        }

                        if (cardType === 'directory') {
                            const path = document.getElementById('directoryPath')?.value || '';
                            return path || 'No directory path set';
                        }

                        return '';
                    }

                    document.querySelectorAll('.book-form-section-title').forEach(function(title) {
                        // Create summary element
                        const summary = document.createElement('div');
                        summary.className = 'card-summary';
                        summary.style.display = 'none';

                        // Insert summary after the title
                        title.parentNode.insertBefore(summary, title.nextSibling);

                        title.addEventListener('click', function() {
                            const content = summary.nextElementSibling;
                            const card = this.closest('.book-form-card');

                            if (content && content.classList.contains('card-content')) {
                                const isCollapsing = content.style.display !== 'none';
                                content.style.display = isCollapsing ? 'none' : 'block';
                                this.classList.toggle('collapsed');

                                // Show/hide summary and toggle card class
                                if (isCollapsing) {
                                    summary.textContent = generateSummary(card);
                                    summary.style.display = 'block';
                                    card.classList.add('collapsed');
                                } else {
                                    summary.style.display = 'none';
                                    card.classList.remove('collapsed');
                                }
                            }
                        });
                    });
                });
                </script>
                <script>
                // Embedded cover extraction functionality
                document.addEventListener('DOMContentLoaded', function() {
                    const extractBtn = document.getElementById('extract-embedded-cover-btn');
                    const statusDiv = document.getElementById('embedded-cover-status');
                    const optionsDiv = document.getElementById('embedded-cover-options');

                    if (extractBtn) {
                        extractBtn.addEventListener('click', function() {
                            const directoryPath = document.getElementById('directoryPath')?.value;

                            if (!directoryPath) {
                                statusDiv.innerHTML = '<div class="alert alert-warning">Please select a directory path first</div>';
                                return;
                            }

                            // Show loading state
                            extractBtn.disabled = true;
                            extractBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Extracting...';
                            statusDiv.innerHTML = '<div class="alert alert-info">Scanning audio files for embedded covers...</div>';
                            optionsDiv.style.display = 'none';

                            // Make AJAX request to extract covers
                            fetch('{{ route("admin.books.extract-embedded-cover") }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                                },
                                body: JSON.stringify({
                                    directory_path: directoryPath
                                })
                            })
                            .then(response => {
                                if (!response.ok) {
                                    return response.json().then(err => { throw err; });
                                }
                                return response.json();
                            })
                            .then(data => {
                                if (data.success) {
                                    statusDiv.innerHTML = `<div class="alert alert-success">${data.message}</div>`;
                                    displayExtractedCovers(data.covers);
                                } else {
                                    statusDiv.innerHTML = `<div class="alert alert-danger">${data.error || 'Extraction failed'}</div>`;
                                    optionsDiv.style.display = 'none';
                                }
                            })
                            .catch(error => {
                                console.error('Error extracting covers:', error);
                                statusDiv.innerHTML = '<div class="alert alert-danger">Failed to extract covers. Please try again.</div>';
                                optionsDiv.style.display = 'none';
                            })
                            .finally(() => {
                                // Reset button
                                extractBtn.disabled = false;
                                extractBtn.innerHTML = '<i class="fas fa-music me-2"></i>Extract Cover from Audio Files';
                            });
                        });
                    }

                    // Add event listener for the extract button in the no covers alert
                    const extractNoCoversBtn = document.getElementById('extract-cover-no-covers-btn');
                    if (extractNoCoversBtn && extractBtn) {
                        extractNoCoversBtn.addEventListener('click', function() {
                            extractBtn.click();
                        });
                    }

                    function autoExtractEmbeddedCover() {
                        const directoryPath = document.getElementById('directoryPath')?.value;

                        if (!directoryPath) {
                            alert('Please select a directory path first');
                            return;
                        }

                        // Trigger the extract button click
                        const extractBtn = document.getElementById('extract-embedded-cover-btn');
                        if (extractBtn) {
                            extractBtn.click();
                        }
                    }

                    function displayExtractedCovers(covers) {
                        optionsDiv.innerHTML = '';
                        optionsDiv.style.display = 'block';

                        const title = document.createElement('h6');
                        title.className = 'mb-3';
                        title.textContent = 'Select an extracted cover:';
                        optionsDiv.appendChild(title);

                        const row = document.createElement('div');
                        row.className = 'row g-3';

                        covers.forEach((cover, index) => {
                            const col = document.createElement('div');
                            col.className = 'col-md-4';

                            const card = document.createElement('div');
                            card.className = 'card h-100';

                            const img = document.createElement('img');
                            img.src = cover.url;
                            img.className = 'card-img-top';
                            img.style.maxHeight = '200px';
                            img.style.objectFit = 'cover';
                            img.alt = `Cover from ${cover.file}`;

                            const cardBody = document.createElement('div');
                            cardBody.className = 'card-body p-2';

                            const title = document.createElement('small');
                            title.className = 'd-block text-muted mb-2';
                            title.textContent = `From: ${cover.file}`;

                            const selectBtn = document.createElement('button');
                            selectBtn.type = 'button';
                            selectBtn.className = 'btn btn-primary btn-sm w-100';
                            selectBtn.textContent = 'Select This Cover';
                            selectBtn.onclick = function() {
                                selectExtractedCover(cover);
                            };

                            cardBody.appendChild(title);
                            cardBody.appendChild(selectBtn);

                            card.appendChild(img);
                            card.appendChild(cardBody);
                            col.appendChild(card);
                            row.appendChild(col);
                        });

                        optionsDiv.appendChild(row);
                    }

                    function selectExtractedCover(cover) {
                        // Create a temporary file input to hold the cover data
                        const dataInput = document.createElement('input');
                        dataInput.type = 'hidden';
                        dataInput.name = 'embedded_cover_temp_path';
                        dataInput.value = cover.temp_path;
                        dataInput.id = 'embedded-cover-temp-path';

                        // Remove any existing embedded cover input
                        const existingInput = document.getElementById('embedded-cover-temp-path');
                        if (existingInput) {
                            existingInput.remove();
                        }

                        // Add the new input to the form
                        document.getElementById('book-form').appendChild(dataInput);

                        // Update the cover preview
                        const coverPreview = document.getElementById('current-cover-image');
                        if (coverPreview) {
                            coverPreview.src = cover.url;
                        }

                        // Update the cover candidates list
                        const candidatesList = document.getElementById('cover-candidates-list');
                        if (candidatesList) {
                            // Remove existing embedded option if present
                            const existingEmbedded = candidatesList.querySelector('[data-source="embedded"]');
                            if (existingEmbedded) {
                                existingEmbedded.closest('.text-center').remove();
                            }

                            // Add new embedded option
                            const optionDiv = document.createElement('div');
                            optionDiv.className = 'text-center';
                            optionDiv.innerHTML = `
                                <label class="d-flex flex-column align-items-center">
                                    <input type="radio" name="coverImageCandidate" value="embedded_extracted"
                                        data-source="embedded" checked class="mb-2">
                                    <img src="${cover.url}" alt="Extracted Cover"
                                        style="max-width:100px;max-height:140px;border:1px solid #ccc;">
                                </label>
                                <div class="mt-1" style="font-size:12px;word-break:break-all;">
                                    Extracted Cover<br>
                                    <small class="text-muted">From ${cover.file}</small>
                                </div>
                            `;
                            candidatesList.appendChild(optionDiv);
                        }

                        // Show success message
                        statusDiv.innerHTML = '<div class="alert alert-success">Cover selected! It will be saved to the book directory when you save the book.</div>';

                        // Close the modal after a short delay
                        setTimeout(() => {
                            const modal = bootstrap.Modal.getInstance(document.getElementById('coverImageModal'));
                            if (modal) {
                                modal.hide();
                            }
                        }, 1500);
                    }
                });
                </script>
                <script>
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
                                if (data.authors && window.BookForm?.addAuthorRow) {
                                    const authorsGroup = document.getElementById('authors-group');
                                    authorsGroup.innerHTML = '';
                                    data.authors.forEach(function(author) {
                                        window.BookForm.addAuthorRow($('#book-form'), author);
                                    });
                                }
                                if (data.series && window.BookForm?.addSeriesRow) {
                                    const seriesGroup = document.getElementById('series-group');
                                    seriesGroup.innerHTML = '';
                                    data.series.forEach(function(series) {
                                        window.BookForm.addSeriesRow($('#book-form'), {
                                            number: series.number,
                                            seriesName: series.name,
                                            isCollection: series.isCollection || false
                                        });
                                    });
                                }
                            }
                        })
                        .catch(err => {
                            resyncBtn.disabled = false;
                            resyncBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Resync Title/Author/Series';
                            alert('Resync failed.');
                        });
                });
                });
                </script>
                @if(isset($book))
                    <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                        <div>
                            <button type="submit" class="btn btn-primary" id="modal-update-btn">Save Changes</button>
                            @if(!empty($isModal))
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="modal-cancel-btn">Cancel</button>
                            @else
                                <a href="{{ $finalReturnUrl ?? route('admin.books.index') }}" class="btn btn-secondary">Cancel</a>
                            @endif
                        </div>
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteBookModal">
                            <i class="fas fa-trash me-2"></i>Delete Book
                        </button>
                    </div>
                @else
                    <button type="submit" class="btn btn-primary" id="modal-create-btn">Create</button>
                    @if(!empty($isModal))
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="modal-cancel-btn">Cancel</button>
                    @else
                        <a href="{{ $finalReturnUrl ?? route('admin.books.index') }}" class="btn btn-secondary">Cancel</a>
                    @endif
                @endif
            </form>
        </div>
