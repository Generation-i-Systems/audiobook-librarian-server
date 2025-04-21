@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Genres</h1>

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        <a href="{{ route('admin.genres.create') }}" class="btn btn-primary mb-3">Create New Genre</a>

        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($genres as $genre)
                    <tr>
                        <td>{{ $genre->name }}</td>
                        <td>
                            <a href="{{ route('admin.genres.edit', $genre) }}" class="btn btn-sm btn-outline-primary"
                                title="Edit"><i class="fas fa-pencil-alt"></i></a>
                            <form action="{{ route('admin.genres.destroy', $genre) }}" method="POST" style="display:inline;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure?')"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
