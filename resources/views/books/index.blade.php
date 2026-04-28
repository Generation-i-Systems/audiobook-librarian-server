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
        <h1>Book Archive</h1>

        @php
$showFilters = request()->has('search') || request()->has('genre_id') || request()->has('author_id') || request()->has('series');
$isLibrivoxMode = $isLibrivoxMode ?? false;
        @endphp

        <!-- Show Recently Added Books Only If There Are No Filters And There Are Recent Books -->
        @if (!$showFilters && count($recentBooks) > 0)
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent Books</h5>
                    @if(!$isLibrivoxMode)
                        <div class="btn-group" role="group" aria-label="View options">
                            <button class="btn btn-sm btn-outline-secondary view-toggle-btn active" id="recent-grid-btn"
                                data-view="grid"><i class="fas fa-th"></i> Grid</button>
                            <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="recent-compact-btn"
                                data-view="compact"><i class="fas fa-th-large"></i> Compact</button>
                            <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="recent-list-btn"
                                data-view="list"><i class="fas fa-list"></i> List</button>
                        </div>
                    @endif
                    <button class="btn btn-link text-decoration-none" id="toggle-recent-books" type="button" tabindex="0">
                        <span id="recent-books-toggle-text">Hide</span>
                    </button>
                </div>
                <div class="card-body p-0" id="recent-books-block">
                    <!-- Grid View (Default) -->
                    <div class="row g-2" id="recent-books-grid" style="display: flex; flex-wrap: wrap;">
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
                                                <span class="book-media-panel__meta">Cover unavailable</span>
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
                    <div class="row g-2" id="recent-books-compact" style="display: none; flex-wrap: wrap;">
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
                    <div class="table-responsive" id="recent-books-list" style="display: none;">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">Cover</th>
                                    <th>Title</th>
                                    <th>Author</th>
                                    <th style="width: 100px;">Actions</th>
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

        <form action="{{ route('books.index') }}" method="GET" class="mb-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="search" class="form-label">Search:</label>
                    <input type="text" class="form-control" id="search" name="search" value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <label for="genre" class="form-label">Genre:</label>
                    <select name="genre" id="genre" class="form-select">
                        <option value="">All Genres</option>
                        @foreach ($genres as $genreId => $genreName)
                            <option value="{{ $genreName }}" {{ request('genre') == $genreName ? 'selected' : '' }}>
                                {{ $genreName }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="author" class="form-label">Author:</label>
                    <input type="text" class="form-control autocomplete-author" id="author" name="author" 
                           value="{{ request('author') }}" placeholder="Type to search authors...">
                </div>
                <div class="col-md-2">
                    <label for="series" class="form-label">Series:</label>
                    <input type="text" class="form-control" id="series" name="series" value="{{ request('series') }}"
                        placeholder="Filter by series">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary me-2">Filter</button>
                    <a href="{{ route('books.index') }}" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>

        @if(!$isLibrivoxMode)
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="btn-group" role="group" aria-label="View options">
                    <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="main-grid-btn" data-view="grid"><i
                            class="fas fa-th"></i> Grid</button>
                    <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="main-compact-btn"
                        data-view="compact"><i class="fas fa-th-large"></i> Compact</button>
                    <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="main-list-btn" data-view="list"><i
                            class="fas fa-list"></i> List</button>
                </div>
            </div>
        @endif

        <div class="per-page-controls">
            <div class="dropdown">
                <button class="btn btn-secondary dropdown-toggle" type="button" id="perPageDropdown"
                    data-bs-toggle="dropdown" aria-expanded="false">
                    <span id="current-per-page">24</span> per page
                </button>
                <ul class="dropdown-menu" aria-labelledby="perPageDropdown">
                    <li><a class="dropdown-item per-page-option" href="#" data-per-page="24">24</a></li>
                    <li><a class="dropdown-item per-page-option" href="#" data-per-page="36">36</a></li>
                    <li><a class="dropdown-item per-page-option" href="#" data-per-page="48">48</a></li>
                    <li><a class="dropdown-item per-page-option" href="#" data-per-page="72">72</a></li>
                    <li><a class="dropdown-item per-page-option" href="#" data-per-page="96">96</a></li>
                </ul>
            </div>
        </div>

        <!-- Main Books Container -->
        <div id="main-books-container">
            <!-- Loading Spinner -->
            <div class="loading-spinner" id="main-loading-spinner">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading books...</p>
            </div>

            <!-- Grid View -->
            <div class="row" id="main-books-grid" style="display: none;">
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
                        <div class="col-md-3 mb-4">
                            <a href="{{ route('books.show', $book['id']) }}" class="text-decoration-none card-link" style="color:inherit">
                                <div class="card h-100 book-card-hover" style="cursor:pointer;">
                                    @if($hasCover)
                                        <div class="book-media-panel pt-3">
                                            <img src="{{ $cover }}" class="card-img-top book-cover-thumb" alt="{{ $title }}"
                                                 style="height: 160px; width: auto; max-width: 100%; object-fit: contain; display: block; margin-left: auto; margin-right: auto;">
                                        </div>
                                    @else
                                        <div class="book-media-panel book-media-panel--coverless" data-source="{{ strtolower($sourceLabel) }}">
                                            <span class="book-media-panel__badge">{{ $sourceLabel }}</span>
                                            <i class="fas fa-headphones-alt book-media-panel__icon" aria-hidden="true"></i>
                                            <span class="book-media-panel__meta">Cover unavailable</span>
                                        </div>
                                    @endif
                                    <div class="card-body p-2">
                                        <h6 class="card-title small mb-0">{{ $title }}</h6>
                                        <p class="card-text small mb-0">{{ $authors }}</p>
                                        @if($seriesText)
                                            <p class="card-text small text-muted mb-0" style="font-size: 0.75rem;">{{ $seriesText }}</p>
                                        @endif
                                        @if(!empty($book['duration']) && $book['duration'] !== '00:00:00')
                                            <p class="card-text small text-muted mb-0" style="font-size: 0.75rem;"><i class="fas fa-clock"></i> {{ $book['duration'] }}</p>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        </div>
                    @endforeach
                @endif
            </div>

            <!-- Compact View -->
            <div class="row" id="main-books-compact" style="display: none;">
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
                        <div class="col-md-2 mb-3">
                            <a href="{{ route('books.show', $book['id']) }}" class="text-decoration-none card-link" style="color:inherit">
                                <div class="card h-100 book-card-hover" style="cursor:pointer;">
                                    @if($hasCover)
                                        <div class="book-media-panel book-media-panel--compact pt-2">
                                            <img src="{{ $cover }}" class="card-img-top book-cover-thumb" alt="{{ $title }}"
                                                 style="height: 120px; width: auto; max-width: 100%; object-fit: contain; display: block; margin-left: auto; margin-right: auto;">
                                        </div>
                                    @else
                                        <div class="book-media-panel book-media-panel--compact book-media-panel--coverless" data-source="{{ strtolower($sourceLabel) }}">
                                            <span class="book-media-panel__badge">{{ $sourceLabel }}</span>
                                            <i class="fas fa-headphones-alt book-media-panel__icon" aria-hidden="true"></i>
                                            <span class="book-media-panel__meta">Cover unavailable</span>
                                        </div>
                                    @endif
                                    <div class="card-body p-2">
                                        <h6 class="card-title small mb-0" style="font-size: 0.8rem;">{{ $title }}</h6>
                                        <p class="card-text small mb-0" style="font-size: 0.75rem;">{{ $authors }}</p>
                                        @if($seriesText)
                                            <p class="card-text small text-muted mb-0" style="font-size: 0.7rem;">{{ $seriesText }}</p>
                                        @endif
                                        @if(!empty($book['duration']) && $book['duration'] !== '00:00:00')
                                            <p class="card-text small text-muted mb-0" style="font-size: 0.7rem;"><i class="fas fa-clock"></i> {{ $book['duration'] }}</p>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        </div>
                    @endforeach
                @endif
            </div>

            <!-- List View -->
            <div class="table-responsive" id="main-books-list" style="display: none;">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Cover</th>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Genre</th>
                            <th style="width: 90px;">Length</th>
                            <th style="width: 100px;">Actions</th>
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
                                    $authors = !empty($book['authors']) && is_array($book['authors']) ? implode(', ', $book['authors']) : 'Unknown';
                                    $genres = !empty($book['genres']) && is_array($book['genres']) ? implode(', ', $book['genres']) : 'Unknown';
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
                                        @if($seriesText)
                                            <div class="small text-muted">{{ $seriesText }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $authors }}</td>
                                    <td>{{ $genres }}</td>
                                    <td class="text-muted small">{{ (!empty($book['duration']) && $book['duration'] !== '00:00:00') ? $book['duration'] : '' }}</td>
                                    <td>
                                        <a href="{{ route('books.download', $book['id']) }}" class="btn btn-sm btn-secondary"><i class="fas fa-download"></i></a>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <div class="pagination-container" id="main-pagination">
            <!-- Pagination will be generated here via JavaScript -->
        </div>

        <!-- Server-side pagination removed - now handled via JavaScript -->

    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="/css/pagination-fix.css">
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const librivoxListOnly = @json($isLibrivoxMode);

            // Toggle recent books visibility
            const toggleButton = document.getElementById('toggle-recent-books');
            const recentBooksBlock = document.getElementById('recent-books-block');
            const toggleText = document.getElementById('recent-books-toggle-text');

            if (toggleButton && recentBooksBlock) {
                toggleButton.addEventListener('click', function () {
                    if (recentBooksBlock.style.display === 'none') {
                        recentBooksBlock.style.display = 'block';
                        if (toggleText) toggleText.textContent = 'Hide';
                    } else {
                        recentBooksBlock.style.display = 'none';
                        if (toggleText) toggleText.textContent = 'Show';
                    }
                });
            }

            // Recent books view type toggle
            const recentViewButtons = document.querySelectorAll('.recent-view-type');
            const recentViews = document.querySelectorAll('.recent-view');

            // Initialize view - show grid view by default
            if (librivoxListOnly) {
                $('#recent-books-list').show();
                $('#recent-books-grid, #recent-books-compact').hide();
                $('#recent-list-btn').addClass('active').removeClass('btn-outline-secondary').addClass('btn-secondary');
            } else {
                $('#recent-books-grid').show();
                $('#recent-books-compact, #recent-books-list').hide();

                // Set active button for recent books view
                $('#recent-grid-btn').addClass('active').removeClass('btn-outline-secondary').addClass('btn-secondary');
                $('#recent-compact-btn, #recent-list-btn').removeClass('active').removeClass('btn-secondary').addClass('btn-outline-secondary');
            }

            // Variables for pagination and view state
            let currentMainPage = 1;
            let mainPerPage = 24;
            let mainViewType = librivoxListOnly ? 'list' : '{{ $mainViewType }}';

            // Route variables with placeholders
            const bookShowRoute = '{{ route("books.show", ":id") }}';
            const bookDownloadRoute = '{{ route("books.download", ":id") }}';
            let mainSearchParams = {
                "search": '{{ request()->input("search", "") }}',
                "genre": '{{ request()->input("genre", "") }}',
                "author": '{{ request()->input("author", "") }}',
                "series": '{{ request()->input("series", "") }}'
            };

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

            // Function to render main books based on current view type
            function renderMainBooks(books) {
                // Clear all containers
                $('#main-books-grid').empty();
                $('#main-books-compact').empty();
                $('#main-books-list tbody').empty();

                // Hide all containers first
                $('#main-books-grid, #main-books-compact, #main-books-list').hide();

                // Show the active container based on view type
                $('#main-books-' + mainViewType).show();

                // Log for debugging
                console.log('View type:', mainViewType);
                console.log('Books count:', books.length);

                if (books.length === 0) {
                    $('#main-books-' + mainViewType).html('<div class="alert alert-info">No books found matching your criteria.</div>');
                    return;
                }

                // Render books based on view type
                books.forEach(function (book) {
                    const title = book.title || 'Unknown Title';
                    const author = book.authors && book.authors.length > 0 ? book.authors.join(', ') : 'Unknown';

                    // Generate series text if available
                    let seriesText = '';
                    if (book.series && book.series.length > 0) {
                        seriesText = book.series.map(s => `${s.seriesName}${s.number ? ' (Book ' + s.number + ')' : ''}`).join(', ');
                    }

                    // Grid view (4 columns)
                    if (mainViewType === 'grid') {
                        $('#main-books-grid').append(`
                                                                <div class="col-md-3 mb-4">
                                                                    <a href="${bookShowRoute.replace(':id', book.id)}" class="text-decoration-none card-link" style="color:inherit">
                                                                        <div class="card h-100 book-card-hover" style="cursor:pointer;">
                                                                            ${createGridMedia(book, title, 160)}
                                                                            <div class="card-body p-2">
                                                                                <h6 class="card-title small mb-0">${title}</h6>
                                                                                <p class="card-text small mb-0">${author}</p>
                                                                                ${seriesText ? `<p class="card-text small text-muted mb-0" style="font-size: 0.75rem;">${seriesText}</p>` : ''}
                                                                            </div>
                                                                        </div>
                                                                    </a>
                                                                </div>
                                                            `);
                    }
                    // Compact view (6 columns)
                    else if (mainViewType === 'compact') {
                        $('#main-books-compact').append(`
                                                                <div class="col-md-2 mb-3">
                                                                    <a href="${bookShowRoute.replace(':id', book.id)}" class="text-decoration-none card-link" style="color:inherit">
                                                                        <div class="card h-100 book-card-hover" style="cursor:pointer;">
                                                                            ${createGridMedia(book, title, 120)}
                                                                            <div class="card-body p-2">
                                                                                <h6 class="card-title small mb-0" style="font-size: 0.8rem;">${title}</h6>
                                                                                <p class="card-text small mb-0" style="font-size: 0.75rem;">${author}</p>
                                                                                ${seriesText ? `<p class="card-text small text-muted mb-0" style="font-size: 0.7rem;">${seriesText}</p>` : ''}
                                                                            </div>
                                                                        </div>
                                                                    </a>
                                                                </div>
                                                            `);
                    }
                    // List view
                    else if (mainViewType === 'list') {
                        $('#main-books-list tbody').append(`
                                                                <tr>
                                                                    <td>
                                                                        ${createListMedia(book, title)}
                                                                    </td>
                                                                    <td>
                                                                        <a href="${bookShowRoute.replace(':id', book.id)}" class="text-decoration-none">${title}</a>
                                                                        ${seriesText ? `<div class="small text-muted">${seriesText}</div>` : ''}
                                                                    </td>
                                                                    <td>${book.author && book.author.length ? book.author.join(', ') : 'Unknown'}</td>
                                                                    <td>${book.genre && book.genre.length ? book.genre.join(', ') : 'Unknown'}</td>
                                                                    <td>
                                                                        <a href="${bookDownloadRoute.replace(':id', book.id)}" class="btn btn-sm btn-secondary"><i class="fas fa-download"></i></a>
                                                                    </td>
                                                                </tr>
                                                            `);
                    }
                });

                // Initialize click handlers for the new elements
                initializeClickHandlers();
            }

            // Function to render pagination controls
            function renderPagination(pagination) {
                const paginationContainer = $('#main-pagination');
                paginationContainer.empty();

                if (pagination.total <= pagination.per_page) {
                    return; // No pagination needed
                }

                const totalPages = Math.ceil(pagination.total / pagination.per_page);
                let html = '<ul class="pagination">';

                // Previous button
                const prevDisabled = pagination.current_page === 1 ? 'disabled' : '';
                html += '<li class="page-item ' + prevDisabled + '"><a class="page-link" href="#" data-page="' + (pagination.current_page - 1) + '">&laquo;</a></li>';

                // Page numbers with ellipsis
                const maxPagesToShow = 5;
                let startPage = Math.max(1, pagination.current_page - Math.floor(maxPagesToShow / 2));
                let endPage = Math.min(totalPages, startPage + maxPagesToShow - 1);

                if (endPage - startPage + 1 < maxPagesToShow) {
                    startPage = Math.max(1, endPage - maxPagesToShow + 1);
                }

                if (startPage > 1) {
                    html += '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                    if (startPage > 2) {
                        html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                }

                for (let i = startPage; i <= endPage; i++) {
                    const active = i === pagination.current_page ? 'active' : '';
                    html += '<li class="page-item ' + active + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
                }

                if (endPage < totalPages) {
                    if (endPage < totalPages - 1) {
                        html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    html += '<li class="page-item"><a class="page-link" href="#" data-page="' + totalPages + '">' + totalPages + '</a></li>';
                }

                // Next button
                const nextDisabled = pagination.current_page === totalPages ? 'disabled' : '';
                html += '<li class="page-item ' + nextDisabled + '"><a class="page-link" href="#" data-page="' + (pagination.current_page + 1) + '">&raquo;</a></li>';

                html += '</ul>';
                paginationContainer.html(html);

                // Handle pagination clicks
                paginationContainer.find('.page-link').on('click', function (e) {
                    e.preventDefault();
                    const page = $(this).data('page');
                    if (!$(this).parent().hasClass('disabled') && !$(this).parent().hasClass('active')) {
                        currentMainPage = page;
                        loadMainBooks();
                    }
                });
            }

            // Function to load main books via AJAX
            function loadMainBooks() {
                if (librivoxListOnly) {
                    mainViewType = 'list';
                }

                // Show loading spinner
                $('#main-loading-spinner').show();
                $('#main-books-grid, #main-books-compact, #main-books-list').hide();

                // Collect all search parameters
                const params = {
                    page: currentMainPage,
                    per_page: mainPerPage,
                    view_type: mainViewType,
                    request_type: 'main_books'
                };

                // Add search parameters if they exist
                if (mainSearchParams) {
                    Object.keys(mainSearchParams).forEach(key => {
                        params[key] = mainSearchParams[key];
                    });
                }

                // Make AJAX request
                console.log('Sending AJAX request with params:', params);

                // Convert params to query string
                const queryString = Object.keys(params)
                    .map(key => `${encodeURIComponent(key)}=${encodeURIComponent(params[key])}`)
                    .join('&');

                fetch(`{{ url('/api/books/json') }}?${queryString}`)
                    .then(response => response.json())
                    .then(function (response) {
                        // Hide loading spinner
                        $('#main-loading-spinner').hide();
                        console.log('AJAX response received:', response);

                        // Update view type if returned from server
                        if (response.view_type) {
                            mainViewType = response.view_type;
                            updateMainViewButtons();
                        }

                        // Render books and pagination
                        renderMainBooks(response.books);
                        renderPagination(response.pagination);
                    })
                    .catch(function (error) {
                        console.error('AJAX request failed:', error);
                        $('#main-loading-spinner').hide();
                        alert('Failed to load books. Please try again.');
                    });
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

                // Store preference in session
                $.post('{{ route("books.set-preference") }}', {
                    _token: '{{ csrf_token() }}',
                    key: 'main_view_type',
                    value: viewType
                });

                // Reload books with new view type
                loadMainBooks();
            });

            // Handle per-page dropdown
            $('.per-page-option').on('click', function (e) {
                e.preventDefault();
                const perPage = parseInt($(this).data('per-page'));

                // Update per page value
                mainPerPage = perPage;
                $('#current-per-page').text(perPage);

                // Reset to first page
                currentMainPage = 1;

                // Store preference in session
                $.post('{{ route("books.set-preference") }}', {
                    _token: '{{ csrf_token() }}',
                    key: 'main_per_page',
                    value: perPage
                });

                // Reload books with new per page value
                loadMainBooks();
            });

            // Handle search form submission
            $('form').on('submit', function (e) {
                e.preventDefault();

                // Collect search parameters
                mainSearchParams = {
                    "search": $('#search').val(),
                    "genre": $('#genre').val(),
                    "author": $('#author').val(),
                    "series": $('#series').val()
                };

                // Reset to first page
                currentMainPage = 1;

                // Load books with search parameters
                loadMainBooks();
            });

            // Initialize main books section
            $(document).ready(function () {
                // Set initial view type button state
                updateMainViewButtons();

                // Set initial per-page dropdown value
                const storedPerPage = '{{ session("main_per_page", "24") }}';
                if (storedPerPage) {
                    mainPerPage = parseInt(storedPerPage);
                    $('#current-per-page').text(mainPerPage);
                }

                // Load main books on page load
                loadMainBooks();

                // Debug initial view type
                console.log('Initial view type:', mainViewType);
                
                // Initialize autocomplete for authors
                $('.autocomplete-author').autocomplete({
                    source: function(request, response) {
                        $.ajax({
                            url: '{{ route("admin.books.autocomplete.authors") }}',
                            type: 'GET',
                            data: { term: request.term },
                            success: function(data) {
                                response(data);
                            }
                        });
                    },
                    minLength: 2,
                    select: function(event, ui) {
                        $(this).val(ui.item.value);
                    }
                });
                
                // Initialize autocomplete for series
                $('#series').autocomplete({
                    source: function(request, response) {
                        $.ajax({
                            url: '{{ route("admin.books.autocomplete.series") }}',
                            type: 'GET',
                            data: { query: request.term },
                            success: function(data) {
                                // Handle the series response format
                                if (data.data) {
                                    response(data.data);
                                } else {
                                    response(data);
                                }
                            }
                        });
                    },
                    minLength: 2,
                    select: function(event, ui) {
                        $(this).val(ui.item.value);
                    }
                });
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

                // Store preference in session
                $.post('{{ route("books.set-preference") }}', {
                    _token: '{{ csrf_token() }}',
                    key: 'recent_view_type',
                    value: viewType
                });
            });

            recentViewButtons.forEach(button => {
                button.addEventListener('click', function () {
                    const viewType = this.getAttribute('data-view');

                    // Update active button styling
                    recentViewButtons.forEach(btn => {
                        btn.classList.remove('active', 'btn-secondary');
                        btn.classList.add('btn-outline-secondary');
                    });
                    this.classList.add('active', 'btn-secondary');
                    this.classList.remove('btn-outline-secondary');

                    // Show selected view, hide others
                    recentViews.forEach(view => {
                        if (view.classList.contains('recent-' + viewType + '-view')) {
                            view.classList.remove('d-none');
                        } else {
                            view.classList.add('d-none');
                        }
                    });
                });
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
