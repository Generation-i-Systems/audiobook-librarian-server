@extends('layouts.app')

@section('content')
<div class="container">
    <div class="alert alert-warning mb-4">
        <strong>Emergency Mode Active</strong> - Using optimized book loading to prevent memory issues.
    </div>

    <h1>Books</h1>

    <!-- Search Form -->
    <form action="{{ route('emergency.books.index') }}" method="GET" class="mb-4">
        <div class="row">
            <div class="col-md-6">
                <input type="text" 
                       class="form-control" 
                       placeholder="Search books..." 
                       name="search" 
                       value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="author" class="form-control">
                    <option value="">All Authors</option>
                    @foreach($authors as $author)
                        <option value="{{ $author }}" {{ request('author') == $author ? 'selected' : '' }}>
                            {{ $author }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="{{ route('emergency.books.index') }}" class="btn btn-secondary">Clear</a>
            </div>
        </div>
    </form>

    <!-- Books List -->
    <div class="mb-3">
        <p class="text-muted">
            Showing {{ $books->count() }} of {{ $books->total() }} books
            (Page {{ $books->currentPage() }} of {{ $books->lastPage() }})
        </p>
    </div>

    <div class="row">
        @forelse($books as $book)
            <div class="col-md-4 col-sm-6 mb-4">
                <div class="card h-100">
                    <div class="row g-0 h-100">
                        <div class="col-4">
                            <img src="{{ $book['coverImage'] }}" 
                                 class="img-fluid rounded-start h-100" 
                                 alt="Cover" 
                                 style="object-fit: cover; max-height: 200px;">
                        </div>
                        <div class="col-8">
                            <div class="card-body d-flex flex-column h-100">
                                <h6 class="card-title">{{ $book['title'] }}</h6>
                                <p class="card-text text-muted small mb-2">
                                    @if(!empty($book['author']))
                                        <strong>Author:</strong> {{ implode(', ', array_slice($book['author'], 0, 2)) }}
                                        @if(count($book['author']) > 2)
                                            <span class="text-muted">+{{ count($book['author']) - 2 }} more</span>
                                        @endif
                                        <br>
                                    @endif
                                    @if(!empty($book['genre']))
                                        <strong>Genre:</strong> {{ implode(', ', $book['genre']) }}
                                    @endif
                                </p>
                                <p class="card-text small flex-grow-1">
                                    {{ $book['description'] }}
                                </p>
                                <div class="mt-auto">
                                    <a href="{{ route('books.show', $book['id']) }}" 
                                       class="btn btn-sm btn-outline-primary">View Details</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="alert alert-info">
                    No books found. Try adjusting your search criteria.
                </div>
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-center">
        {{ $books->withQueryString()->links() }}
    </div>

    <!-- Recent Books Sidebar -->
    @if(!empty($recentBooks))
        <div class="mt-5">
            <h4>Recently Added</h4>
            <div class="row">
                @foreach($recentBooks as $book)
                    <div class="col-md-4 mb-3">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">{{ $book['title'] }}</h6>
                                <p class="card-text small text-muted">
                                    {{ implode(', ', $book['author']) }}
                                </p>
                                <a href="{{ route('books.show', $book['id']) }}" 
                                   class="btn btn-sm btn-outline-secondary">View</a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection