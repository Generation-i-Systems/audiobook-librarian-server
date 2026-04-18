@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Manage Books</h1>

        @include('components.ai-query-prompt')

        <form action="{{ route('admin.books.index') }}" method="GET" id="filter-form">
            <div class="input-group mb-3">
                <input type="text" class="form-control" placeholder="Search title, author, or series" name="search"
                    value="{{ request('search') }}">
                <select name="sort" class="form-select ms-2" onchange="this.form.submit()">
                    <option value="recent_desc" {{ (request('sort', 'recent_desc') == 'recent_desc') ? 'selected' : '' }}>Most
                        Recent</option>
                    <option value="recent_asc" {{ (request('sort') == 'recent_asc') ? 'selected' : '' }}>Oldest</option>
                    <option value="title_asc" {{ (request('sort') == 'title_asc') ? 'selected' : '' }}>Title A-Z</option>
                    <option value="title_desc" {{ (request('sort') == 'title_desc') ? 'selected' : '' }}>Title Z-A</option>
                    <option value="author_asc" {{ (request('sort') == 'author_asc') ? 'selected' : '' }}>Author A-Z</option>
                    <option value="author_desc" {{ (request('sort') == 'author_desc') ? 'selected' : '' }}>Author Z-A</option>
                    <option value="series_asc" {{ (request('sort') == 'series_asc') ? 'selected' : '' }}>Series A-Z</option>
                    <option value="series_desc" {{ (request('sort') == 'series_desc') ? 'selected' : '' }}>Series Z-A</option>
                    <option value="genre_asc" {{ (request('sort') == 'genre_asc') ? 'selected' : '' }}>Genre A-Z</option>
                    <option value="genre_desc" {{ (request('sort') == 'genre_desc') ? 'selected' : '' }}>Genre Z-A</option>
                    <option value="year_asc" {{ (request('sort') == 'year_asc') ? 'selected' : '' }}>Year Asc</option>
                    <option value="year_desc" {{ (request('sort') == 'year_desc') ? 'selected' : '' }}>Year Desc</option>
                </select>
                <button class="btn btn-outline-secondary" type="submit">Search</button>
            </div>
        </form>

        <div class="mb-3">
            <a href="{{ route('admin.books.create') }}" class="btn btn-primary">Add New Book</a>
            <a href="{{ route('admin.books.import') }}" class="btn btn-info">Import Book(s)</a>
            <a href="{{ route('admin.books.importFile') }}" class="btn btn-warning ms-2">Import from File/Audio</a>
            <a href="{{ route('admin.authors.index') }}" class="btn btn-outline-secondary ms-2">Manage Authors</a>
            <a href="{{ route('admin.genres.index') }}" class="btn btn-outline-secondary ms-2">Manage Genres</a>
            <a href="{{ route('admin.needs_review.index') }}" class="btn btn-outline-danger ms-2">Needs Review</a>
        </div>
        @php
            // Use pagination total instead of count() which could load all data
            $totalBooks = $books->total();
        @endphp
        <div class="mb-2 text-muted small">
            <span>Total books: <strong>{{ $totalBooks }}</strong></span>
            <span class="ms-3">Showing {{ $books->count() }} of {{ $totalBooks }} books</span>
        </div>

        @php
            $currentSort = request('sort', 'recent_desc');
            $showYear = in_array($currentSort, ['year_asc', 'year_desc']);
            $showModified = in_array($currentSort, ['recent_asc', 'recent_desc']);
            $returnUrl = request()->fullUrl();

            // Helper function to get sort URL
            $getSortUrl = function ($field) use ($currentSort) {
                $newSort = $field . '_asc';
                if (str_starts_with($currentSort, $field . '_asc')) {
                    $newSort = $field . '_desc';
                }
                return route('admin.books.index', array_merge(request()->query(), ['sort' => $newSort, 'page' => 1]));
            };

            // Helper function to get sort indicator
            $getSortIndicator = function ($field) use ($currentSort) {
                if (str_starts_with($currentSort, $field . '_asc')) {
                    return ' ↑';
                } elseif (str_starts_with($currentSort, $field . '_desc')) {
                    return ' ↓';
                }
                return '';
            };
        @endphp

        <table class="table">
            <thead>
                <tr>
                    <th style="width: 56px;"></th>
                    <th style="cursor: pointer;"><a href="{{ $getSortUrl('title') }}"
                            style="text-decoration: none; color: inherit;">Title{{ $getSortIndicator('title') }}</a></th>
                    <th style="cursor: pointer;"><a href="{{ $getSortUrl('author') }}"
                            style="text-decoration: none; color: inherit;">Author{{ $getSortIndicator('author') }}</a></th>
                    <th style="cursor: pointer;"><a href="{{ $getSortUrl('series') }}"
                            style="text-decoration: none; color: inherit;">Series{{ $getSortIndicator('series') }}</a></th>
                    <th style="width: 60px; text-align: center;">#</th>
                    <th style="cursor: pointer;"><a href="{{ $getSortUrl('genre') }}"
                            style="text-decoration: none; color: inherit;">Genre{{ $getSortIndicator('genre') }}</a></th>
                    <th style="width: 90px;">Length</th>
                    @if($showYear)
                        <th style="cursor: pointer;"><a href="{{ $getSortUrl('year') }}"
                                style="text-decoration: none; color: inherit;">Year{{ $getSortIndicator('year') }}</a></th>
                    @endif
                    @if($showModified)
                        <th style="cursor: pointer;"><a href="{{ $getSortUrl('recent') }}"
                                style="text-decoration: none; color: inherit;">Modified{{ $getSortIndicator('recent') }}</a>
                        </th>
                    @endif
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($books as $book)
                    @php
                        $rowClass = '';
                        $directoryMissing = isset($book['directoryExists']) && $book['directoryExists'] === false;
                        if ($directoryMissing) {
                            $rowClass = 'table-danger';
                        } elseif ($loop->iteration % 2 == 0) {
                            $rowClass = 'table-secondary';
                        }
                        $bookId = $book['id'] ?? ($book['documentId'] ?? 0);
                        $rowUrl = route('admin.books.show', ['book' => $bookId, 'returnUrl' => $returnUrl]);
                    @endphp
                    <tr class="{{ $rowClass }} clickable-row" data-href="{{ $rowUrl }}" @if($directoryMissing)
                    title="Directory not found: {{ $book['directoryPath'] ?? 'unknown' }}" @endif>
                        <td style="vertical-align: middle; text-align: center;">
                            @php
                                $coverImage = $book['coverImage'] ?? null;

                                // Debug logging for problematic data
                                if (is_array($coverImage) && isset($coverImage['83mb'])) {
                                    \Illuminate\Support\Facades\Log::error('Found problematic coverImage', [
                                        'book_id' => $book['id'] ?? 'unknown',
                                        'book_title' => $book['title'] ?? 'unknown',
                                        'coverImage_type' => gettype($coverImage),
                                        'coverImage_keys' => array_keys($coverImage),
                                        'coverImage_data' => $coverImage,
                                    ]);
                                }

                                // Handle array coverImage (extract path if it's an array)
                                if (is_array($coverImage)) {
                                    $coverImage = $coverImage['path'] ?? $coverImage[0] ?? null;
                                }
                                // Extra safety: ensure it's a string or null, not still an array
                                if (is_array($coverImage)) {
                                    $coverImage = null;
                                }

                                try {
                                    if (is_string($coverImage) && !empty(trim($coverImage))) {
                                        // URL encode the path to handle special characters like {, }, etc.
                                        $encodedPath = str_replace(['%2F'], ['/'], rawurlencode($coverImage));
                                        $coverProxyUrl = url('/cover/' . $encodedPath);
                                    } else {
                                        $coverProxyUrl = asset('images/placeholder.png');
                                    }
                                } catch (\Exception $e) {
                                    \Illuminate\Support\Facades\Log::error('Route generation failed', [
                                        'book_id' => $book['id'] ?? 'unknown',
                                        'book_title' => $book['title'] ?? 'unknown',
                                        'coverImage_type' => gettype($coverImage),
                                        'coverImage_value' => $coverImage,
                                        'error' => $e->getMessage(),
                                    ]);
                                    $coverProxyUrl = asset('images/placeholder.png');
                                }
                            @endphp
                            <img src="{{ $coverProxyUrl }}" alt="cover"
                                style="height: 48px; width: auto; object-fit: contain; border-radius: 3px; box-shadow: 0 1px 2px rgba(0,0,0,.07); background: #f8f8f8;"
                                loading="lazy">
                        </td>
                        <td>
                            <a href="{{ $rowUrl }}" class="text-decoration-none fw-bold text-dark">
                                {{ $book['title'] ?? 'Untitled' }}
                            </a>
                            @if($directoryMissing)
                                <span class="badge bg-danger ms-2"
                                    title="Directory not found: {{ $book['directoryPath'] ?? 'unknown' }}">⚠️ Missing Files</span>
                            @endif
                        </td>
                        <td>
                            @if(!empty($book['author']))
                                @php
                                    $authorsData = collect($book['authors_data'] ?? []);
                                    $authorsList = is_array($book['author']) ? $book['author'] : [$book['author']];
                                @endphp
                                @foreach($authorsList as $author)
                                    @php
                                        $authorName = is_array($author) && isset($author['name']) ? $author['name'] : (is_string($author) ? $author : 'Unknown');
                                        $authorRecord = $authorsData->firstWhere('name', $authorName);
                                        $authorLink = $authorRecord
                                            ? route('admin.books.index', ['search' => 'authorId:' . $authorRecord['id']])
                                            : route('admin.books.index', ['author' => $authorName]);
                                    @endphp
                                    <a href="{{ $authorLink }}" class="text-decoration-none">{{ $authorName }}</a>@if(!$loop->last)<br>@endif
                                @endforeach
                            @else
                                Unknown
                            @endif
                        </td>
                        <td>
                            @if(!empty($book['series']))
                                @php
                                    $series = $book['series'] ?? [];
                                    $seriesDataById = collect($book['series_data'] ?? [])->keyBy('name');
                                @endphp
                                @if(is_array($series))
                                    @foreach($series as $key => $item)
                                        @php
                                            $seriesName = null;
                                            if (is_array($item) && (isset($item['seriesName']) || isset($item['name']))) {
                                                $seriesName = $item['seriesName'] ?? $item['name'];
                                            } elseif (is_string($key) && (is_scalar($item) || is_null($item))) {
                                                $seriesName = $key;
                                            } elseif (is_string($item)) {
                                                $seriesName = $item;
                                            }
                                            $seriesRecord = $seriesName ? ($seriesDataById[$seriesName] ?? null) : null;
                                            $seriesLink = ($seriesRecord && isset($seriesRecord['id']))
                                                ? route('admin.books.index', ['search' => 'seriesId:' . $seriesRecord['id']])
                                                : route('admin.books.index', ['series' => $seriesName]);
                                        @endphp
                                        @if($seriesName)
                                            <a href="{{ $seriesLink }}" class="text-decoration-none">{{ $seriesName }}</a>
                                        @endif
                                        @if(!$loop->last)<br>@endif
                                    @endforeach
                                @elseif(is_string($series))
                                    <a href="{{ route('admin.books.index', ['series' => $series]) }}"
                                        class="text-decoration-none">{{ $series }}</a>
                                @endif
                            @endif
                        </td>
                        <td style="text-align: center; vertical-align: middle;">
                            @if(!empty($book['series']))
                                @php
                                    $series = $book['series'] ?? [];
                                    $seriesNumber = null;
                                @endphp
                                @if(is_array($series))
                                    @foreach($series as $key => $item)
                                        @if(is_array($item) && !empty($item['series_number']))
                                            {{ $item['series_number'] }}
                                            @php $seriesNumber = $item['series_number']; @endphp
                                        @elseif(is_array($item) && !empty($item['number']))
                                            {{ $item['number'] }}
                                            @php $seriesNumber = $item['number']; @endphp
                                        @elseif(is_string($key) && (is_scalar($item) || is_null($item)))
                                            {{ $item ?: '' }}
                                            @php $seriesNumber = $item; @endphp
                                        @endif
                                        @if(!$loop->last && $seriesNumber)<br>@endif
                                    @endforeach
                                @endif
                            @endif
                        </td>
                        <td>
                            @if(!empty($book['genre']))
                                @php
                                    $genresData = collect($book['genres_data'] ?? [])->keyBy('name');
                                    $genresList = is_array($book['genre'])
                                        ? (isset($book['genre']['name']) ? [$book['genre']['name']] : $book['genre'])
                                        : [$book['genre']];
                                @endphp
                                @foreach($genresList as $genre)
                                    @php
                                        $genreName = is_array($genre) ? ($genre['name'] ?? '') : $genre;
                                        $genreRecord = $genreName ? ($genresData[$genreName] ?? null) : null;
                                        $genreLink = ($genreRecord && isset($genreRecord['id']))
                                            ? route('admin.books.index', ['search' => 'genreId:' . $genreRecord['id']])
                                            : route('admin.books.index', ['genre' => $genreName]);
                                    @endphp
                                    <a href="{{ $genreLink }}" class="text-decoration-none">{{ $genreName }}</a>@if(!$loop->last), @endif
                                @endforeach
                            @else
                                Unknown
                            @endif
                        </td>
                        <td class="text-muted small">
                            @if(!empty($book['duration']) && $book['duration'] !== '00:00:00')
                                {{ $book['duration'] }}
                            @else
                                -
                            @endif
                        </td>
                        @if($showYear)
                            <td>
                                @if(!empty($book['year']))
                                    {{ $book['year'] }}
                                @elseif(!empty($book['release_date']))
                                    {{ \Carbon\Carbon::parse($book['release_date'])->format('Y') }}
                                @else
                                    -
                                @endif
                            </td>
                        @endif
                        @if($showModified)
                            <td>
                                @if(!empty($book['updated_at']))
                                    {{ \Carbon\Carbon::parse($book['updated_at'])->format('M d, Y') }}
                                @elseif(!empty($book['created_at']))
                                    {{ \Carbon\Carbon::parse($book['created_at'])->format('M d, Y') }}
                                @else
                                    -
                                @endif
                            </td>
                        @endif
                        <td>
                            @php
                                $bookId = $book['id'] ?? ($book['documentId'] ?? 0);
                            @endphp
                            <a href="{{ route('admin.books.edit', array_merge([$bookId], request()->query())) }}"
                                class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pencil-alt"></i></a>
                            <form action="{{ route('admin.books.autofillFromPath', $bookId) }}" method="POST" style="display: inline;">
                                @csrf
                                <input type="hidden" name="return_url" value="{{ url()->full() }}">
                                <button type="submit" class="btn btn-sm btn-outline-success"
                                    title="Autofill metadata from path-based best match (prefers Audible)"><i
                                        class="fas fa-magic"></i></button>
                            </form>
                            <form action="{{ route('admin.books.destroy', $bookId) }}"
                                method="POST" style="display: inline;">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="return_url" value="{{ url()->full() }}">
                                @php
                                    $deleteTitle = addslashes($book['title'] ?? 'Untitled');
                                @endphp
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                    onclick="return confirm('Are you sure you want to delete {{ $deleteTitle }}?')"><i
                                        class="fas fa-trash-alt"></i></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mt-3">
            {{ $books->onEachSide(2)->links('pagination.admin-books') }}
        </div>

    </div>

    {{-- Shared Directory Confirmation Modal --}}
    @if(session('requires_confirmation') && session('book_id'))
    <div class="modal fade" id="sharedDirectoryModal" tabindex="-1" aria-labelledby="sharedDirectoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="sharedDirectoryModalLabel">Shared Directory Warning</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>This book shares a directory with other books!</strong>
                    </div>
                    <p class="mb-2"><strong>Book:</strong> {{ session('book_title') }}</p>
                    <p class="text-muted small mb-3"><strong>Shared directory:</strong> <code>{{ session('shared_directory') }}</code></p>
                    <p><strong>You can only delete the database record. The files will remain on disk for the other books.</strong></p>
                    <p class="mb-0">Do you want to proceed with deleting only the database record?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form action="{{ route('admin.books.destroy', session('book_id')) }}" method="POST" style="display:inline;">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="delete_files" value="false">
                        <input type="hidden" name="confirmed" value="true">
                        @if(session('return_url'))
                            <input type="hidden" name="return_url" value="{{ session('return_url') }}">
                        @else
                            <input type="hidden" name="return_url" value="{{ url()->full() }}">
                        @endif
                        <button type="submit" class="btn btn-danger">Delete Database Record Only</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endif

    @if(session('success') || session('error'))
        @php
            $toastType = session('error') ? 'danger' : 'success';
            $toastMessage = session('error') ?: session('success');
        @endphp
        <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1085;">
            <div
                id="book-action-toast"
                class="toast align-items-center text-bg-{{ $toastType }} border-0"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                data-bs-delay="3800"
            >
                <div class="d-flex">
                    <div class="toast-body">
                        {{ $toastMessage }}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    @endif

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // Auto-show shared directory modal
                const sharedDirModal = document.getElementById('sharedDirectoryModal');
                if (sharedDirModal) {
                    const modal = new bootstrap.Modal(sharedDirModal);
                    modal.show();
                }

                // Clickable rows
                document.querySelectorAll('.clickable-row').forEach(function (row) {
                    row.addEventListener('click', function (event) {
                        if (event.target.closest('a, button, input, select, textarea, label, form')) {
                            return;
                        }
                        const href = row.getAttribute('data-href');
                        if (href) {
                            window.location = href;
                        }
                    });
                });

                const actionToast = document.getElementById('book-action-toast');
                if (actionToast) {
                    if (window.bootstrap && window.bootstrap.Toast) {
                        window.bootstrap.Toast.getOrCreateInstance(actionToast).show();
                    } else {
                        actionToast.classList.add('show');
                        setTimeout(function () {
                            actionToast.classList.remove('show');
                        }, 3800);
                    }
                }
            });
        </script>
    @endpush
@endsection
