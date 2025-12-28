@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Search Results</h1>

        @if (count($books) > 0)
            <form action="{{ route('admin.books.importFromGoogleBooks') }}" method="POST">
                @csrf
                <ul>
                    @foreach ($books as $book)
                        <li>
                            <label>
                                <input type="radio" name="volume_id" value="{{ $book['id'] }}" required>
                                {{ $book['volumeInfo']['title'] ?? 'Untitled' }} -
                                {{ isset($book['volumeInfo']['authors']) ? implode(', ', $book['volumeInfo']['authors']) : 'Unknown Author' }}
                            </label>
                        </li>
                    @endforeach
                </ul>

                <button type="submit" class="btn btn-primary">Import Selected Book</button>
            </form>
        @else
            <p>No books found.</p>
        @endif
    </div>
@endsection
