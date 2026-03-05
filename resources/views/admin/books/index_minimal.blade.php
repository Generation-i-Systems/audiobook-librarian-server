@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Manage Books (Emergency Mode)</h1>
        
        <p>Memory usage debugging mode enabled.</p>
        <p>Total books: {{ $books->total() }}</p>
        <p>Showing: {{ $books->count() }}</p>

        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($books as $book)
                    <tr>
                        <td>{{ $book['id'] ?? 'N/A' }}</td>
                        <td>{{ $book['title'] ?? 'Untitled' }}</td>
                        <td>
                            @if(!empty($book['author']) && is_array($book['author']))
                                {{ implode(', ', $book['author']) }}
                            @else
                                Unknown
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.books.edit', $book['id'] ?? '1') }}" class="btn btn-sm btn-primary">Edit</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $books->links('pagination::bootstrap-5') }}
    </div>
@endsection