@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Create New Book</h1>

        <form action="{{ route('books.store') }}" method="POST" enctype="multipart/form-data">
            @csrf

            <div class="form-group">
                <label for="title">Title:</label>
                <input type="text" class="form-control" id="title" name="title" required>
            </div>

            <div class="form-group">
                <label for="author_id">Author:</label>
                <select class="form-control" id="author_id" name="author_id">
                    @foreach($authors as $author)
                    <option value="{{ $author->id }}">{{ $author->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="series">Series (Optional):</label>
                <input type="text" class="form-control" id="series" name="series">
            </div>

            <div class="form-group">
                <label for="genre_id">Genre:</label>
                <select class="form-control" id="genre_id" name="genre_id">
                    @foreach($genres as $genre)
                    <option value="{{ $genre->id }}">{{ $genre->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="cover_image">Cover Image (Optional):</label>
                <input type="file" class="form-control-file" id="cover_image" name="cover_image">
            </div>

            <div class="form-group">
                <label for="description">Description (Optional):</label>
                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
            </div>

             <div class="form-group">
                <label for="book_files">Book Files:</label>
                <input type="file" class="form-control-file" id="book_files" name="book_files[]" multiple required>
            </div>

            <div class="form-group">
                <label for="type">Type:</label>
                <select class="form-control" id="type" name="type">
                    <option value="ebook">Ebook</option>
                    <option value="audiobook">Audiobook</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Create</button>
            <a href="{{ route('books.index') }}" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
@endsection
