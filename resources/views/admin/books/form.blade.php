@extends(isset($layout) ? $layout : 'layouts.app')

@section('content')
<style>
.cursor-pointer {
    cursor: pointer;
}
.cursor-pointer:hover {
    background-color: #f8f9fa;
}
.book-form-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.book-form-card.collapsed {
    padding-bottom: 0.5rem;
}
.book-form-card .form-label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
}
.book-form-section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #212529;
    margin-bottom: 0;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e9ecef;
    cursor: pointer;
    user-select: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.book-form-section-title:hover {
    background-color: #f8f9fa;
}
.book-form-section-title .toggle-icon {
    transition: transform 0.2s;
}
.book-form-section-title.collapsed .toggle-icon {
    transform: rotate(-90deg);
}
.card-summary {
    color: #6c757d;
    font-size: 0.9rem;
    font-weight: normal;
    padding: 0.5rem 0;
    margin-bottom: 0.25rem;
}
</style>
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
            $bookDir = isset($book) && !empty($book['directoryPath']) ? $book['directoryPath'] : ($directoryPath ?? ($initial['directoryPath'] ?? null));
            if ($bookDir) {
                $coverUrl = route('cover.proxy', ['path' => $bookDir . '/' . basename($currentCover)]);
            }
        }
    @endphp
    @if(empty($isModal))
        <div class="d-flex justify-content-between align-items-start" style="margin-bottom: 10px;">
            <div>
                <h1 style="margin-bottom: 30px;">{{ isset($book) ? 'Edit Book' : 'Create New Book' }}</h1>
                <div class="mb-3">
                    <button type="button" class="btn btn-info" id="autofill-modal-btn"><i class="fas fa-magic me-2"></i>Autofill Book Metadata</button>
                    @if(isset($book))
                    <button type="button" class="btn btn-secondary ms-2" id="raw-json-edit-btn"><i class="fas fa-code me-2"></i>Raw JSON Edit</button>
                    @endif
                </div>
            </div>
            @if($coverUrl)
                <div class="position-relative" style="cursor: pointer;" id="cover-preview-trigger">
                    <img src="{{ $coverUrl }}" alt="Book Cover" style="height: 120px; border: 2px solid #dee2e6; border-radius: 4px;">
                    <div class="position-absolute top-0 end-0 bg-primary text-white rounded-circle" 
                         style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin: -8px;">
                        <i class="fas fa-edit" style="font-size: 12px;"></i>
                    </div>
                </div>
            @endif
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

    @if(isset($book) && !empty($book['needsReview']) && !empty($book['needsReviewReasons']))
        <div class="alert alert-warning">
            <h5 class="alert-heading mb-2"><i class="fas fa-exclamation-triangle me-2"></i>This book needs review</h5>
            <strong>Reasons:</strong>
            <ul class="mb-0 mt-2">
                @foreach($book['needsReviewReasons'] as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ isset($book) ? route('admin.books.update', ['book' => $book['id']]) : route('admin.books.store') }}"
        method="POST" enctype="multipart/form-data" id="book-form" class="mt-3">
        @php
            // Priority: old input > passed returnUrl > request returnUrl > referer
            $finalReturnUrl = old('returnUrl') ?? ($returnUrl ?? null) ?? request('returnUrl') ?? request()->headers->get('referer');
        @endphp
        @if($finalReturnUrl)
            <input type="hidden" name="returnUrl" value="{{ $finalReturnUrl }}">
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
        <input type="hidden" id="directoryPath" name="directoryPath" value="{{ $displayPath }}">

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
                            value="{{ old('title') ?? request()->get('title') ?? ($book['title'] ?? null) ?? ($initial['title'] ?? '') }}" 
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
                                $seriesList = old('series') ?? request()->get('series') ?? ($book['series'] ?? ($initial['series'] ?? []));
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
                                    <input type="number" name="series[{{ $idx }}][number]" class="form-control"
                                        style="width:60px; height:32px; flex-shrink:0;" placeholder="#" value="{{ $series['number'] ?? '' }}" step="any">
                                    <input type="text" name="series[{{ $idx }}][seriesName]" class="form-control series-autocomplete ms-2" style="height:32px; flex:1;"
                                         placeholder="Series Name" value="{{ $series['seriesName'] ?? '' }}">
                                    <datalist id="series-list"></datalist>
                                    @if(!empty($series['seriesName']))
                                        <button type="button" class="btn btn-sm btn-outline-primary ms-2 rename-series-btn" 
                                            data-series-name="{{ $series['seriesName'] }}"
                                            style="height:32px; width:32px; padding:0; display:flex; align-items:center; justify-content:center; flex-shrink:0;"
                                            title="Rename this series">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    @else
                                        <div style="width:32px; height:32px; margin-left:0.5rem; flex-shrink:0;"></div>
                                    @endif
                                    <div class="d-flex flex-column ms-2" style="gap:2px;">
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-series"
                                            style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">&times;</button>
                                        @if($idx === count($seriesList) - 1)
                                            <button type="button" class="btn btn-primary btn-sm add-series-row"
                                                style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">+</button>
                                        @endif
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
                                $authors = old('author') ?? (request()->get('author') ? [request()->get('author')] : null) ?? ($book['author'] ?? null) ?? ($initial['author'] ?? []);
                                if (!is_array($authors)) {
                                    $authors = [$authors];
                                }
                                if (empty($authors) || (count($authors) === 1 && ($authors[0] === null || $authors[0] === ''))) {
                                    $authors = [''];
                                }
                            @endphp
                            @php $authorsCount = count($authors); @endphp
                            @foreach($authors as $idx => $author)
                                <div class="d-flex align-items-start mb-2 author-row">
                                    @php
                                        if ($author instanceof \MongoDB\Model\BSONArray) {
                                            $author = (array) $author;
                                        }
                                        if (is_array($author)) {
                                            $author = implode(', ', $author);
                                        }
                                    @endphp
                                    <input type="text" name="author[]" class="form-control author-autocomplete" style="height:32px; flex:1;"
                                        value="{{ $author }}" placeholder="Author Name" required>
                                    <datalist id="author-list"></datalist>
                                    <div class="d-flex flex-column ms-2" style="gap:2px;">
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-author"
                                            style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">&times;</button>
                                        @if($idx === $authorsCount - 1)
                                            <button type="button" class="btn btn-primary btn-sm add-author-row"
                                                style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">+</button>
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
                            $narrators = old('narrator') ?? request()->get('narrator') ?? ($book['narrator'] ?? null) ?? ($initial['narrator'] ?? []);
                            if (!is_array($narrators))
                                $narrators = [$narrators];
                            if (empty($narrators) || (count($narrators) === 1 && ($narrators[0] === null || $narrators[0] === '')))
                                $narrators = [''];
                        @endphp
                        @php $narratorsCount = count($narrators); @endphp
                        @foreach($narrators as $idx => $narrator)
                            <div class="d-flex align-items-start mb-2 narrator-row">
                                @php
                                    if ($narrator instanceof \MongoDB\Model\BSONArray) {
                                        $narrator = (array) $narrator;
                                    }
                                    if (is_array($narrator)) {
                                        $narrator = implode(', ', $narrator);
                                    }
                                @endphp
                                <input type="text" name="narrator[]" class="form-control narrator-autocomplete" style="height:32px; flex:1;"
                                    value="{{ $narrator }}" placeholder="Narrator Name">
                                <datalist id="narrator-list"></datalist>
                                <div class="d-flex flex-column ms-2" style="gap:2px;">
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-row"
                                        style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">&times;</button>
                                    @if($idx === $narratorsCount - 1)
                                        <button type="button" class="btn btn-primary btn-sm add-narrator-row"
                                            style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">+</button>
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
                            $genres = old('genre') ?? request()->get('genre') ?? ($book['genre'] ?? null) ?? ($initial['genre'] ?? []);
                            if (!is_array($genres)) {
                                $genres = [$genres];
                            }
                            if (empty($genres) || (count($genres) === 1 && ($genres[0] === null || $genres[0] === ''))) {
                                $genres = [''];
                            }
                            $genresCount = count($genres);
                        @endphp
                        @foreach($genres as $idx => $genre)
                            <div class="d-flex align-items-start mb-2 genre-row">
                                <select name="genre[]" class="form-select" style="height:32px; flex:1;" required>
                                    <option value="">Select a genre</option>
                                    @foreach($genreList as $g)
                                        <option value="{{ $g }}" {{ $genre === $g ? 'selected' : '' }}>{{ $g }}</option>
                                    @endforeach
                                </select>
                                <div class="d-flex flex-column ms-2" style="gap:2px;">
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-genre"
                                        style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">&times;</button>
                                    @if($idx === $genresCount - 1)
                                        <button type="button" class="btn btn-primary btn-sm add-genre-row"
                                            style="width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">+</button>
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
                <div class="d-flex align-items-center">
                    <div class="position-relative" style="flex: 1;">
                        <input type="text" class="form-control @error('directoryPath') is-invalid @enderror" id="directoryPath"
                            name="directoryPath" value="{{ old('directoryPath', $directoryPath ?? ($initial['directoryPath'] ?? '')) }}" 
                            style="padding-right: 35px;" placeholder="Path to book directory">
                        <button type="button" class="btn btn-link text-danger position-absolute" id="directory-not-found-btn" 
                            style="display: none; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 25px; height: 25px;"
                            title="Directory not found - Click to browse">
                            <i class="fas fa-times-circle"></i>
                        </button>
                    </div>
                    <button type="button" class="btn btn-outline-secondary ms-2" id="resync-path-btn" 
                        title="Parse directory path to populate title, author, and series fields">
                        <i class="fas fa-sync-alt me-1"></i>Resync Fields from Path
                    </button>
                </div>
                @error('directoryPath')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>

            <div class="mb-3">
                <a href="#" class="text-decoration-none" id="show-files-link">
                    <i class="fas fa-folder-open me-1"></i>View Directory Files
                </a>
                <div id="directory-files-list"
                    class="w-100 mt-2"
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
                        <label for="description" class="form-label">Description <span class="text-muted">(Optional)</span></label>
                        <textarea class="form-control @error('description') is-invalid @enderror" id="description"
                            name="description" rows="3" 
                            placeholder="Enter book description">{{ old('description', isset($book) && !empty($book['description']) ? $book['description'] : ($initial['description'] ?? null)) }}</textarea>
                        @error('description')
                            <span class="invalid-feedback d-block">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="release_date" class="form-label">Release Date <span class="text-muted">(Optional)</span></label>
                        @php
                            $rawRelease = old('release_date', isset($book) && !empty($book['release_date']) ? $book['release_date'] : ($initial['release_date'] ?? null));
                            $releaseDisplayValue = '';
                            if (is_string($rawRelease) && $rawRelease !== '') {
                                // If stored as YYYY-01-01, display as YYYY only; otherwise show the stored value
                                $releaseDisplayValue = preg_match('/^\d{4}-01-01$/', $rawRelease) ? substr($rawRelease, 0, 4) : $rawRelease;
                            }
                        @endphp
                        <input type="text" class="form-control @error('release_date') is-invalid @enderror" id="release_date"
                            name="release_date"
                            placeholder="YYYY or YYYY-MM-DD"
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
            $createCoverUrl = function($dir, $file) {
                if (is_string($dir) && !empty(trim($dir)) && is_string($file) && !empty(trim($file))) {
                    // Don't encode - Laravel route handles the path parameter correctly
                    return route('cover.proxy', ['path' => $dir . '/' . $file]);
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
        <div class="modal fade" id="coverImageModal" tabindex="-1" aria-labelledby="coverImageModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="coverImageModalLabel"><i class="fas fa-images me-2"></i>Select Cover Image</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @if (!empty($coverOptions))
                        <div class="mb-3" id="cover-candidates-group">
            @php
                $initialCoverSource = '';
                foreach($coverOptions as $option) {
                    if((isset($biggestCover) && $biggestCover === $option['value']) ||
                       (empty($biggestCover) && $option['type'] === 'current')) {
                        $initialCoverSource = $option['type'];
                        break;
                    }
                }
            @endphp
            <!-- Original coverImageSource field -->
            <input type="hidden" name="coverImageSource" id="coverImageSource" value="{{ $initialCoverSource }}">
            
            <!-- Add a script to ensure coverImageSource is set correctly on form submission -->
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const bookForm = document.getElementById('book-form');
                    if (bookForm) {
                        bookForm.addEventListener('submit', function() {
                            const selectedRadio = document.querySelector('input[name="coverImageCandidate"]:checked');
                            if (selectedRadio) {
                                const sourceField = document.getElementById('coverImageSource');
                                if (sourceField) {
                                    sourceField.value = selectedRadio.dataset.source || '';
                                    console.log('Form submit: Setting coverImageSource to ' + sourceField.value);
                                }
                            }
                        });
                    }
                });
            </script>
            <!-- Debug info -->
            <div class="d-none">Initial cover source: {{ $initialCoverSource }}</div>
            <div class="d-flex flex-wrap gap-3" id="cover-candidates-list">
                @foreach($coverOptions as $option)
                <div class="text-center">
                    <label class="d-flex flex-column align-items-center">
                        <input type="radio" name="coverImageCandidate" value="{{ $option['value'] }}"
                            data-source="{{ $option['type'] }}"
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

            <div id="google-books-matches-table-wrapper" style="display:none;" class="mt-3">
                <label class="form-label">Google Books: Select a Match</label>
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

            <div class="mt-3">
                <label for="coverImage" class="form-label">Upload Cover Image <span class="text-muted">(Optional)</span></label>
                <input type="file" class="form-control @error('coverImage') is-invalid @enderror" id="coverImage"
                    name="coverImage">
                @error('coverImage')
                    <span class="invalid-feedback d-block">{{ $message }}</span>
                @enderror
            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

