@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Import Books</h1>

        <form action="{{ route('processImport') }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="library_path">Library Path:</label>
                <input type="text" class="form-control" id="library_path" name="library_path" required>
            </div>

            <button type="submit" class="btn btn-primary">Import</button>
        </form>

        @if(session('tagData'))
            <h2>Tag Data:</h2>
            <ul>
                @foreach(session('tagData') as $path => $data)
                    <li>
                        <strong>Path:</strong> {{ $path }}<br>
                        <strong>Title:</strong> {{ $data['title'] ?? 'N/A' }}<br>
                        <strong>Artist:</strong> {{ $data['artist'] ?? 'N/A' }}<br>
                        <strong>Album:</strong> {{ $data['album'] ?? 'N/A' }}<br>
                        <strong>Description:</strong> {{ $data['description'] ?? 'N/A' }}<br>

                        @if(!$data['tagMatch'])
                            <span style="color: red;">Tag mismatch detected!</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
