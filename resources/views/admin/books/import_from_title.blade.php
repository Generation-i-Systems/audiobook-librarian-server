@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Import Book from Title</h1>

        <form action="{{ route('admin.books.googleBooks') }}" method="GET">
            @csrf

            <div class="form-group">
                <label for="title">Title:</label>
                <input type="text" class="form-control" id="title" name="title" required>
            </div>

            <div class="form-group">
                <label for="author">Author (optional):</label>
                <input type="text" class="form-control" id="author" name="author">
            </div>

            <button type="submit" class="btn btn-primary">Search Google Books</button>
        </form>
    </div>
@endsection
