@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Manage Authors</h1>

        @include('components.ai-query-prompt')

        <form action="{{ route('admin.authors.index') }}" method="GET" class="mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" id="search" class="form-control" placeholder="Search author name" name="search"
                        value="{{ $search }}">
                </div>
                <div class="col-md-3">
                    <label for="sort" class="form-label">Sort by</label>
                    <select name="sort" id="sort" class="form-select">
                        <option value="name" {{ $sort === 'name' ? 'selected' : '' }}>Name</option>
                        <option value="books" {{ $sort === 'books' ? 'selected' : '' }}>Book count</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="direction" class="form-label">Direction</label>
                    <select name="direction" id="direction" class="form-select">
                        <option value="asc" {{ $direction === 'asc' ? 'selected' : '' }}>Ascending</option>
                        <option value="desc" {{ $direction === 'desc' ? 'selected' : '' }}>Descending</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-outline-secondary" type="submit">Apply</button>
                </div>
            </div>
        </form>

        <a href="{{ route('admin.authors.create') }}" class="btn btn-primary mb-3">Add New Author</a>

        <form action="{{ route('admin.authors.merge') }}" method="POST">
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
                    @foreach($authors as $author)
                        <tr class="{{ $loop->iteration % 2 == 0 ? 'table-secondary' : '' }}">
                            <td>
                                <input type="checkbox" name="author_ids[]" value="{{ $author['id'] }}">
                            </td>
                            <td>
                                <div>{{ $author['name'] }}</div>
                                <div class="text-muted small">{{ $author['bookCount'] ?? 0 }} book(s)</div>
                            </td>
                            <td>
                                <a href="{{ route('admin.books.create') }}?author_id={{ $author['id'] }}"
                                    class="btn btn-sm btn-outline-success" title="Add Book"><i
                                        class="fas fa-plus-circle"></i></a>
                                <a href="{{ route('admin.authors.edit', $author['id']) }}"
                                    class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pencil-alt"></i></a>
                                <a href="{{ route('admin.authors.browse', $author['id']) }}"
                                    class="btn btn-sm btn-outline-secondary" title="Browse"><i class="fas fa-sitemap"></i></a>
                                <form action="{{ route('admin.authors.destroy', $author['id']) }}" method="POST"
                                    style="display: inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                        onclick="return confirm('Are you sure you want to delete author &quot;{{ $author['name'] }}&quot;?')"><i
                                            class="fas fa-trash-alt"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="row align-items-end mb-3">
                <div class="col-md-4">
                    <label for="primary_author_id" class="form-label">Primary author</label>
                    <select name="primary_author_id" id="primary_author_id" class="form-select">
                        @foreach($authors as $authorOption)
                            <option value="{{ $authorOption['id'] }}">{{ $authorOption['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-danger mt-3">
                        <i class="fas fa-users-cog"></i> Merge Selected Authors
                    </button>
                </div>
            </div>
        </form>

        {{-- Simple pagination removed as we're not using Laravel's paginator --}}
        @if(count($authors) === 0)
            <div class="alert alert-info">No authors found.</div>
        @endif

    </div>
@endsection
