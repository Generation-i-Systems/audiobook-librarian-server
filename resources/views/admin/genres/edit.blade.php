@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Edit Genre</h1>

        <form action="{{ route('admin.genres.update', $genre) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="name">Name:</label>
                <input type="text" class="form-control" id="name" name="name" value="{{ $genre->name }}" required>
            </div>

            <button type="submit" class="btn btn-primary">Update</button>
            <a href="{{ route('admin.genres.index') }}" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
@endsection
