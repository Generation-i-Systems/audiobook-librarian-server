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

    .table-striped > tbody > tr:nth-of-type(odd) {
        background-color: rgba(255, 0, 0, 0.005); /* Even lighter zebra striping */
    }

    .table-striped > tbody > tr:nth-of-type(even) {
        background-color: rgba(255, 0, 255, 1);
    }

    .load-more-btn {
        transition: all 0.2s ease;
    }

    .load-more-btn:hover {
        background-color: #0d6efd;
        color: white;
    }
</style>
@endpush

@section('content')
    <div class="container">
        <h1>Book Archive</h1>

        @php
            $showFilters = request()->has('search') || request()->has('genre_id') || request()->has('author_id') || request()->has('series');
        @endphp

        <!-- Show Recently Added Books Only If There Are No Filters And There Are Recent Books -->
        @if (!$showFilters && count($recentBooks) > 0)
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent Books</h5>
                    <div class="btn-group" role="group" aria-label="View options">
                        <button class="btn btn-sm btn-outline-secondary view-toggle-btn active" id="recent-grid-btn" data-view="grid"><i class="fas fa-th"></i> Grid</button>
                        <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="recent-compact-btn" data-view="compact"><i class="fas fa-th-large"></i> Compact</button>
                        <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="recent-list-btn" data-view="list"><i class="fas fa-list"></i> List</button>
                    </div>
                    <button class="btn btn-link text-decoration-none" id="toggle-recent-books" type="button" tabindex="0">
                        <span id="recent-books-toggle-text">Hide</span>
                    </button>
                </div>
                <div class="card-body p-0" id="recent-books-block">
                    <!-- Grid View (Default) -->
                    <div class="row g-2" id="recent-books-grid" style="display: flex; flex-wrap: wrap;">
                        @foreach($recentBooks as $book)
                            <div class="col-md-2 mb-4">
                                <a href="{{ route('books.show', $book['id']) }}" class="text-decoration-none card-link" style="color:inherit">
                                    <div class="card h-100 book-card-hover" style="cursor:pointer;">
                                        @php
                                            $cover = isset($book['coverImage']) && $book['coverImage'] ? url('/cover/' . $book['coverImage']) : url('images/placeholder.png');
                                        @endphp
                                        <div class="pt-3" style="background: #f8f9fa; border-top-left-radius: 0.375rem; border-top-right-radius: 0.375rem;">
                                            <img src="{{ $cover }}"
                                                 class="card-img-top book-cover-thumb" alt="{{ $book['title'] }}"
                                                 style="height: 160px; width: auto; max-width: 100%; object-fit: contain; display: block; margin-left: auto; margin-right: auto;">
                                        </div>
                                        <div class="card-body p-2">
                                            <h6 class="card-title small mb-0">{{ $book['title'] }}</h6>
                                            <p class="card-text small mb-0">{{ isset($book['author']) && is_array($book['author']) && !empty($book['author']) ? $book['author'][0] : 'Unknown' }}</p>
                                            @if(isset($book['series']) && !empty($book['series']))
                                                <p class="card-text small text-muted mb-0" style="font-size: 0.75rem;">
                                                    @if(is_array($book['series']) && isset($book['series'][0]['seriesName']))
                                                        @php
                                                            $seriesEntry = $book['series'][0];
                                                            $seriesName = $seriesEntry['seriesName'] ?? '';
                                                            $seriesNumber = $seriesEntry['number'] ?? '';
                                                        @endphp
                                                        {{ $seriesName }}@if($seriesNumber !== '') (Book {{ $seriesNumber }})@endif
                                                    @else
                                                        {{ $book['series'] }}
                                                        @if(isset($book['series_number']) && !empty($book['series_number']))
                                                            #{{ $book['series_number'] }}
                                                        @endif
                                                    @endif
                                                </p>
                                            @endif
                                            <p class="card-text small text-muted mb-0" style="font-size: 0.75rem;">
                                                Added: {{ isset($book['dateAdded']) ? date('M d, Y', strtotime($book['dateAdded'])) : 'N/A' }}
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
                            <div class="col-md-3 col-lg-2 mb-4">
                                <a href="{{ route('books.show', $book['id']) }}" class="text-decoration-none card-link" style="color:inherit">
                                    <div class="card h-100 book-card-hover" data-book-id="{{ $book['id'] }}">
                                        @php
                                            $cover = isset($book['coverImage']) && $book['coverImage'] ? url('/cover/' . $book['coverImage']) : url('images/placeholder.png');
                                        @endphp
                                        <div class="row g-0">
                                            <div class="col-4" style="background: #f8f9fa; border-top-left-radius: 0.375rem; border-bottom-left-radius: 0.375rem;">
                                                <img src="{{ $cover }}" class="img-fluid rounded-start" alt="{{ $book['title'] }}" style="height: 96px; width: auto; object-fit: contain;">
                                            </div>
                                            <div class="col-8">
                                                <div class="card-body p-2">
                                                    <h6 class="card-title mb-0">{{ $book['title'] }}</h6>
                                                    <p class="card-text small mb-0">{{ isset($book['author']) && is_array($book['author']) && !empty($book['author']) ? $book['author'][0] : 'Unknown' }}</p>
                                                    @if(isset($book['series']) && !empty($book['series']))
                                                        <p class="card-text small text-muted mb-0" style="font-size: 0.75rem;">
                                                            @if(is_array($book['series']) && count($book['series']) > 0)
                                                                @php
                                                                    $firstSeries = $book['series'][0];
                                                                    $seriesName = $firstSeries['seriesName'] ?? $firstSeries['name'] ?? '';
                                                                    $seriesNumber = $firstSeries['number'] ?? '';
                                                                @endphp
                                                                {{ $seriesName }}@if($seriesNumber !== '') (Book {{ $seriesNumber }})@endif
                                                            @else
                                                                {{ $book['series'] }}
                                                                @if(isset($book['series_number']) && !empty($book['series_number']))
                                                                    #{{ $book['series_number'] }}
                                                                @endif
                                                            @endif
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
                                                $cover = isset($book['coverImage']) && $book['coverImage'] ? url('/cover/' . $book['coverImage']) : url('images/placeholder.png');
                                            @endphp
                                            <img src="{{ $cover }}" alt="{{ $book['title'] }}" style="height: 64px; width: auto; max-width: 48px; object-fit: contain;">
                                        </td>
                                        <td>
                                            <a href="{{ route('books.show', $book['id']) }}" class="text-decoration-none">{{ $book['title'] }}</a>
                                            @if(isset($book['series']) && !empty($book['series']))
                                                <div class="small text-muted">
                                                    @if(is_array($book['series']) && isset($book['series'][0]['seriesName']))
                                                        @php
                                                            $seriesEntry = $book['series'][0];
                                                            $seriesName = $seriesEntry['seriesName'] ?? '';
                                                            $seriesNumber = $seriesEntry['number'] ?? '';
                                                        @endphp
                                                        {{ $seriesName }}@if($seriesNumber !== '') (Book {{ $seriesNumber }})@endif
                                                    @else
                                                        {{ $book['series'] }}
                                                        @if(isset($book['series_number']) && !empty($book['series_number']))
                                                            #{{ $book['series_number'] }}
                                                        @endif
                                                    @endif
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            {{ isset($book['author']) && is_array($book['author']) && !empty($book['author']) ? $book['author'][0] : 'Unknown' }}
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="{{ route('books.show', $book['id']) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                                                <a href="{{ route('books.download', $book['id']) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-download"></i></a>
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
                    <label for="genre_id" class="form-label">Genre:</label>
                    <select name="genre_id" id="genre_id" class="form-select">
                        <option value="">All Genres</option>
                        @foreach ($genres as $genreId => $genreName)
                            <option value="{{ $genreId }}" {{ request('genre_id') == $genreId ? 'selected' : '' }}>
                                {{ $genreName }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="author_id" class="form-label">Author:</label>
                    <select name="author_id" id="author_id" class="form-select">
                        <option value="">All Authors</option>
                        @foreach ($authors as $authorId => $authorName)
                            <option value="{{ $authorId }}" {{ request('author_id') == $authorId ? 'selected' : '' }}>
                                {{ $authorName }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="series" class="form-label">Series:</label>
                    <input type="text" class="form-control" id="series" name="series" value="{{ request('series') }}" placeholder="Filter by series">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary me-2">Filter</button>
                    <a href="{{ route('books.index') }}" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="btn-group" role="group" aria-label="View options">
                <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="main-grid-btn" data-view="grid"><i class="fas fa-th"></i> Grid</button>
                <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="main-compact-btn" data-view="compact"><i class="fas fa-th-large"></i> Compact</button>
                <button class="btn btn-sm btn-outline-secondary view-toggle-btn" id="main-list-btn" data-view="list"><i class="fas fa-list"></i> List</button>
            </div>
        </div>

        <div class="per-page-controls">
            <div class="dropdown">
                <button class="btn btn-secondary dropdown-toggle" type="button" id="perPageDropdown" data-bs-toggle="dropdown" aria-expanded="false">
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
                <!-- Books will be loaded here via JavaScript -->
            </div>

            <!-- Compact View -->
            <div class="row" id="main-books-compact" style="display: none;">
                <!-- Books will be loaded here via JavaScript -->
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
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Books will be loaded here via JavaScript -->
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
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle recent books visibility
        const toggleButton = document.getElementById('toggle-recent-books');
        const recentBooksBlock = document.getElementById('recent-books-block');
        const toggleText = document.getElementById('recent-books-toggle-text');

        toggleButton.addEventListener('click', function() {
            if (recentBooksBlock.style.display === 'none') {
                recentBooksBlock.style.display = 'block';
                toggleText.textContent = 'Hide';
            } else {
                recentBooksBlock.style.display = 'none';
                toggleText.textContent = 'Show';
            }
        });

        // Recent books view type toggle
        const recentViewButtons = document.querySelectorAll('.recent-view-type');
        const recentViews = document.querySelectorAll('.recent-view');

        // Initialize view - show grid view by default
        $('#recent-books-grid').show();
        $('#recent-books-compact, #recent-books-list').hide();

        // Set active button for recent books view
        $('#recent-grid-btn').addClass('active').removeClass('btn-outline-secondary').addClass('btn-secondary');
        $('#recent-compact-btn, #recent-list-btn').removeClass('active').removeClass('btn-secondary').addClass('btn-outline-secondary');

        // Variables for pagination and view state
        let currentMainPage = 1;
        let mainPerPage = 24;
        let mainViewType = '{{ session("main_view_type", "grid") }}';

        // Route variables with placeholders
        const bookShowRoute = '{{ route("books.show", ":id") }}';
        const bookDownloadRoute = '{{ route("books.download", ":id") }}';
        let mainSearchParams = {
            "search": '{{ request()->input("search", "") }}',
            "genre_id": '{{ request()->input("genre_id", "") }}',
            "author_id": '{{ request()->input("author_id", "") }}',
            "series": '{{ request()->input("series", "") }}'
        };

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
            books.forEach(function(book) {
                // Fix cover URL to handle paths with slashes correctly
                const cover = book.coverImage ? '{{ url("/cover") }}/' + book.coverImage : '{{ url("images/placeholder.png") }}';
                const title = book.title || 'Unknown Title';
                const author = book.author && book.author.length > 0 ? book.author[0] : 'Unknown';

                // Generate series text if available
                let seriesText = '';
                if (book.series) {
                    if (typeof book.series === 'object') {
                        const firstSeries = Object.keys(book.series)[0];
                        const seriesNumber = book.series[firstSeries];
                        seriesText = firstSeries + (seriesNumber ? ' #' + seriesNumber : '');
                    } else {
                        seriesText = book.series + (book.series_number ? ' #' + book.series_number : '');
                    }
                }

                // Grid view (4 columns)
                if (mainViewType === 'grid') {
                    $('#main-books-grid').append(`
                        <div class="col-md-3 mb-4">
                            <a href="${bookShowRoute.replace(':id', book.id)}" class="text-decoration-none card-link" style="color:inherit">
                                <div class="card h-100 book-card-hover" style="cursor:pointer;">
                                    <div class="pt-3" style="background: #f8f9fa; border-top-left-radius: 0.375rem; border-top-right-radius: 0.375rem;">
                                        <img src="${cover}" class="card-img-top book-cover-thumb" alt="${title}"
                                             style="height: 160px; width: auto; max-width: 100%; object-fit: contain; display: block; margin-left: auto; margin-right: auto;">
                                    </div>
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
                                    <div class="pt-2" style="background: #f8f9fa; border-top-left-radius: 0.375rem; border-top-right-radius: 0.375rem;">
                                        <img src="${cover}" class="card-img-top book-cover-thumb" alt="${title}"
                                             style="height: 120px; width: auto; max-width: 100%; object-fit: contain; display: block; margin-left: auto; margin-right: auto;">
                                    </div>
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
                                <img src="${cover}" alt="${title}" style="height: 64px; width: auto; max-width: 64px; object-fit: contain;">
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
            paginationContainer.find('.page-link').on('click', function(e) {
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
                .then(function(response) {
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
            .catch(function(error) {
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
        $('#main-grid-btn, #main-compact-btn, #main-list-btn').on('click', function() {
            const viewType = $(this).data('view');

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
        $('.per-page-option').on('click', function(e) {
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
        $('form').on('submit', function(e) {
            e.preventDefault();

            // Collect search parameters
            mainSearchParams = {
                "search": $('#search').val(),
                "genre_id": $('#genre_id').val(),
                "author_id": $('#author_id').val(),
                "series": $('#series').val()
            };

            // Reset to first page
            currentMainPage = 1;

            // Load books with search parameters
            loadMainBooks();
        });

        // Initialize main books section
        $(document).ready(function() {
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
        });

        // Handle recent books view toggle
        $('#recent-grid-btn, #recent-compact-btn, #recent-list-btn').on('click', function() {
            const viewType = $(this).data('view');

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
            button.addEventListener('click', function() {
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
            card.addEventListener('click', function() {
                const link = this.closest('a').getAttribute('href');
                if (link) {
                    window.location.href = link;
                }
            });
        });

        // Add click handlers to table rows
        const bookRows = document.querySelectorAll('table.table-hover tbody tr');
        bookRows.forEach(row => {
            row.addEventListener('click', function(e) {
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

        loadMoreButton.addEventListener('click', function() {
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
            const cover = book.coverImage ? '/cover/' + book.coverImage : '/images/placeholder.png';
            return `
                <a href="/books/${book.id}" class="text-decoration-none">
                    <div class="card h-100 book-card-hover">
                        <img src="${cover}" class="card-img-top book-cover-thumb" alt="${book.title}"
                             style="height: 150px; width: auto; max-width: 100%; object-fit: contain; background: #f8f9fa; display: block; margin-left: auto; margin-right: auto; padding-top: 15px;">
                        <div class="card-body p-2">
                            <h6 class="card-title small mb-0">${book.title}</h6>
                            <p class="card-text small text-muted">${book.author && book.author.length ? book.author[0] : 'Unknown'}</p>
                        </div>
                    </div>
                </a>
            `;
        }

        function createCompactCard(book) {
            // Fix cover URL to handle paths with slashes correctly
            const cover = book.coverImage ? '/cover/' + book.coverImage : '/images/placeholder.png';
            return `
                <a href="/books/${book.id}" class="text-decoration-none">
                    <div class="card h-100 book-card-hover">
                        <img src="${cover}" class="card-img-top book-cover-thumb" alt="${book.title}"
                             style="height: 120px; width: auto; max-width: 100%; object-fit: contain; background: #f8f9fa; display: block; margin-left: auto; margin-right: auto; padding-top: 10px;">
                        <div class="card-body p-2">
                            <p class="card-title small mb-0 text-truncate">${book.title}</p>
                        </div>
                    </div>
                </a>
            `;
        }

        function createListRow(book) {
            // Fix cover URL to handle paths with slashes correctly
            const cover = book.coverImage ? '/cover/' + book.coverImage : '/images/placeholder.png';
            return `
                <td>
                    <img src="${cover}" alt="${book.title}" style="height: 48px; width: auto; object-fit: contain;">
                </td>
                <td><a href="/books/${book.id}">${book.title}</a></td>
                <td>${book.author && book.author.length ? book.author.join(', ') : 'Unknown'}</td>
                <td>${book.genre && book.genre.length ? book.genre.join(', ') : 'Unknown'}</td>
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
                card.addEventListener('click', function() {
                    const link = this.closest('a').getAttribute('href');
                    if (link) {
                        window.location.href = link;
                    }
                });
            });

            // Reinitialize click handlers for new table rows
            document.querySelectorAll('table.table-hover tbody tr').forEach(row => {
                row.addEventListener('click', function(e) {
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
