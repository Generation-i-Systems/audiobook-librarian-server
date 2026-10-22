@extends('layouts.app')

@push('styles')
    <style>
        .book-card {
            height: 100%;
            transition: transform 0.2s;
        }

        .book-card:hover {
            transform: translateY(-5px);
        }

        .book-image-container {
            background-color: #f8f9fa;
            border-radius: 0.375rem;
            padding: 0.5rem;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .book-image {
            max-height: 160px;
            width: auto;
            object-fit: contain;
        }

        .compact-image {
            max-height: 120px;
        }

        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 1.5rem;
        }

        .pagination-container .page-item {
            margin: 0 0.25rem;
        }

        .view-toggle-btn.active {
            background-color: #6c757d !important;
            border-color: #6c757d !important;
            color: white !important;
        }

        .loading-spinner {
            display: none;
            text-align: center;
            padding: 2rem 0;
        }

        .book-card-hover {
            transition: all 0.2s ease-in-out;
            cursor: pointer;
        }

        .book-card-hover:hover {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            transform: translateY(-0.25rem);
            border-color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.05);
        }

        .table-hover tbody tr {
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(128, 110, 253, 0.1) !important;
        }

        .table-striped>tbody>tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .load-more-btn {
            transition: all 0.2s ease;
        }

        .load-more-btn:hover {
            background-color: #0d6efd;
            color: white;
        }

        .book-media-panel {
            background: #f8f9fa;
            border-top-left-radius: 0.375rem;
            border-top-right-radius: 0.375rem;
            min-height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .book-media-panel--compact {
            min-height: 120px;
        }

        .book-media-panel--recent-compact {
            min-height: 96px;
            border-top-right-radius: 0;
            border-bottom-left-radius: 0.375rem;
            border-bottom-right-radius: 0;
        }

        .book-media-panel--coverless {
            background: linear-gradient(160deg, #f8f9fa 0%, #eef2ff 100%);
            flex-direction: column;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.75rem;
        }

        .book-media-panel__badge {
            align-self: flex-start;
            background: rgba(13, 110, 253, 0.12);
            border-radius: 999px;
            color: #0d6efd;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.03em;
            padding: 0.25rem 0.55rem;
            text-transform: uppercase;
        }

        .book-media-panel__icon {
            color: #6c757d;
            font-size: 1.75rem;
        }

        .book-media-panel--compact .book-media-panel__icon,
        .book-media-panel--recent-compact .book-media-panel__icon {
            font-size: 1.35rem;
        }

        .book-media-panel__meta {
            color: #6c757d;
            font-size: 0.75rem;
            text-align: center;
        }

        .book-list-media-fallback {
            align-items: center;
            background: #f8f9fa;
            border-radius: 0.375rem;
            color: #6c757d;
            display: inline-flex;
            height: 64px;
            justify-content: center;
            width: 48px;
        }
    </style>
@endpush

@section('content')
    <div class="container">
        <h1>{{ __('Book Archive') }}</h1>
        <div id="book-preference-error" class="alert alert-warning d-none" role="alert"></div>

        @if(Auth::user()?->is_admin)
            @include('components.ai-query-prompt')
        @endif

        @php
$showFilters = request()->has('search') || request()->has('genre_id') || request()->has('author_id') || request()->has('series');
$isLibrivoxMode = $isLibrivoxMode ?? false;
$canManageBooks = $canManageBooks ?? false;
        @endphp

        @can('manage-books')
            <div class="mb-3">
                <a href="{{ route('admin.books.create') }}" class="btn btn-primary">{{ __('Add New Book') }}</a>
                <a href="{{ route('admin.books.import') }}" class="btn btn-info">Import Book(s)</a>
                <a href="{{ route('admin.books.importFile') }}" class="btn btn-warning ms-2">{{ __('Import from File/Audio') }}</a>
                <a href="{{ route('admin.needs_review.index') }}" class="btn btn-outline-danger ms-2">{{ __('Needs Review') }}</a>
            </div>
        @endcan

        <!-- Show Recently Added Books Only If There Are No Filters And There Are Recent Books -->
        @if (!$showFilters && count($recentBooks) > 0)
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ __('Recent Books') }}</h5>
                    @if(!$isLibrivoxMode)
                        <div class="btn-group" role="group" aria-label="{{ __('View options') }}">
                            <button class="btn btn-sm btn-outline-secondary view-toggle-btn {{ $recentViewType === 'grid' ? 'active' : '' }}" id="recent-grid-btn"
                                data-view="grid"><i class="fas fa-th"></i> {{ __('Grid') }}</button>
                            <button class="btn btn-sm btn-outline-secondary view-toggle-btn {{ $recentViewType === 'compact' ? 'active' : '' }}" id="recent-compact-btn"
                                data-view="compact"><i class="fas fa-th-large"></i> {{ __('Compact') }}</button>
                            <button class="btn btn-sm btn-outline-secondary view-toggle-btn {{ $recentViewType === 'list' ? 'active' : '' }}" id="recent-list-btn"
                                data-view="list"><i class="fas fa-list"></i> {{ __('List') }}</button>
                        </div>
                    @endif
                    <button class="btn btn-link text-decoration-none" id="toggle-recent-books" type="button" tabindex="0">
                        <span id="recent-books-toggle-text">{{ $recentOpen ? 'Hide' : 'Show' }}</span>
                    </button>
                </div>
                <div class="card-body p-0" id="recent-books-block" style="display: {{ $recentOpen ? 'block' : 'none' }};">
                    <!-- Grid View (Default) -->
                    <div class="row g-2" id="recent-books-grid" style="display: {{ $recentViewType === 'grid' ? 'flex' : 'none' }}; flex-wrap: wrap;">
                        @foreach($recentBooks as $book)
                            @php
                                $hasCover = is_string($book['coverImage'] ?? null) && trim($book['coverImage']) !== '';
                                $cover = $hasCover ? $book['coverImage'] : null;
                                $sourceLabel = ($book['source'] ?? null) === 'librivox' ? 'LibriVox' : 'Audiobook';
                                $title = $book['title'] ?? 'Untitled';
                                $title = is_array($title) ? ($title[0] ?? 'Untitled') : $title;
                            @endphp
                            <div class="col-md-2 mb-4">
                                <a href="{{ route('books.show', $book['id']) }}" class="text-decoration-none card-link"
                                    style="color:inherit">
                                    <div class="card h-100 book-card-hover" style="cursor:pointer;">
                                        @if($hasCover)
                                            <div class="book-media-panel pt-3">
                                                <img src="{{ $cover }}" class="card-img-top book-cover-thumb"
                                                    alt="{{ is_array($book['title']) ? ($book['title'][0] ?? 'Untitled') : ($book['title'] ?? 'Untitled') }}"
                                                    style="height: 160px; width: auto; max-width: 100%; object-fit: contain; display: block; margin-left: auto; margin-right: auto;">
                                            </div>
                                        @else
                                            <div class="book-media-panel book-media-panel--coverless" data-source="{{ strtolower($sourceLabel) }}">
                                                <span class="book-media-panel__badge">{{ $sourceLabel }}</span>
                                                <i class="fas fa-headphones-alt book-media-panel__icon" aria-hidden="true"></i>
                                                <span class="book-media-panel__meta">{{ __('Cover unavailable') }}</span>
                                            </div>
                                        @endif
                                        <div class="card-body p-2">
                                            <h6 class="card-title small mb-0">
                                                {{ $title }}
                                            </h6>
                                            <p class="card-text small mb-0">
                                                {{ !empty($book['authors']) ? implode(', ', $book['authors']) : 'Unknown' }}
                                            </p>
                                            @if(isset($book['series']) && !empty($book['series']))
                                                <p class="card-text small text-muted mb-0" style="font-size: 0.75rem;">
                                                    @foreach($book['series'] as $series)
                                                        {{ $series['seriesName'] }}@if(isset($series['number'])) (Book
                                                        {{ $series['number'] }})@endif
                                                    @endforeach
                                                </p>
                                            @endif
                                            <p class="card-text small text-muted mb-0" style="font-size: 0.75rem;">
                                                Added:
                                                {{ isset($book['createdAt']) ? date('M d, Y', strtotime($book['createdAt'])) : 'N/A' }}
                                            </p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        @endforeach
                    </div>

                    <!-- Compact View (Hidden by default) -->
                    <div class="row g-2" id="recent-books-compact" style="display: {{ $recentViewType === 'compact' ? 'flex' : 'none' }}; flex-wrap: wrap;">
                        @foreach($recentBooks as $book)
                            @php
                                $hasCover = is_string($book['coverImage'] ?? null) && trim($book['coverImage']) !== '';
                                $cover = $hasCover ? $book['coverImage'] : null;
                                $sourceLabel = ($book['source'] ?? null) === 'librivox' ? 'LibriVox' : 'Audiobook';
                                $title = $book['title'] ?? 'Untitled';
                                $title = is_array($title) ? ($title[0] ?? 'Untitled') : $title;
                            @endphp
                            <div class="col-md-3 col-lg-2 mb-4">
                                <a href="{{ route('books.show', $book['id']) }}" class="text-decoration-none card-link"
                                    style="color:inherit">
                                    <div class="card h-100 book-card-hover" data-book-id="{{ $book['id'] }}">
                                        <div class="row g-0">
                                            @if($hasCover)
                                                <div class="col-4">
                                                    <div class="book-media-panel book-media-panel--recent-compact">
                                                        <img src="{{ $cover }}" class="img-fluid rounded-start" alt="{{ $title }}"
                                                            style="height: 96px; width: auto; object-fit: contain;">
                                                    </div>
                                                </div>
                                            @else
                                                <div class="col-4">
                                                    <div class="book-media-panel book-media-panel--recent-compact book-media-panel--coverless"
                                                        data-source="{{ strtolower($sourceLabel) }}">
                                                        <span class="book-media-panel__badge">{{ $sourceLabel }}</span>
                                                        <i class="fas fa-headphones-alt book-media-panel__icon" aria-hidden="true"></i>
                                                    </div>
                                                </div>
                                            @endif
                                            <div class="col-8">
                                                <div class="card-body p-2">
                                                    <h6 class="card-title mb-0">
                                                        {{ $title }}
                                                    </h6>
                                                    <p class="card-text small mb-0">
                                                        {{ !empty($book['authors']) ? implode(', ', $book['authors']) : 'Unknown' }}
                                                    </p>
                                                    @if(isset($book['series']) && !empty($book['series']))
                                                        <p class="card-text small text-muted mb-0" style="font-size: 0.75rem;">
                                                            @foreach($book['series'] as $series)
                                                                {{ $series['seriesName'] }}@if(isset($series['number'])) (Book
                                                                {{ $series['number'] }})@endif
                                                            @endforeach
                                                        </p>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        @endforeach
                    </div>

                    <!-- List View (Hidden by default) -->
                    <div class="table-responsive" id="recent-books-list" style="display: {{ $recentViewType === 'list' ? 'block' : 'none' }};">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">{{ __('Cover') }}</th>
                                    <th>{{ __('Title') }}</th>
                                    <th>{{ __('Author') }}</th>
                                    <th style="width: 100px;">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentBooks as $book)
                                    <tr>
                                        <td>
                                            @php
                                                $hasCover = is_string($book['coverImage'] ?? null) && trim($book['coverImage']) !== '';
                                                $cover = $hasCover ? $book['coverImage'] : null;
                                                $title = $book['title'] ?? 'Untitled';
                                                $title = is_array($title) ? ($title[0] ?? 'Untitled') : $title;
                                            @endphp
                                            @if($hasCover)
                                                <img src="{{ $cover }}" alt="{{ $title }}"
                                                    style="height: 64px; width: auto; max-width: 48px; object-fit: contain;">
                                            @else
                                                <span class="book-list-media-fallback"><i class="fas fa-headphones-alt" aria-hidden="true"></i></span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('books.show', $book['id']) }}" class="text-decoration-none">
                                                {{ $title }}
                                            </a>
                                            @if(isset($book['series']) && !empty($book['series']))
                                                <div class="small text-muted">
                                                    @foreach($book['series'] as $series)
                                                        {{ $series['seriesName'] }}@if(isset($series['number'])) (Book
                                                        {{ $series['number'] }})@endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            {{ !empty($book['authors']) ? implode(', ', $book['authors']) : 'Unknown' }}
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="{{ route('books.show', $book['id']) }}"
                                                    class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                                                <a href="{{ route('books.download', $book['id']) }}"
                                                    class="btn btn-sm btn-outline-success"><i class="bi bi-download"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Load More Button for Recent Books -->
                    <div class="text-center mt-3 mb-3">
                        <button id="load-more-recent" class="btn btn-outline-primary load-more-btn">
                            <i class="fas fa-plus-circle"></i> Load More Recent Books
                        </button>
                    </div>
                </div>
            </div>
            <hr />
        @endif

        <form action="{{ route('books.index') }}" method="GET" class="mb-4" data-book-search
            @can('manage-books')
                data-authors-autocomplete-url="{{ route('admin.books.autocomplete.authors') }}"
                data-series-autocomplete-url="{{ route('admin.books.autocomplete.series') }}"
            @endcan
        >
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="search" class="form-label">{{ __('Search:') }}</label>
                    <input type="text" class="form-control" id="search" name="search" value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <label for="genre" class="form-label">{{ __('Genre:') }}</label>
                    <select name="genre" id="genre" class="form-select">
                        <option value="">{{ __('All Genres') }}</option>
                        @foreach ($genres as $genreId => $genreName)
                            <option value="{{ $genreName }}" {{ request('genre') == $genreName ? 'selected' : '' }}>
                                {{ $genreName }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="author" class="form-label">{{ __('Author:') }}</label>
                    <input type="text" class="form-control autocomplete-author" id="author" name="author" 
                           value="{{ request('author') }}" placeholder="{{ __('Type to search authors...') }}">
                </div>
                <div class="col-md-2">
                    <label for="series" class="form-label">{{ __('Series:') }}</label>
                    <input type="text" class="form-control" id="series" name="series" value="{{ request('series') }}"
                        placeholder="{{ __('Filter by series') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary me-2">{{ __('Filter') }}</button>
                    <a href="{{ route('books.index') }}" class="btn btn-secondary">{{ __('Reset') }}</a>
                </div>
            </div>
            <div class="row g-3 align-items-end mt-1">
                <div class="col-md-3">
                    <label for="tag" class="form-label">{{ __('Tag:') }}</label>
                    <input type="text" class="form-control" id="tag" name="tag"
                        value="{{ request('tag') }}" placeholder="{{ __('Filter by tag') }}">
                </div>
                @can('manage-books')
                    <div class="col-md-3">
                        <label for="sort" class="form-label">{{ __('Sort:') }}</label>
                        <select name="sort" id="sort" class="form-select">
                            <option value="recent_desc" {{ $sort === 'recent_desc' ? 'selected' : '' }}>{{ __('Most Recent') }}</option>
                            <option value="recent_asc" {{ $sort === 'recent_asc' ? 'selected' : '' }}>{{ __('Oldest') }}</option>
                            <option value="title_asc" {{ $sort === 'title_asc' ? 'selected' : '' }}>{{ __('Title A-Z') }}</option>
                            <option value="title_desc" {{ $sort === 'title_desc' ? 'selected' : '' }}>{{ __('Title Z-A') }}</option>
                            <option value="author_asc" {{ $sort === 'author_asc' ? 'selected' : '' }}>{{ __('Author A-Z') }}</option>
                            <option value="author_desc" {{ $sort === 'author_desc' ? 'selected' : '' }}>{{ __('Author Z-A') }}</option>
                            <option value="series_asc" {{ $sort === 'series_asc' ? 'selected' : '' }}>{{ __('Series A-Z') }}</option>
                            <option value="series_desc" {{ $sort === 'series_desc' ? 'selected' : '' }}>{{ __('Series Z-A') }}</option>
                            <option value="genre_asc" {{ $sort === 'genre_asc' ? 'selected' : '' }}>{{ __('Genre A-Z') }}</option>
                            <option value="genre_desc" {{ $sort === 'genre_desc' ? 'selected' : '' }}>{{ __('Genre Z-A') }}</option>
                            <option value="year_asc" {{ $sort === 'year_asc' ? 'selected' : '' }}>{{ __('Year Asc') }}</option>
                            <option value="year_desc" {{ $sort === 'year_desc' ? 'selected' : '' }}>{{ __('Year Desc') }}</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-center">
                @else
                    <div class="col-md-9 d-flex align-items-center">
                @endcan
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="semantic" name="semantic" value="1"
                            {{ request('semantic') ? 'checked' : '' }}>
                        <label class="form-check-label" for="semantic" title="{{ __('Ranks results by meaning, not just exact word match — may be slower') }}">
                            {{ __('Smart search (rank by meaning, not just exact word match)') }}
                        </label>
                    </div>
                </div>
            </div>
        </form>

        @can('manage-books')
            <div id="main-book-count" class="mb-2 text-muted small">
                <span>{{ __('Total books:') }} <strong>{{ $books->total() }}</strong></span>
                <span class="ms-3">Showing {{ $books->count() }} of {{ $books->total() }} books</span>
            </div>
        @endcan

        @if(!$isLibrivoxMode)
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="btn-group" role="group" aria-label="{{ __('View options') }}">
                    <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="main-grid-btn" data-view="grid"><i
                            class="fas fa-th"></i> {{ __('Grid') }}</button>
                    <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="main-compact-btn"
                        data-view="compact"><i class="fas fa-th-large"></i> {{ __('Compact') }}</button>
                    <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="main-list-btn" data-view="list"><i
                            class="fas fa-list"></i> {{ __('List') }}</button>
                </div>
            </div>
        @endif

        <div class="per-page-controls">
            <div class="dropdown">
                <button class="btn btn-secondary dropdown-toggle" type="button" id="perPageDropdown"
                    data-bs-toggle="dropdown" aria-expanded="false">
                    <span id="current-per-page">{{ $mainPerPage }}</span> per page
                </button>
                <ul class="dropdown-menu" aria-labelledby="perPageDropdown">
                    @foreach($perPageUrls as $size => $url)
                        <li><a class="dropdown-item {{ $size === $mainPerPage ? 'active' : '' }}" href="{{ $url }}">{{ $size }}</a></li>
                    @endforeach
                </ul>
            </div>
        </div>

        <!-- Main Books Container -->
        <div id="main-books-container">
            <!-- Grid View -->
            <div class="row" id="main-books-grid" @if($mainViewType !== 'grid') style="display: none;" @endif>
                <!-- Server-render initial page for non-JS and tests -->
                @if(isset($books) && count($books) > 0)
                    @foreach($books as $book)
                        @php
                            $hasCover = is_string($book['coverImage'] ?? null) && trim($book['coverImage']) !== '';
                            $cover = $hasCover ? $book['coverImage'] : null;
                            $sourceLabel = ($book['source'] ?? null) === 'librivox' ? 'LibriVox' : 'Audiobook';
                            $title = $book['title'] ?? 'Unknown Title';
                            $title = is_array($title) ? ($title[0] ?? 'Unknown Title') : (string)$title;
                            $authors = !empty($book['authors']) && is_array($book['authors']) ? implode(', ', $book['authors']) : 'Unknown';
                            $seriesText = '';
                            if (!empty($book['series']) && is_array($book['series'])) {
                                $tmp = [];
                                foreach ($book['series'] as $s) {
                                    if (isset($s['seriesName'])) {
                                        $tmp[] = $s['seriesName'] . (isset($s['number']) ? ' (Book ' . $s['number'] . ')' : '');
                                    }
                                }
                                $seriesText = implode(', ', $tmp);
                            }
                        @endphp
                        @php
                            $authorsList = !empty($book['authors']) && is_array($book['authors']) ? $book['authors'] : [];
                            $directoryMissing = isset($book['directoryExists']) && $book['directoryExists'] === false;
                        @endphp
                        <div class="col-md-3 mb-4">
                            <div class="card h-100">
                                <a href="{{ route('books.show', $book['id']) }}" class="text-decoration-none card-link book-card-hover" style="color:inherit; cursor:pointer;">
                                    @if($hasCover)
                                        <div class="book-media-panel pt-3">
                                            <img src="{{ $cover }}" class="card-img-top book-cover-thumb" alt="{{ $title }}"
                                                 style="height: 160px; width: auto; max-width: 100%; object-fit: contain; display: block; margin-left: auto; margin-right: auto;">
                                        </div>
                                    @else
                                        <div class="book-media-panel book-media-panel--coverless" data-source="{{ strtolower($sourceLabel) }}">
                                            <span class="book-media-panel__badge">{{ $sourceLabel }}</span>
                                            <i class="fas fa-headphones-alt book-media-panel__icon" aria-hidden="true"></i>
                                            <span class="book-media-panel__meta">{{ __('Cover unavailable') }}</span>
                                        </div>
                                    @endif
                                    <div class="card-body p-2 pb-0">
                                        <h6 class="card-title small mb-0">{{ $title }}</h6>
                                        @if($seriesText)
                                            <p class="card-text small text-muted mb-0" style="font-size: 0.75rem;">{{ $seriesText }}</p>
                                        @endif
                                        @if(!empty($book['duration']) && $book['duration'] !== '00:00:00')
                                            <p class="card-text small text-muted mb-0" style="font-size: 0.75rem;"><i class="fas fa-clock"></i> {{ $book['duration'] }}</p>
                                        @endif
                                        @if($directoryMissing)
                                            <span class="badge bg-danger mt-1">{{ __('⚠️ Missing Files') }}</span>
                                        @endif
                                    </div>
                                </a>
                                <div class="card-body pt-1 pb-2 px-2">
                                    <p class="card-text small mb-0">
                                        @forelse($authorsList as $author)
                                            <a href="{{ route('books.index', ['author' => $author]) }}" class="text-decoration-none">{{ $author }}</a>@if(!$loop->last), @endif
                                        @empty
                                            Unknown
                                        @endforelse
                                    </p>
                                    @can('manage-books')
                                        <div class="d-flex gap-1 mt-1">
                                            <a href="{{ route('admin.books.edit', $book['id']) }}" class="btn btn-sm btn-outline-primary" title="{{ __('Edit') }}"><i class="fas fa-pencil-alt"></i></a>
                                            <form action="{{ route('admin.books.destroy', $book['id']) }}" method="POST" class="d-inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Are you sure you want to delete :name?', ['name' => $title])) }})">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="return_url" value="{{ url()->full() }}">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="{{ __('Delete') }}"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        </div>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            <!-- Compact View -->
            <div class="row" id="main-books-compact" @if($mainViewType !== 'compact') style="display: none;" @endif>
                @if(isset($books) && count($books) > 0)
                    @foreach($books as $book)
                        @php
                            $hasCover = is_string($book['coverImage'] ?? null) && trim($book['coverImage']) !== '';
                            $cover = $hasCover ? $book['coverImage'] : null;
                            $sourceLabel = ($book['source'] ?? null) === 'librivox' ? 'LibriVox' : 'Audiobook';
                            $title = $book['title'] ?? 'Unknown Title';
                            $title = is_array($title) ? ($title[0] ?? 'Unknown Title') : (string)$title;
                            $authors = !empty($book['authors']) && is_array($book['authors']) ? implode(', ', $book['authors']) : 'Unknown';
                            $seriesText = '';
                            if (!empty($book['series']) && is_array($book['series'])) {
                                $tmp = [];
                                foreach ($book['series'] as $s) {
                                    if (isset($s['seriesName'])) {
                                        $tmp[] = $s['seriesName'] . (isset($s['number']) ? ' (Book ' . $s['number'] . ')' : '');
                                    }
                                }
                                $seriesText = implode(', ', $tmp);
                            }
                        @endphp
                        @php
                            $authorsList = !empty($book['authors']) && is_array($book['authors']) ? $book['authors'] : [];
                            $directoryMissing = isset($book['directoryExists']) && $book['directoryExists'] === false;
                        @endphp
                        <div class="col-md-2 mb-3">
                            <div class="card h-100">
                                <a href="{{ route('books.show', $book['id']) }}" class="text-decoration-none card-link book-card-hover" style="color:inherit; cursor:pointer;">
                                    @if($hasCover)
                                        <div class="book-media-panel book-media-panel--compact pt-2">
                                            <img src="{{ $cover }}" class="card-img-top book-cover-thumb" alt="{{ $title }}"
                                                 style="height: 120px; width: auto; max-width: 100%; object-fit: contain; display: block; margin-left: auto; margin-right: auto;">
                                        </div>
                                    @else
                                        <div class="book-media-panel book-media-panel--compact book-media-panel--coverless" data-source="{{ strtolower($sourceLabel) }}">
                                            <span class="book-media-panel__badge">{{ $sourceLabel }}</span>
                                            <i class="fas fa-headphones-alt book-media-panel__icon" aria-hidden="true"></i>
                                            <span class="book-media-panel__meta">{{ __('Cover unavailable') }}</span>
                                        </div>
                                    @endif
                                    <div class="card-body p-2 pb-0">
                                        <h6 class="card-title small mb-0" style="font-size: 0.8rem;">{{ $title }}</h6>
                                        @if($seriesText)
                                            <p class="card-text small text-muted mb-0" style="font-size: 0.7rem;">{{ $seriesText }}</p>
                                        @endif
                                        @if(!empty($book['duration']) && $book['duration'] !== '00:00:00')
                                            <p class="card-text small text-muted mb-0" style="font-size: 0.7rem;"><i class="fas fa-clock"></i> {{ $book['duration'] }}</p>
                                        @endif
                                        @if($directoryMissing)
                                            <span class="badge bg-danger mt-1">⚠️</span>
                                        @endif
                                    </div>
                                </a>
                                <div class="card-body pt-1 pb-2 px-2">
                                    <p class="card-text small mb-0" style="font-size: 0.75rem;">
                                        @forelse($authorsList as $author)
                                            <a href="{{ route('books.index', ['author' => $author]) }}" class="text-decoration-none">{{ $author }}</a>@if(!$loop->last), @endif
                                        @empty
                                            Unknown
                                        @endforelse
                                    </p>
                                    @can('manage-books')
                                        <div class="d-flex gap-1 mt-1">
                                            <a href="{{ route('admin.books.edit', $book['id']) }}" class="btn btn-sm btn-outline-primary" title="{{ __('Edit') }}"><i class="fas fa-pencil-alt"></i></a>
                                            <form action="{{ route('admin.books.destroy', $book['id']) }}" method="POST" class="d-inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Are you sure you want to delete :name?', ['name' => $title])) }})">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="return_url" value="{{ url()->full() }}">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="{{ __('Delete') }}"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        </div>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            <!-- List View -->
            <div class="table-responsive" id="main-books-list" @if($mainViewType !== 'list') style="display: none;" @endif>
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 60px;">{{ __('Cover') }}</th>
                            <th>{{ __('Title') }}</th>
                            <th>{{ __('Author') }}</th>
                            <th>{{ __('Series') }}</th>
                            <th style="width: 60px; text-align: center;">#</th>
                            <th>{{ __('Genre') }}</th>
                            <th style="width: 90px;">{{ __('Length') }}</th>
                            <th style="width: 100px;">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if(isset($books) && count($books) > 0)
                            @foreach($books as $book)
                                @php
                                    $hasCover = is_string($book['coverImage'] ?? null) && trim($book['coverImage']) !== '';
                                    $cover = $hasCover ? $book['coverImage'] : null;
                                    $title = $book['title'] ?? 'Unknown Title';
                                    $title = is_array($title) ? ($title[0] ?? 'Unknown Title') : (string)$title;
                                    $authorsList = !empty($book['authors']) && is_array($book['authors']) ? $book['authors'] : [];
                                    $genresList = !empty($book['genres']) && is_array($book['genres']) ? $book['genres'] : [];
                                    $directoryMissing = isset($book['directoryExists']) && $book['directoryExists'] === false;
                                @endphp
                                <tr>
                                    <td>
                                        @if($hasCover)
                                            <img src="{{ $cover }}" alt="{{ $title }}" style="height: 64px; width: auto; max-width: 64px; object-fit: contain;">
                                        @else
                                            <span class="book-list-media-fallback"><i class="fas fa-headphones-alt" aria-hidden="true"></i></span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('books.show', $book['id']) }}" class="text-decoration-none">{{ $title }}</a>
                                        @if($directoryMissing)
                                            <span class="badge bg-danger ms-1" title="{{ __('Directory not found') }}">{{ __('⚠️ Missing Files') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @forelse($authorsList as $author)
                                            <a href="{{ route('books.index', ['author' => $author]) }}" class="text-decoration-none">{{ $author }}</a>@if(!$loop->last), @endif
                                        @empty
                                            Unknown
                                        @endforelse
                                    </td>
                                    <td>
                                        @foreach($book['series'] ?? [] as $s)
                                            <a href="{{ route('books.index', ['series' => $s['seriesName']]) }}" class="text-decoration-none">{{ $s['seriesName'] }}</a>@if(!$loop->last)<br>@endif
                                        @endforeach
                                    </td>
                                    <td class="text-center">
                                        @foreach($book['series'] ?? [] as $s)
                                            {{ $s['number'] ?? '' }}@if(!$loop->last)<br>@endif
                                        @endforeach
                                    </td>
                                    <td>
                                        @forelse($genresList as $genre)
                                            <a href="{{ route('books.index', ['genre' => $genre]) }}" class="text-decoration-none">{{ $genre }}</a>@if(!$loop->last), @endif
                                        @empty
                                            Unknown
                                        @endforelse
                                    </td>
                                    <td class="text-muted small">{{ (!empty($book['duration']) && $book['duration'] !== '00:00:00') ? $book['duration'] : '' }}</td>
                                    <td>
                                        <a href="{{ route('books.download', $book['id']) }}" class="btn btn-sm btn-secondary" title="{{ __('Download') }}"><i class="fas fa-download"></i></a>
                                        @can('manage-books')
                                            <a href="{{ route('admin.books.edit', $book['id']) }}" class="btn btn-sm btn-outline-primary" title="{{ __('Edit') }}"><i class="fas fa-pencil-alt"></i></a>
                                            <form action="{{ route('admin.books.destroy', $book['id']) }}" method="POST" class="d-inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Are you sure you want to delete :name?', ['name' => $title])) }})">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="return_url" value="{{ url()->full() }}">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="{{ __('Delete') }}"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        @if(count($books) === 0)
            <div class="alert alert-info">{{ __('No books found matching your criteria.') }}</div>
        @endif

        <!-- Pagination: plain links, so the page is always described by its query string -->
        <div class="pagination-container" id="main-pagination">
            {{ $books->onEachSide(2)->links('pagination::bootstrap-5') }}
        </div>

    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="/css/pagination-fix.css">
@endpush

@push('scripts')
    @vite('resources/js/books/search-form.js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const librivoxListOnly = @json($isLibrivoxMode);

            function saveBookPreference(key, value) {
                $.post('{{ route("books.set-preference") }}', {
                    _token: '{{ csrf_token() }}',
                    key: key,
                    value: value
                }).done(function () {
                    $('#book-preference-error').addClass('d-none').text('');
                }).fail(function (xhr) {
                    console.error('[books] Failed to save display preference:', key, xhr.status);
                    $('#book-preference-error')
                        .text(window.__('Your display setting could not be saved. Please try again.'))
                        .removeClass('d-none');
                });
            }

            // Toggle recent books visibility
            const toggleButton = document.getElementById('toggle-recent-books');
            const recentBooksBlock = document.getElementById('recent-books-block');
            const toggleText = document.getElementById('recent-books-toggle-text');

            if (toggleButton && recentBooksBlock) {
                toggleButton.addEventListener('click', function () {
                    const opening = recentBooksBlock.style.display === 'none';
                    recentBooksBlock.style.display = opening ? 'block' : 'none';
                    if (toggleText) toggleText.textContent = opening ? 'Hide' : 'Show';
                    saveBookPreference('recent_open', opening ? 'open' : 'closed');
                });
                if (@json(!$recentOpen)) {
                    recentBooksBlock.style.display = 'none';
                    if (toggleText) toggleText.textContent = 'Show';
                }
            }

            // Recent books view type toggle
            // Initialize the recent section in the remembered view type
            const initialRecentView = librivoxListOnly ? 'list' : @json($recentViewType);
            $('#recent-books-grid, #recent-books-compact, #recent-books-list').hide();
            $(`#recent-books-${initialRecentView}`).css('display', initialRecentView === 'list' ? 'block' : 'flex');
            $('#recent-grid-btn, #recent-compact-btn, #recent-list-btn').removeClass('active').removeClass('btn-secondary').addClass('btn-outline-secondary');
            $(`#recent-${initialRecentView}-btn`).addClass('active').removeClass('btn-outline-secondary').addClass('btn-secondary');

            // Variables for pagination and view state
            let mainViewType = librivoxListOnly ? 'list' : '{{ $mainViewType }}';

            // Route variables with placeholders
            const bookShowRoute = '{{ route("books.show", ":id") }}';
            const bookDownloadRoute = '{{ route("books.download", ":id") }}';
            const bookIndexRoute = '{{ route("books.index") }}';
            const bookEditRoute = '{{ route("admin.books.edit", ":id") }}';
            const bookDestroyRoute = '{{ route("admin.books.destroy", ":id") }}';
            function hasCoverImage(book) {
                return book.hasCoverImage === true || (typeof book.coverImage === 'string' && book.coverImage.trim() !== '');
            }

            function getSourceLabel(book) {
                return book.source === 'librivox' ? 'LibriVox' : 'Audiobook';
            }

            function createGridMedia(book, title, height) {
                if (hasCoverImage(book)) {
                    return `
                        <div class="book-media-panel ${height === 120 ? 'book-media-panel--compact pt-2' : 'pt-3'}">
                            <img src="${book.coverImage}" class="card-img-top book-cover-thumb" alt="${title}"
                                style="height: ${height}px; width: auto; max-width: 100%; object-fit: contain; display: block; margin-left: auto; margin-right: auto;">
                        </div>
                    `;
                }

                return `
                    <div class="book-media-panel ${height === 120 ? 'book-media-panel--compact ' : ''}book-media-panel--coverless" data-source="${getSourceLabel(book).toLowerCase()}">
                        <span class="book-media-panel__badge">${getSourceLabel(book)}</span>
                        <i class="fas fa-headphones-alt book-media-panel__icon" aria-hidden="true"></i>
                        <span class="book-media-panel__meta">Cover unavailable</span>
                    </div>
                `;
            }

            function createListMedia(book, title) {
                if (hasCoverImage(book)) {
                    return `<img src="${book.coverImage}" alt="${title}" style="height: 64px; width: auto; max-width: 64px; object-fit: contain;">`;
                }

                return '<span class="book-list-media-fallback"><i class="fas fa-headphones-alt" aria-hidden="true"></i></span>';
            }

            // Function to update main view buttons
            function updateMainViewButtons() {
                // Update button styling
                $('#main-grid-btn, #main-compact-btn, #main-list-btn').removeClass('active').removeClass('btn-secondary').addClass('btn-outline-secondary');
                $(`#main-${mainViewType}-btn`).addClass('active').removeClass('btn-outline-secondary').addClass('btn-secondary');

                // Hide all view containers
                $('#main-books-grid, #main-books-compact, #main-books-list').hide();

                // Show the active container
                $(`#main-books-${mainViewType}`).show();

                console.log('Updated view type to:', mainViewType);
            }

            // Handle main books view toggle
            $('#main-grid-btn, #main-compact-btn, #main-list-btn').on('click', function () {
                const viewType = $(this).data('view');

                if (librivoxListOnly && viewType !== 'list') {
                    return;
                }

                // Update view type
                mainViewType = viewType;

                // Update active button styling
                updateMainViewButtons();

                saveBookPreference('main_view_type', viewType);
            });

            // Remember the chosen sort per user
            $('#sort').on('change', function () {
                saveBookPreference('main_sort', $(this).val());
            });

            // Initialize main books section
            $(document).ready(function () {
                // Set initial view type button state
                updateMainViewButtons();

                // Debug initial view type
                console.log('Initial view type:', mainViewType);
            });

            // Handle recent books view toggle
            $('#recent-grid-btn, #recent-compact-btn, #recent-list-btn').on('click', function () {
                const viewType = $(this).data('view');

                if (librivoxListOnly && viewType !== 'list') {
                    return;
                }

                // Update active button styling
                $('#recent-grid-btn, #recent-compact-btn, #recent-list-btn').removeClass('active').removeClass('btn-secondary').addClass('btn-outline-secondary');
                $(this).addClass('active').removeClass('btn-outline-secondary').addClass('btn-secondary');

                // Hide all views
                $('#recent-books-grid, #recent-books-compact, #recent-books-list').hide();

                // Show selected view
                if (viewType === 'grid' || viewType === 'compact') {
                    $(`#recent-books-${viewType}`).css('display', 'flex');
                } else {
                    $(`#recent-books-${viewType}`).show();
                }

                saveBookPreference('recent_view_type', viewType);
            });

            // Add click handlers to book cards and rows
            const bookCards = document.querySelectorAll('.book-card-hover');
            bookCards.forEach(card => {
                card.addEventListener('click', function () {
                    const link = this.closest('a').getAttribute('href');
                    if (link) {
                        window.location.href = link;
                    }
                });
            });

            // Add click handlers to table rows
            const bookRows = document.querySelectorAll('table.table-hover tbody tr');
            bookRows.forEach(row => {
                row.addEventListener('click', function (e) {
                    // Don't navigate if clicking on a button
                    if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A' || e.target.closest('a') || e.target.closest('button')) {
                        return;
                    }

                    const link = this.querySelector('td a');
                    if (link) {
                        window.location.href = link.getAttribute('href');
                    }
                });
            });

            // Load more recent books functionality
            const loadMoreButton = document.getElementById('load-more-recent');
            let currentOffset = 10;
            const booksPerLoad = 8;

            if (loadMoreButton) loadMoreButton.addEventListener('click', function () {
                // Show loading state
                loadMoreButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';
                loadMoreButton.disabled = true;

                // Fetch more recent books via AJAX using the main books endpoint
                // but with forced sorting by date_added and specific pagination
                const params = {
                    page: Math.floor(currentOffset / booksPerLoad) + 1,
                    per_page: booksPerLoad,
                    sort: 'date_added',
                    order: 'desc',
                    request_type: 'recent_books'
                };

                // Convert params to query string
                const queryString = Object.keys(params)
                    .map(key => `${encodeURIComponent(key)}=${encodeURIComponent(params[key])}`)
                    .join('&');

                console.log('Sending recent books AJAX request with params:', params);
                fetch(`{{ url('/api/books/recent/json') }}?${queryString}`)
                    .then(response => {
                        console.log('Recent books response status:', response.status);
                        return response.json();
                    })
                    .then(data => {
                        console.log('Recent books AJAX response received:', data);
                        if (data.books && data.books.length > 0) {
                            console.log(`Received ${data.books.length} recent books`);
                            // Append books to each view type
                            appendBooksToViews(data.books);
                            currentOffset += data.books.length;

                            // Reset button state
                            loadMoreButton.innerHTML = '<i class="fas fa-plus-circle"></i> Load More Recent Books';
                            loadMoreButton.disabled = false;

                            // Hide button if no more books or if we've reached the end of pagination
                            if (data.books.length < booksPerLoad ||
                                (data.pagination && data.pagination.current_page >= data.pagination.last_page)) {
                                console.log('No more recent books available, hiding button');
                                loadMoreButton.style.display = 'none';
                            }
                        } else {
                            console.log('No recent books returned in response');
                            // No more books
                            loadMoreButton.innerHTML = 'No more books';
                            setTimeout(() => {
                                loadMoreButton.style.display = 'none';
                            }, 2000);
                        }
                    })
                    .catch(error => {
                        console.error('Error loading more books:', error);
                        loadMoreButton.innerHTML = '<i class="fas fa-exclamation-circle"></i> Error loading books';
                        loadMoreButton.disabled = false;
                    });
            });

            // Function to append new books to all view types
            function appendBooksToViews(books) {
                const gridContainer = document.querySelector('.recent-grid-view .row');
                const compactContainer = document.querySelector('.recent-compact-view .row');
                const listContainer = document.querySelector('.recent-list-view tbody');

                books.forEach(book => {
                    // Append to grid view
                    if (gridContainer) {
                        const gridCol = document.createElement('div');
                        gridCol.className = 'col-md-3 mb-4';
                        gridCol.innerHTML = createGridCard(book);
                        gridContainer.appendChild(gridCol);
                    }

                    // Append to compact view
                    if (compactContainer) {
                        const compactCol = document.createElement('div');
                        compactCol.className = 'col-md-2 mb-3';
                        compactCol.innerHTML = createCompactCard(book);
                        compactContainer.appendChild(compactCol);
                    }

                    // Append to list view
                    if (listContainer) {
                        const listRow = document.createElement('tr');
                        listRow.innerHTML = createListRow(book);
                        listContainer.appendChild(listRow);
                    }
                });

                // Reinitialize click handlers for new elements
                initializeClickHandlers();
            }

            // Helper functions to create HTML for different view types
            function createGridCard(book) {
                // Fix cover URL to handle paths with slashes correctly
                const title = String(book.title || 'Unknown Title'); // Ensure title is a string
                const author = book.authors && book.authors.length > 0 ? book.authors.join(', ') : 'Unknown';
                let seriesText = '';
                if (book.series && book.series.length > 0) {
                    seriesText = book.series.map(s => `${s.seriesName}${s.number ? ' (Book ' + s.number + ')' : ''}`).join(', ');
                }
                const duration = book.duration && book.duration !== '00:00:00' ? book.duration : '';
                return `
                                                            <a href="/books/${book.id}" class="text-decoration-none">
                                                                <div class="card h-100 book-card-hover">
                                                                    ${createGridMedia(book, title, 150)}
                                                                    <div class="card-body p-2">
                                                                    <h6 class="card-title small mb-0">${title}</h6>
                                                                    <p class="card-text small text-muted">${author}</p>
                                                                    ${seriesText ? `<p class="card-text small text-muted mb-0" style="font-size: 0.75rem;">${seriesText}</p>` : ''}
                                                                    ${duration ? `<p class="card-text small text-muted mb-0" style="font-size: 0.75rem;"><i class="fas fa-clock"></i> ${duration}</p>` : ''}
                                                                </div>
                                                            </div>
                                                        </a>
                                                    `;
            }

            function createCompactCard(book) {
                // Fix cover URL to handle paths with slashes correctly
                const title = String(book.title || 'Unknown Title'); // Ensure title is a string
                const author = book.authors && book.authors.length > 0 ? book.authors.join(', ') : 'Unknown';
                let seriesText = '';
                if (book.series && book.series.length > 0) {
                    seriesText = book.series.map(s => `${s.seriesName}${s.number ? ' (Book ' + s.number + ')' : ''}`).join(', ');
                }
                const duration = book.duration && book.duration !== '00:00:00' ? book.duration : '';
                return `
                                                            <a href="/books/${book.id}" class="text-decoration-none">
                                                                <div class="card h-100 book-card-hover">
                                                                    ${createGridMedia(book, title, 120)}
                                                                    <div class="card-body p-2">
                                                                    <p class="card-title small mb-0 text-truncate">${title}</p>
                                                                    <p class="card-text small mb-0" style="font-size: 0.75rem;">${author}</p>
                                                                    ${seriesText ? `<p class="card-text small text-muted mb-0" style="font-size: 0.7rem;">${seriesText}</p>` : ''}
                                                                    ${duration ? `<p class="card-text small text-muted mb-0" style="font-size: 0.7rem;"><i class="fas fa-clock"></i> ${duration}</p>` : ''}
                                                                </div>
                                                            </div>
                                                        </a>
                                                    `;
            }

            function createListRow(book) {
                // Fix cover URL to handle paths with slashes correctly
                const title = String(book.title || 'Unknown Title'); // Ensure title is a string
                const author = book.authors && book.authors.length > 0 ? book.authors.join(', ') : 'Unknown';
                const genre = book.genres && book.genres.length > 0 ? book.genres.join(', ') : 'Unknown';
                let seriesText = '';
                if (book.series && book.series.length > 0) {
                    seriesText = book.series.map(s => `${s.seriesName}${s.number ? ' (Book ' + s.number + ')' : ''}`).join(', ');
                }
                const duration = book.duration && book.duration !== '00:00:00' ? book.duration : '';
                return `
                                                        <td>
                                                            ${createListMedia(book, title)}
                                                        </td>
                                                        <td>
                                                            <a href="/books/${book.id}">${title}</a>
                                                            ${seriesText ? `<div class="small text-muted">${seriesText}</div>` : ''}
                                                        </td>
                                                        <td>${author}</td>
                                                        <td>${genre}</td>
                                                        <td class="text-muted small">${duration}</td>
                                                        <td>
                                                            <a href="/books/${book.id}/download" class="btn btn-sm btn-secondary">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                        </td>
                                                    `;
            }

            function initializeClickHandlers() {
                // Reinitialize click handlers for new book cards
                document.querySelectorAll('.book-card-hover').forEach(card => {
                    card.addEventListener('click', function () {
                        const link = this.closest('a').getAttribute('href');
                        if (link) {
                            window.location.href = link;
                        }
                    });
                });

                // Reinitialize click handlers for new table rows
                document.querySelectorAll('table.table-hover tbody tr').forEach(row => {
                    row.addEventListener('click', function (e) {
                        if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A' || e.target.closest('a') || e.target.closest('button')) {
                            return;
                        }

                        const link = this.querySelector('td a');
                        if (link) {
                            window.location.href = link.getAttribute('href');
                        }
                    });
                });
            }
        });
    </script>
@endpush
