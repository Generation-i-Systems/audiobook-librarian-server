@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Manage Books</h1>

        <form action="{{ route('admin.books.index') }}" method="GET">
            <div class="input-group mb-3">
                <input type="text" class="form-control" placeholder="Search title, author, or series" name="search"
                    value="{{ request('search') }}">
                <button class="btn btn-outline-secondary" type="submit">Search</button>
            </div>
        </form>

        <div class="mb-3">
            <a href="{{ route('admin.books.create') }}" class="btn btn-primary">Add New Book</a>
            <a href="{{ route('admin.books.import') }}" class="btn btn-info">Import Book(s)</a>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Series</th>
                    <th>Genre</th>
                    <th>Type</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($books as $book)
                    <tr class="{{ $loop->iteration % 2 == 0 ? 'table-secondary' : '' }}">
                        <td>{{ $book->title }}</td>
                        <td>{{ $book->author->name }}</td>
                        <td>{{--Display new values--}}</td>
                        <td>{{ $book->genre->name }}</td>
                        <td>{{ $book->type }}</td>
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

        {{ $books->links() }}

    </div>
@endsection
