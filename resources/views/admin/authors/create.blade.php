@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Create New Author</h1>

        <form action="{{ route('admin.authors.store') }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="name">Name:</label>
                <input type="text" class="form-control" id="name" name="name" required>
            </div>

            <button type="submit" class="btn btn-primary">Create</button>
            <a href="{{ route('admin.authors.index') }}" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
@endsection
