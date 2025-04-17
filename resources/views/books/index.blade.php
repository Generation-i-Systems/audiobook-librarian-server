@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Book Archive</h1>

        @php
            $showFilters = request()->has('search') || request()->has('genre_id') || request()->has('author_id') || request()->has('series');
        @endphp

        <!-- Show Recently Added Books Only If There Are No Filters And There Are Recent Books -->
        @if (!$showFilters && count($recentBooks) > 0)
            <h2>Recently Added Books</h2>
            <div class="row">
                @foreach($recentBooks as $book)
                    <div class="col-md-4 mb-4">
                        <div class="card">
                            <img src="{{ Storage::url($book->cover_image) }}" class="card-img-top" alt="{{ $book->title }}"
                                style="max-height: 200px; object-fit: cover;">
                            <div class="card-body">
                                <h5 class="card-title">{{ $book->title }}</h5>
                                <p class="card-text">By {{ $book->author->name }}</p>
                                <p class="card-text"><strong>Date Added:</strong> {{ $book->date_added->format('Y-m-d') }}</p>
                                <p class="card-text"><strong>Publication Date:</strong>
                                    {{ $book->publication_date ? $book->publication_date->format('Y-m-d') : 'N/A' }}</p>
                                <a href="{{ route('books.show', $book) }}" class="btn btn-primary">View Details</a>
                            </div>
                        </div>
                    </div>
                @endforeach
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
                    <div class="card">
                        <img src="{{ Storage::url($book->cover_image) }}" class="card-img-top" alt="{{ $book->title }}"
                            style="max-height: 200px; object-fit: cover;">
                        <div class="card-body">
                            <h5 class="card-title">{{ $book->title }}</h5>
                            <p class="card-text">By {{ $book->author->name }}</p>
                            <a href="{{ route('books.show', $book) }}" class="btn btn-primary">View Details</a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{ $books->links() }} <!-- Pagination links -->

    </div>
@endsection
