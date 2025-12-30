@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Genres</h1>

        @include('components.ai-query-prompt')

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        <form action="{{ route('admin.genres.index') }}" method="GET" class="mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="sort" class="form-label">Sort by</label>
                    <select name="sort" id="sort" class="form-select">
                        <option value="name" {{ ($sort ?? 'name') === 'name' ? 'selected' : '' }}>Name</option>
                        <option value="books" {{ ($sort ?? 'name') === 'books' ? 'selected' : '' }}>Book count</option>
                        <option value="authors" {{ ($sort ?? 'name') === 'authors' ? 'selected' : '' }}>Author count</option>
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

        <a href="{{ route('admin.genres.create') }}" class="btn btn-primary mb-3">Create New Genre</a>

        <form action="{{ route('admin.genres.merge') }}" method="POST">
            @csrf
            <table class="table">
                <thead>
                    <tr>
                        <th>Select</th>
                        <th>Name</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($genres as $genre)
                        <tr>
                            <td>
                                <input type="checkbox" name="genre_ids[]" value="{{ $genre['id'] }}">
                            </td>
                            <td>
                                <div>{{ $genre['name'] }}</div>
                                <div class="text-muted small">
                                    {{ $genre['bookCount'] ?? 0 }} book(s), {{ $genre['authorCount'] ?? 0 }} author(s)
                                </div>
                            </td>
                            <td>
                                <a href="{{ route('admin.genres.edit', $genre['id']) }}" class="btn btn-sm btn-outline-primary"
                                    title="Edit"><i class="fas fa-pencil-alt"></i></a>
                                <a href="{{ route('admin.genres.authors', $genre['id']) }}"
                                    class="btn btn-sm btn-outline-secondary" title="View Authors"><i
                                        class="fas fa-users"></i></a>
                                <form action="{{ route('admin.genres.destroy', $genre['id']) }}" method="POST"
                                    style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                        onclick="return confirm('Are you sure you want to delete genre &quot;{{ $genre['name'] }}&quot;?')"><i
                                            class="fas fa-trash-alt"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="row align-items-end mb-3">
                <div class="col-md-4">
                    <label for="primary_genre_id" class="form-label">Primary genre</label>
                    <select name="primary_genre_id" id="primary_genre_id" class="form-select">
                        @foreach($genres as $genreOption)
                            <option value="{{ $genreOption['id'] }}">{{ $genreOption['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-danger mt-3">
                        <i class="fas fa-layer-group"></i> Merge Selected Genres
                    </button>
                </div>
            </div>
        </form>
    </div>
@endsection
