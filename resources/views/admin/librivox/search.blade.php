@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>Search LibriVox</h1>
        <a href="{{ route('admin.librivox.index') }}" class="btn btn-outline-secondary">Browse Imported</a>
    </div>

    @if($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    <form action="{{ route('admin.librivox.search') }}" method="GET" class="mb-4">
        <div class="input-group">
            <select name="field" class="form-select" style="max-width: 130px;">
                <option value="title" @selected(request('field', 'title') === 'title')>Title</option>
                <option value="author" @selected(request('field') === 'author')>Author</option>
            </select>
            <input type="text" class="form-control" name="q" value="{{ $query }}" placeholder="Search LibriVox catalog..." autofocus>
            <button class="btn btn-primary" type="submit">Search</button>
        </div>
    </form>

    @if($query !== '' && count($results) === 0 && !$error)
        <p class="text-muted">No results found for "{{ $query }}".</p>
    @endif

    @if(count($results) > 0)
        <p class="text-muted small mb-3">{{ count($results) }} result(s)</p>
        <div class="row row-cols-1 row-cols-md-2 g-3">
            @foreach($results as $book)
                @php
                    $authors = collect($book['authors'] ?? [])->map(fn($a) => trim($a['first_name'] . ' ' . $a['last_name']))->filter()->join(', ');
                    $genres = collect($book['genres'] ?? [])->pluck('name')->join(', ');
                    $duration = !empty($book['totaltimesecs']) ? gmdate('H:i:s', (int)$book['totaltimesecs']) : null;
                @endphp
                <div class="col">
                    <div class="card h-100 {{ $book['_imported'] ? 'border-success' : '' }}">
                        <div class="card-body">
                            <div class="d-flex gap-3">
                                <div>
                                    <h6 class="card-title mb-1">{{ $book['title'] }}</h6>
                                    @if($authors)
                                        <div class="small text-muted">{{ $authors }}</div>
                                    @endif
                                    @if($genres)
                                        <div class="small text-muted">{{ $genres }}</div>
                                    @endif
                                    <div class="small text-muted">
                                        {{ $book['language'] ?? '' }}
                                        @if(!empty($book['copyright_year'])) · {{ $book['copyright_year'] }} @endif
                                        @if($duration) · {{ $duration }} @endif
                                        @if(!empty($book['num_sections'])) · {{ $book['num_sections'] }} sections @endif
                                    </div>
                                </div>
                            </div>
                            @if(!empty($book['description']))
                                <p class="card-text small mt-2 text-muted"
                                    style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">
                                    {{ strip_tags($book['description']) }}
                                </p>
                            @endif
                        </div>
                        <div class="card-footer d-flex justify-content-between align-items-center">
                            <span class="small text-muted">ID: {{ $book['id'] }}</span>
                            <div class="d-flex gap-2 align-items-center">
                                @if(!empty($book['url_librivox']))
                                    <a href="{{ $book['url_librivox'] }}" target="_blank" class="small">LibriVox ↗</a>
                                @endif
                                @if(!empty($book['_local_id']))
                                    <a href="{{ route('admin.books.show', $book['_local_id']) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
