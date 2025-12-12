@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Authors in Genre</h1>

        <div class="mb-3">
            <h2 class="h4 mb-1">{{ $hierarchy['genre']['name'] ?? '' }}</h2>
        </div>

        <form action="{{ route('admin.genres.authors', $hierarchy['genre']['id']) }}" method="GET" class="mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="sort" class="form-label">Sort by</label>
                    <select name="sort" id="sort" class="form-select">
                        <option value="name" {{ ($sort ?? 'name') === 'name' ? 'selected' : '' }}>Name</option>
                        <option value="books" {{ ($sort ?? 'name') === 'books' ? 'selected' : '' }}>Book count</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="direction" class="form-label">Direction</label>
                    <select name="direction" id="direction" class="form-select">
                        <option value="asc" {{ ($direction ?? 'asc') === 'asc' ? 'selected' : '' }}>Ascending</option>
                        <option value="desc" {{ ($direction ?? 'asc') === 'desc' ? 'selected' : '' }}>Descending</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-outline-secondary" type="submit">Apply</button>
                </div>
            </div>
        </form>

        @if(!empty($hierarchy['authors']))
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Books</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($hierarchy['authors'] as $author)
                        <tr>
                            <td>{{ $author['name'] }}</td>
                            <td>{{ $author['bookCount'] ?? 0 }}</td>
                            <td>
                                <a href="{{ route('admin.authors.browse', [$author['id'], 'genre_id' => $hierarchy['genre']['id'] ?? null]) }}"
                                    class="btn btn-sm btn-outline-secondary">Browse</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-muted">No authors found for this genre.</p>
        @endif

        <a href="{{ route('admin.genres.index') }}" class="btn btn-secondary">Back to Genres</a>
    </div>
@endsection
