@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Manage Books</h1>

        <form action="{{ route('admin.books.index') }}" method="GET">
            <div class="input-group mb-3">
                <input type="text" class="form-control" placeholder="Search title, author, or series" name="search"
                    value="{{ request('search') }}">
                <select name="sort" class="form-select ms-2" onchange="this.form.submit()">
                    <option value="recent_desc" {{ (request('sort', 'recent_desc') == 'recent_desc') ? 'selected' : '' }}>Most Recent</option>
                    <option value="recent_asc" {{ (request('sort') == 'recent_asc') ? 'selected' : '' }}>Oldest</option>
                    <option value="author_asc" {{ (request('sort') == 'author_asc') ? 'selected' : '' }}>Author A-Z</option>
                    <option value="author_desc" {{ (request('sort') == 'author_desc') ? 'selected' : '' }}>Author Z-A</option>
                    <option value="title_asc" {{ (request('sort') == 'title_asc') ? 'selected' : '' }}>Title A-Z</option>
                    <option value="title_desc" {{ (request('sort') == 'title_desc') ? 'selected' : '' }}>Title Z-A</option>
                    <option value="year_asc" {{ (request('sort') == 'year_asc') ? 'selected' : '' }}>Year Asc</option>
                    <option value="year_desc" {{ (request('sort') == 'year_desc') ? 'selected' : '' }}>Year Desc</option>
                </select>
                <button class="btn btn-outline-secondary" type="submit">Search</button>
            </div>
        </form>

        <div class="mb-3">
            <a href="{{ route('admin.books.create') }}" class="btn btn-primary">Add New Book</a>
            <a href="{{ route('admin.books.import') }}" class="btn btn-info">Import Book(s)</a>
            <a href="{{ route('admin.authors.index') }}" class="btn btn-outline-secondary ms-2">Manage Authors</a>
            <a href="{{ route('admin.genres.index') }}" class="btn btn-outline-secondary ms-2">Manage Genres</a>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th style="width: 56px;"></th>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Series</th>
                    <th>Genre</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($books as $book)
                    <tr class="{{ $loop->iteration % 2 == 0 ? 'table-secondary' : '' }}">
                        <td style="vertical-align: middle; text-align: center;">
                            @php
                                $coverProxyUrl = $book->cover_image && Storage::disk('books')->exists($book->cover_image)
                                    ? url('/cover/' . ltrim($book->cover_image, '/'))
                                    : asset('images/placeholder.png');
                            @endphp
                            <img src="{{ $coverProxyUrl }}" alt="cover" style="height: 48px; width: auto; object-fit: contain; border-radius: 3px; box-shadow: 0 1px 2px rgba(0,0,0,.07); background: #f8f8f8;" loading="lazy">
                        </td>
                        <td>{{ $book->title }}</td>
                        <td>{{ $book->author->name }}</td>
                        <td>{{--Display new values--}}</td>
                        <td>{{ $book->genre->name }}</td>
                        <td>
                            <a href="{{ route('admin.books.edit', $book) }}" class="btn btn-sm btn-outline-primary"
                                title="Edit"><i class="fas fa-pencil-alt"></i></a>
                            <form action="{{ route('admin.books.destroy', $book) }}" method="POST" style="display: inline;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                    onclick="return confirm('Are you sure?')"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="pagination">
            {{ $books->links() }}
        </div>

    </div>
@endsection
