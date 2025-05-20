@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Manage Authors</h1>

        <form action="{{ route('admin.authors.index') }}" method="GET">
            <div class="input-group mb-3">
                <input type="text" class="form-control" placeholder="Search author name" name="search"
                    value="{{ request('search') }}">
                <button class="btn btn-outline-secondary" type="submit">Search</button>
            </div>
        </form>

        <a href="{{ route('admin.authors.create') }}" class="btn btn-primary mb-3">Add New Author</a>

        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($authors as $author)
                    <tr class="{{ $loop->iteration % 2 == 0 ? 'table-secondary' : '' }}">
                        <td>{{ $author->name }}</td>
                        <td>
                            <a href="{{ route('admin.books.create') }}?author_id={{$author->id}}"
                                class="btn btn-sm btn-outline-success" title="Add Book"><i class="fas fa-plus-circle"></i></a>
                            <a href="{{ route('admin.authors.edit', $author) }}" class="btn btn-sm btn-outline-primary"
                                title="Edit"><i class="fas fa-pencil-alt"></i></a>
                            <form action="{{ route('admin.authors.destroy', $author) }}" method="POST" style="display: inline;">
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

        {{-- Simple pagination removed as we're not using Laravel's paginator --}}
        @if(count($authors) === 0)
            <div class="alert alert-info">No authors found.</div>
        @endif

    </div>
@endsection
