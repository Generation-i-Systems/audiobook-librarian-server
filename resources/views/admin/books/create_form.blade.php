@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Create New Book</h1>

        <form action="{{ route('admin.books.store') }}" method="POST" enctype="multipart/form-data" class="mt-3">
            @csrf
            <div class="form-group">
                <label for="title">Title:</label>
                <input type="text" class="form-control" id="title" name="title" value="{{ old('title', $initial['title']) }}" required>
                @error('title')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>
            <div class="form-group">
                <label for="author_id">Author:</label>
                <select class="form-control" id="author_id" name="author_id" required>
                    <option value="">Select Author</option>
                    @foreach($authorList as $author)
                    <option value="{{ $author->id }}" @if(old('author_id') == $author->id || $initial->author_id == $author->id) selected @endif>{{ $author->name }}</option>
                    @endforeach
                </select>
                @error('author_id')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="series_id">Series (Optional):</label>
                <select class="form-control" id="series_id" name="series_id">
                    <option value="">Select Series</option>
                    @foreach($seriesList as $series)
                        <option value="{{ $series->id }}" @if(old('series_id', $initial->series_id) == $series->id) selected @endif>{{ $series->name }}</option>
                    @endforeach
                </select>
                @error('series_id')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="series_number">Series Number (Optional):</label>
                <input type="number" class="form-control" id="series_number" name="series_number" value="{{ old('series_number', $initial->series_number) }}">
                @error('series_number')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="genre_id">Genre:</label>
                <select class="form-control" id="genre_id" name="genre_id" required>
                    <option value="">Select Genre</option>
                    @foreach($genreList as $genre)
                    <option value="{{ $genre->id }}" @if(old('genre_id') == $genre->id || $initial->genre_id == $genre->id) selected @endif>{{ $genre->name }}</option>
                    @endforeach
                </select>
                @error('genre_id')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="cover_image">Cover Image (Optional):</label>
                <input type="file" class="form-control-file" id="cover_image" name="cover_image">
                @error('cover_image')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="description">Description (Optional):</label>
                <textarea class="form-control" id="description" name="description" rows="3">{{ old('description', $initial->description) }}</textarea>
                @error('description')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="publication_date">Publication Date (Optional):</label>
                <input type="date" class="form-control" id="publication_date" name="publication_date" value="{{ old('publication_date', $initial->publication_date) }}">
                @error('publication_date')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <div class="form-group">
                <label for="book_files">Directory Path:</label>
                <input type="text" class="form-control" id="directory_path" name="directory_path" value="{{ old('directory_path', $initial->directory_path) }}">
                @error('directory_path')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            {{-- <div class="form-group">
                <label for="book_files">Book Files:</label>
                <input type="file" class="form-control-file" id="book_files" name="book_files[]" multiple required>
                @error('book_files')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div> --}}

            <div class="form-group">
                <label>Type:</label><br>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="type" id="ebook" value="ebook" @if(old('type') == 'ebook') checked @endif>
                    <label class="form-check-label" for="ebook">Ebook</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="type" id="audiobook" value="audiobook" @if(old('type') == null || old('type') == 'audiobook') checked @endif required>
                    <label class="form-check-label" for="audiobook">Audiobook</label>
                </div>
                @error('type')
                    <span class="text-danger">{{ $message }}</span>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary">Create</button>
            <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
@endsection