{{-- Series Rename Modal --}}
<div class="modal fade" id="renameSeriesModal" tabindex="-1" aria-labelledby="renameSeriesModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="renameSeriesModalLabel">Rename Series</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Warning:</strong> This will rename the series for ALL books in this series, not just this book.
                </div>
                <div class="mb-3">
                    <label for="old-series-name" class="form-label">Current Series Name</label>
                    <input type="text" class="form-control" id="old-series-name" readonly>
                </div>
                <div class="mb-3">
                    <label for="new-series-name" class="form-label">New Series Name</label>
                    <input type="text" class="form-control" id="new-series-name" placeholder="Enter new series name">
                </div>
                <div id="rename-series-feedback"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirm-rename-series-btn">Rename Series</button>
            </div>
        </div>
    </div>
</div>

{{-- Directory Browser Modal --}}
<div class="modal fade" id="directoryBrowserModal" tabindex="-1" aria-labelledby="directoryBrowserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="directoryBrowserModalLabel">Browse Directories</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Current Path:</label>
                    <div class="d-flex align-items-center">
                        <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="dir-browser-up-btn" disabled>
                            <i class="fas fa-arrow-up"></i> Up
                        </button>
                        <input type="text" class="form-control" id="dir-browser-current-path" readonly>
                    </div>
                </div>
                <div class="border rounded p-3" style="max-height: 400px; overflow-y: auto;">
                    <div id="dir-browser-list">
                        <div class="text-center text-muted">Loading...</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="dir-browser-select-btn">Select This Directory</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Accessibility fix for autofillModal: Remove aria-hidden if set while modal is open and focused
