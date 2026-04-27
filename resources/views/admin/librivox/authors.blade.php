@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>Browse by Author</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.librivox.genres') }}" class="btn btn-outline-secondary">Browse Genres</a>
            <a href="{{ route('admin.librivox.search') }}" class="btn btn-outline-secondary">Search</a>
            <a href="{{ route('admin.librivox.index') }}" class="btn btn-outline-secondary">Imported Books</a>
        </div>
    </div>

    <form action="{{ route('admin.librivox.authors') }}" method="GET" class="mb-4">
        <div class="input-group">
            <input type="text" class="form-control" name="q" value="{{ $query }}"
                placeholder="Search by last name (e.g. Twain, Dickens)..." autofocus>
            <button class="btn btn-primary" type="submit">Search</button>
        </div>
    </form>

    @if($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    @if($query !== '' && count($authors) === 0 && !$error)
        <p class="text-muted">No authors found for "{{ $query }}".</p>
    @endif

    @if(count($authors) > 0)
        <p class="text-muted small mb-3">{{ count($authors) }} author(s) found</p>
        <div class="list-group">
            @foreach($authors as $author)
                @php
                    $name = trim($author['first_name'] . ' ' . $author['last_name']);
                    $years = collect([$author['dob'] ?? null, $author['dod'] ?? null])->filter()->join('–');
                @endphp
                <a href="{{ route('admin.librivox.author.books', $author['id']) }}"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span>{{ $name }}</span>
                    @if($years)
                        <span class="text-muted small">{{ $years }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
