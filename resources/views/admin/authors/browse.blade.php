@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Author Hierarchy</h1>

        <div class="mb-3">
            <h2 class="h4 mb-1">{{ $hierarchy['author']['name'] ?? '' }}</h2>
            @if(!empty($hierarchy['genre']))
                <div class="text-muted">Filtered by genre: {{ $hierarchy['genre']['name'] }}</div>
            @endif
        </div>

        <form action="{{ route('admin.authors.browse', $hierarchy['author']['id']) }}" method="GET" class="mb-3">
            @if(!empty($hierarchy['genre']['id']))
                <input type="hidden" name="genre_id" value="{{ $hierarchy['genre']['id'] }}">
            @endif
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="sort" class="form-label">Sort series by</label>
                    <select name="sort" id="sort" class="form-select">
                        <option value="series_name" {{ ($sort ?? 'series_name') === 'series_name' ? 'selected' : '' }}>Name
                        </option>
                        <option value="series_books" {{ ($sort ?? 'series_name') === 'series_books' ? 'selected' : '' }}>Book
                            count</option>
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

        <div class="row">
            <div class="col-md-6 mb-4">
                <h3 class="h5">Series</h3>
                @if(!empty($hierarchy['series']))
                    <ul class="list-group">
                        @foreach($hierarchy['series'] as $series)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div>{{ $series['name'] }}</div>
                                    <div class="text-muted small">{{ $series['bookCount'] ?? 0 }} book(s)</div>
                                </div>
                                <a href="{{ route('admin.books.index', ['author' => $hierarchy['author']['name'] ?? null, 'series' => $series['name']]) }}"
                                    class="btn btn-sm btn-outline-secondary">View Books</a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-muted">No series found for this
                        author{{ !empty($hierarchy['genre']) ? ' in this genre' : '' }}.</p>
                @endif
            </div>

            <div class="col-md-6 mb-4">
                <h3 class="h5">Standalone Books</h3>
                @if(!empty($hierarchy['standaloneBooks']))
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Directory</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($hierarchy['standaloneBooks'] as $book)
                                <tr>
                                    <td>{{ $book['title'] }}</td>
                                    <td><small>{{ $book['directoryPath'] ?? '' }}</small></td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.books.edit', $book['id']) }}"
                                            class="btn btn-sm btn-outline-primary">Edit</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-muted">No standalone books found for this
                        author{{ !empty($hierarchy['genre']) ? ' in this genre' : '' }}.</p>
                @endif
            </div>
        </div>

        <a href="{{ route('admin.authors.index') }}" class="btn btn-secondary">Back to Authors</a>
    </div>
@endsection