(function() {
    var autofillModal = document.getElementById('autofillModal');
    var observer = null;
    function removeAriaHiddenIfFocused() {
        if (!autofillModal) return;
        // Remove aria-hidden if modal or any descendant has focus
        var focused = autofillModal.contains(document.activeElement) || autofillModal === document.activeElement;
        if (focused && autofillModal.hasAttribute('aria-hidden')) {
            autofillModal.removeAttribute('aria-hidden');
        }
    }
    if (autofillModal) {
        autofillModal.addEventListener('show.bs.modal', function () {
            removeAriaHiddenIfFocused();
            // Observe attribute changes to remove aria-hidden if it's set while open
            if (observer) observer.disconnect();
            observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'aria-hidden') {
                        removeAriaHiddenIfFocused();
                    }
                });
            });
            observer.observe(autofillModal, { attributes: true });
        });
        autofillModal.addEventListener('hidden.bs.modal', function () {
            if (observer) observer.disconnect();
            observer = null;
        });
        // Also patch on focusin (e.g. if focus moves to modal)
        autofillModal.addEventListener('focusin', removeAriaHiddenIfFocused);
    }
})();
</script>

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
        narratorsAutocomplete: "{{ route('admin.books.autocomplete.narrators') }}",
        renameSeries: "{{ route('admin.books.renameSeries') }}"
    };

    // Set other global variables
    window.APP_URL = "{{ config('app.url') }}";
    window.GENRE_OPTIONS = @json(config('genres.list', []));
    window.AUDIBLE_SEARCH_URL = "{{ route('admin.books.audible') }}";
    window.BOOK_FORM_ROUTES.browseDirectories = "{{ route('admin.books.browseDirectories') }}";
    window.BOOK_FORM_ROUTES.parsePath = "{{ route('admin.books.parsePath') }}";

    // Debug: Confirm jQuery and jQuery UI are loaded
    console.log('window.jQuery:', typeof window.jQuery, window.jQuery ? 'OK' : 'MISSING');
    console.log('$.fn.autocomplete:', typeof $.fn.autocomplete, $.fn.autocomplete ? 'OK' : 'MISSING');
</script>

{{-- Include form.js, directory-browser.js, and series-rename.js scripts --}}
<script src="{{ asset('js/admin/books/form.js') }}"></script>
<script src="{{ asset('js/admin/books/directory-browser.js') }}"></script>
<script src="{{ asset('js/admin/books/series-rename.js') }}"></script>
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

<div class="modal fade" id="autofillModal" tabindex="-1" aria-labelledby="autofillModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="true">
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

{{-- Removed duplicate book-autocomplete.js - form.js already handles autocomplete with jQuery UI --}}
@endsection
