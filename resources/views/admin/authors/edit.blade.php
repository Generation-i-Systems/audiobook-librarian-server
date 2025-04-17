@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Edit Author</h1>

        <form action="{{ route('admin.authors.update', $author) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="name">Name:</label>
                <input type="text" class="form-control" id="name" name="name" value="{{ $author->name }}" required>
            </div>

            <button type="submit" class="btn btn-primary">Update</button>
            <a href="{{ route('admin.authors.index') }}" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
@endsection
