@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>LibriVox Library</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.librivox.genres') }}" class="btn btn-outline-secondary">Browse Genres</a>
            <a href="{{ route('admin.librivox.authors') }}" class="btn btn-outline-secondary">Browse Authors</a>
            <a href="{{ route('admin.librivox.search') }}" class="btn btn-primary">Search</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="mb-2 text-muted small">
        Total in catalog: <strong>{{ $totalCount }}</strong>
    </div>

    <form action="{{ route('admin.librivox.index') }}" method="GET" class="mb-3">
        <div class="input-group">
            <input type="text" class="form-control" name="search" placeholder="Search title or author"
                value="{{ request('search') }}">
            <select name="sort" class="form-select" style="max-width: 180px;">
                <option value="recent_desc" @selected($sort === 'recent_desc')>Recently Added</option>
                <option value="recent_asc" @selected($sort === 'recent_asc')>Oldest First</option>
                <option value="title_asc" @selected($sort === 'title_asc')>Title A-Z</option>
                <option value="title_desc" @selected($sort === 'title_desc')>Title Z-A</option>
                <option value="year_asc" @selected($sort === 'year_asc')>Year Asc</option>
                <option value="year_desc" @selected($sort === 'year_desc')>Year Desc</option>
            </select>
            <button class="btn btn-outline-secondary" type="submit">Filter</button>
        </div>
    </form>

    <table class="table table-hover">
        <thead>
            <tr>
                <th style="width:56px;"></th>
                <th>Title</th>
                <th>Authors</th>
                <th>Language</th>
                <th>Chapters</th>
                <th>Year</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($books as $book)
                <tr>
                    <td class="text-center align-middle">
                        @if($book->cover_image)
                            <img src="{{ $book->cover_image }}" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:3px;">
                        @else
                            <div style="width:40px;height:40px;background:#eee;border-radius:3px;"></div>
                        @endif
                    </td>
                    <td class="align-middle">
                        <a href="{{ route('admin.books.show', $book->id) }}">{{ $book->title }}</a>
                        @if($book->librivox_info['url_librivox'] ?? null)
                            <a href="{{ $book->librivox_info['url_librivox'] }}" target="_blank" class="ms-1 text-muted small" title="View on LibriVox">↗</a>
                        @endif
                    </td>
                    <td class="align-middle small">
                        {{ $book->authors->pluck('name')->join(', ') ?: '—' }}
                    </td>
                    <td class="align-middle">{{ $book->language ?? '—' }}</td>
                    <td class="align-middle">{{ $book->audio_file_count ?? '—' }}</td>
                    <td class="align-middle">{{ $book->year ?? '—' }}</td>
                    <td class="align-middle">
                        <a href="{{ route('admin.books.show', $book->id) }}" class="btn btn-sm btn-outline-secondary">View</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        No books in catalog yet. Run a sync from the <a href="{{ route('admin.librivox.genres') }}">genres page</a>.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $books->links() }}
</div>
@endsection
