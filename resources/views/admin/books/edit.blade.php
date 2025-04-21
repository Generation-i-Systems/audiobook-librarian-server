@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Edit Book</h1>

        <form action="{{ route('books.update', $book) }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="title">Title:</label>
                <input type="text" class="form-control" id="title" name="title" value="{{ $book->title }}" required>
            </div>

              <div class="form-group">
                <label for="author_id">Author:</label>
                <select class="form-control" id="author_id" name="author_id">
                    @foreach($authors as $author)
                    <option value="{{ $author->id }}" {{ $book->author_id == $author->id ? 'selected' : '' }}>{{ $author->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="series_id">Series (Optional):</label>
                <select class="form-control" id="series_id" name="series_id">
                    <option value="">Select Series</option>
                    @foreach($seriesList as $series)
                        <option value="{{ $series->id }}" {{ $book->series_id == $series->id ? 'selected' : '' }}>{{ $series->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="series_number">Series Number (Optional):</label>
                <input type="number" class="form-control" id="series_number" name="series_number" value="{{ $book->series_number }}">
            </div>

            <div class="form-group">
                <label for="genre_id">Genre:</label>
                <select class="form-control" id="genre_id" name="genre_id">
                    @foreach($genres as $genre)
                    <option value="{{ $genre->id }}" {{ $book->genre_id == $genre->id ? 'selected' : '' }}>{{ $genre->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="cover_image">Cover Image (Optional):</label>
                <input type="file" class="form-control-file" id="cover_image" name="cover_image">
                @if ($book->cover_image)
                    <img src="{{ Storage::url($book->cover_image) }}" alt="Current Cover" style="max-height: 100px;">
                @endif
            </div>

            <div class="form-group">
                <label for="description">Description (Optional):</label>
                <textarea class="form-control" id="description" name="description" rows="3">{{ $book->description }}</textarea>
            </div>

            <div class="form-group">
                <label for="type">Type:</label>
                <select class="form-control" id="type" name="type">
                    <option value="ebook" {{ $book->type == 'ebook' ? 'selected' : '' }}>Ebook</option>
                    <option value="audiobook" {{ $book->type == 'audiobook' ? 'selected' : '' }}>Audiobook</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Update</button>
            <a href="{{ route('books.index') }}" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
@endsection
