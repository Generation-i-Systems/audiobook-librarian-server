@extends('layouts.app')

@section('content')
<div class="container">
    @if($book->cover_image)
        <div class="mb-3">
            <img src="{{ route('image.proxy', ['dir' => $book->directory_path, 'file' => basename($book->cover_image)]) }}" alt="Book Cover" style="max-height: 200px; border:1px solid #ccc;">
        </div>
    @endif
    <h1>{{ $book->title }}</h1>
    <ul class="list-group mb-4">
        <li class="list-group-item"><strong>Author:</strong> {{ $book->author ? $book->author->name : 'N/A' }}</li>
        <li class="list-group-item"><strong>Genre:</strong> {{ $book->genre ? $book->genre->name : 'N/A' }}</li>
        <li class="list-group-item"><strong>Series:</strong> {{ $book->series ? $book->series->name : 'N/A' }}</li>
        <li class="list-group-item"><strong>Series Number:</strong> {{ $book->series_number ?? 'N/A' }}</li>
        <li class="list-group-item"><strong>Description:</strong> {{ $book->description }}</li>
        <li class="list-group-item"><strong>Type:</strong> {{ ucfirst($book->type) }}</li>
        <li class="list-group-item"><strong>Published Year:</strong> {{ $book->published_year }}</li>
        <li class="list-group-item"><strong>Date Added:</strong> {{ $book->date_added }}</li>
        <li class="list-group-item"><strong>Directory Path:</strong> {{ $book->directory_path }}</li>
    </ul>
    <a href="{{ route('admin.books.edit', $book) }}" class="btn btn-primary">Edit</a>
    <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Back to List</a>
</div>
@endsection
