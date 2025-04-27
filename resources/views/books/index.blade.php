@extends('layouts.app')

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
                    <span>Recently Added Books</span>
                    <button class="btn btn-link text-decoration-none" id="toggle-recent-books" type="button" tabindex="0">
                        <span id="recent-books-toggle-text">Hide</span>
                    </button>
                </div>
                <div class="card-body p-0" id="recent-books-block">
                    <div class="row m-0">
                        @foreach($recentBooks as $book)
                            <div class="col-md-4 mb-4">
                                <a href="{{ route('books.show', $book) }}" class="text-decoration-none card-link" style="color:inherit">
                                    <div class="card h-100 book-card-hover" style="cursor:pointer;">
                                        @php
                                            $cover = $book->cover_image ? route('image.proxy', ['file' => $book->cover_image]) : url('images/placeholder.png');
                                        @endphp
                                        <img src="{{ $cover }}"
                                             class="card-img-top book-cover-thumb" alt="{{ $book->title }}"
                                             style="height: 200px; width: auto; max-width: 100%; object-fit: contain; background: #f8f9fa; display: block; margin-left: auto; margin-right: auto;">
                                        <div class="card-body">
                                            <h5 class="card-title">{{ $book->title }}</h5>
                                            <p class="card-text">By {{ $book->author && $book->author->name ? $book->author->name : 'Unknown' }}</p>
                                            <p class="card-text"><strong>Date Added:</strong> {{ ($book->date_added instanceof \Carbon\Carbon) ? $book->date_added->format('Y-m-d') : ($book->date_added ? $book->date_added : 'N/A') }}</p>
                                            <p class="card-text"><strong>Publication Date:</strong>
                                                {{ ($book->publication_date instanceof \Carbon\Carbon) ? $book->publication_date->format('Y-m-d') : ($book->publication_date ? $book->publication_date : 'N/A') }}</p>
                                            <a href="{{ route('books.show', $book) }}" class="btn btn-primary d-none d-md-inline">View Details</a>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <hr />
        @endif

        <form action="{{ route('books.index') }}" method="GET">
            <div class="form-group">
                <label for="search">Search:</label>
                <input type="text" class="form-control" id="search" name="search" value="{{ request('search') }}">
            </div>

            <div class="form-group">
                <label for="genre_id">Genre:</label>
                <select class="form-control" id="genre_id" name="genre_id">
                    <option value="">All Genres</option>
                    @foreach ($genres as $genre)
                        <option value="{{ $genre->id }}" {{ request('genre_id') == $genre->id ? 'selected' : '' }}>
                            {{ $genre->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="author_id">Author:</label>
                <select class="form-control" id="author_id" name="author_id">
                    <option value="">All Authors</option>
                    @foreach ($authors as $author)
                        <option value="{{ $author->id }}" {{ request('author_id') == $author->id ? 'selected' : '' }}>
                            {{ $author->name }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="{{ route('books.index') }}" class="btn btn-secondary">Reset</a>
        </form>

        <div class="row">
            @foreach ($books as $book)
                <div class="col-md-4 mb-4">
                    <a href="{{ route('books.show', $book) }}" class="text-decoration-none card-link" style="color:inherit">
                        <div class="card h-100 book-card-hover" style="cursor:pointer;">
                            @php
                                $cover = $book->cover_image ? route('image.proxy', ['file' => $book->cover_image]) : url('images/placeholder.png');
                            @endphp
                            <img src="{{ $cover }}"
                                 class="card-img-top book-cover-thumb" alt="{{ $book->title }}"
                                 style="height: 200px; width: auto; max-width: 100%; object-fit: contain; background: #f8f9fa; display: block; margin-left: auto; margin-right: auto;">
                            <div class="card-body">
                                <h5 class="card-title">{{ $book->title }}</h5>
                                <p class="card-text">By {{ $book->author && $book->author->name ? $book->author->name : 'Unknown' }}</p>
                                <a href="{{ route('books.show', $book) }}" class="btn btn-primary d-none d-md-inline">View Details</a>
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>

        <div class="d-flex justify-content-center">
            <div class="pagination">
                {{ $books->links() }}
            </div>
        </div>

    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="/css/pagination-fix.css">
@endpush

@push('scripts')
<script>
    $(function() {
        var toggleBtns = $('#toggle-recent-books');
        var blocks = $('#recent-books-block');
        function setRecentBooksCollapsed(collapsed) {
            document.cookie = 'recentBooksCollapsed=' + (collapsed ? '1' : '0') + ';path=/;max-age=31536000';
        }
        function getRecentBooksCollapsed() {
            var match = document.cookie.match(/(?:^|; )recentBooksCollapsed=([^;]*)/);
            return match ? match[1] === '1' : false;
        }
        function updateRecentBooksBlock() {
            var collapsed = getRecentBooksCollapsed();
            var block = $('#recent-books-block');
            var toggleText = $('#recent-books-toggle-text');
            if (block.length && toggleText.length) {
                block.css('display', collapsed ? 'none' : '');
                toggleText.text(collapsed ? 'Show' : 'Hide');
            }
        }
        updateRecentBooksBlock();
        toggleBtns.on('click', function(e) {
            e.preventDefault();
            var collapsed = !getRecentBooksCollapsed();
            setRecentBooksCollapsed(collapsed);
            updateRecentBooksBlock();
        });
    });
</script>
@endpush
