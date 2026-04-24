@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>
            @if($browseBy === 'genre')
                <a href="{{ route('admin.librivox.genres') }}" class="text-decoration-none text-muted small me-2">Genres</a>
            @else
                <a href="{{ route('admin.librivox.authors') }}" class="text-decoration-none text-muted small me-2">Authors</a>
            @endif
            {{ $label }}
        </h1>
        <a href="{{ route('admin.librivox.index') }}" class="btn btn-outline-secondary">Imported Books</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    @if($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    <form action="{{ request()->url() }}" method="GET" class="mb-3 d-flex gap-2 align-items-center">
        <label class="form-label mb-0 text-muted small">Language:</label>
        <select name="language" class="form-select form-select-sm" style="max-width:160px;" onchange="this.form.submit()">
            <option value="" @selected(($language ?? '') === '')>All languages</option>
            <option value="English" @selected(($language ?? 'English') === 'English')>English</option>
            <option value="German" @selected(($language ?? '') === 'German')>German</option>
            <option value="Spanish" @selected(($language ?? '') === 'Spanish')>Spanish</option>
            <option value="French" @selected(($language ?? '') === 'French')>French</option>
            <option value="Latin" @selected(($language ?? '') === 'Latin')>Latin</option>
        </select>
    </form>

    @if(count($books) > 0)
        <p class="mb-3 text-muted small">
            @if(($totalCount ?? 0) > count($books))
                {{ number_format($totalCount) }}{{ $totalCount >= 500 ? '+' : '' }} total ·
            @endif
            {{ count($books) }} on this page
        </p>

        <div class="row row-cols-1 row-cols-md-2 g-3">
            @foreach($books as $book)
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

        {{-- Pagination --}}
        @php
            $pageSize = 50;
            $totalPages = isset($totalCount) && $totalCount > 0
                ? (int) ceil($totalCount / $pageSize)
                : ($hasMore ? $page + 1 : $page);
            $window = 2;
            $start = max(1, $page - $window);
            $end = min($totalPages, $page + $window);
        @endphp
        @if($totalPages > 1)
        <nav class="mt-4">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item {{ $page <= 1 ? 'disabled' : '' }}">
                    <a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}">‹</a>
                </li>
                @if($start > 1)
                    <li class="page-item"><a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => 1]) }}">1</a></li>
                    @if($start > 2)<li class="page-item disabled"><span class="page-link">…</span></li>@endif
                @endif
                @for($p = $start; $p <= $end; $p++)
                    <li class="page-item {{ $p === $page ? 'active' : '' }}">
                        <a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $p]) }}">{{ $p }}</a>
                    </li>
                @endfor
                @if($end < $totalPages)
                    @if($end < $totalPages - 1)<li class="page-item disabled"><span class="page-link">…</span></li>@endif
                    <li class="page-item"><a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $totalPages]) }}">{{ $totalPages }}</a></li>
                @endif
                <li class="page-item {{ !$hasMore ? 'disabled' : '' }}">
                    <a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}">›</a>
                </li>
            </ul>
        </nav>
        @endif
    @elseif(!$error)
        <p class="text-muted">No books found for this {{ $browseBy }}.</p>
    @endif
</div>
@endsection
