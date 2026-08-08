@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Rename Tag: {{ $tag }}</h1>

        <form action="{{ route('admin.tags.update', $tag) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="name">New name:</label>
                <input type="text" class="form-control" id="name" name="name" value="{{ old('name', $tag) }}" required maxlength="64">
            </div>

            <button type="submit" class="btn btn-primary mt-3">Rename</button>
            <a href="{{ route('admin.tags.index') }}" class="btn btn-secondary">Cancel</a>
        </form>

        <h2 class="mt-4">Books with this tag</h2>
        <ul class="list-group">
            @forelse($books as $book)
                <li class="list-group-item">
                    <a href="{{ route('admin.books.show', $book->id) }}">{{ $book->title }}</a>
                </li>
            @empty
                <li class="list-group-item text-muted">No books currently have this tag.</li>
            @endforelse
        </ul>
    </div>
@endsection
