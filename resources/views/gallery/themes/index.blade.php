@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row mb-4">
        <div class="col-md-12">
            <h1>Theme Gallery</h1>

            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <a href="{{ route('gallery.themes.index', ['sort' => 'recent']) }}"
                       class="btn btn-sm {{ $sort === 'recent' ? 'btn-primary' : 'btn-outline-primary' }}">
                        Recent
                    </a>
                    <a href="{{ route('gallery.themes.index', ['sort' => 'popular']) }}"
                       class="btn btn-sm {{ $sort === 'popular' ? 'btn-primary' : 'btn-outline-primary' }}">
                        Popular
                    </a>
                    <a href="{{ route('gallery.themes.index', ['sort' => 'top_rated']) }}"
                       class="btn btn-sm {{ $sort === 'top_rated' ? 'btn-primary' : 'btn-outline-primary' }}">
                        Top Rated
                    </a>
                </div>

                <div>
                    @auth
                        <a href="{{ route('gallery.themes.create') }}" class="btn btn-success">Create Theme</a>
                        <a href="{{ route('gallery.themes.my-themes') }}" class="btn btn-secondary">My Themes</a>
                    @endauth
                </div>
            </div>

            <form method="GET" class="mb-4">
                <div class="input-group">
                    <input type="text" name="search" class="form-control" placeholder="Search themes..."
                           value="{{ $search ?? '' }}">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </form>
        </div>
    </div>

    @if(empty($themes))
        <div class="alert alert-info">
            No themes found. @auth<a href="{{ route('gallery.themes.create') }}">Create the first one!</a>@endauth
        </div>
    @else
        <div class="row">
            @foreach($themes as $theme)
                <div class="col-md-3 mb-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h5 class="card-title">
                                <a href="{{ route('gallery.themes.show', $theme['id']) }}">
                                    {{ $theme['name'] }}
                                </a>
                            </h5>
                            <p class="card-text text-muted small">by {{ $theme['author'] }}</p>
                            @if(!empty($theme['description']))
                                <p class="card-text">{{ \Illuminate\Support\Str::limit($theme['description'], 100) }}</p>
                            @endif

                            @php
                                $themeData = $theme['themeData'] ?? $theme['theme_data'] ?? null;
                            @endphp
                            @if(!empty($themeData))
                                <div class="d-flex gap-1 mb-2">
                                    @if(!empty($themeData['primary']))
                                        <div style="width:20px;height:20px;background:{{ $themeData['primary'] }};border:1px solid #ccc;"></div>
                                    @endif
                                    @if(!empty($themeData['accent']))
                                        <div style="width:20px;height:20px;background:{{ $themeData['accent'] }};border:1px solid #ccc;"></div>
                                    @endif
                                    @if(!empty($themeData['background']))
                                        <div style="width:20px;height:20px;background:{{ $themeData['background'] }};border:1px solid #ccc;"></div>
                                    @endif
                                </div>
                            @endif

                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small class="text-muted">
                                    <i class="fas fa-download"></i> {{ $theme['downloadCount'] ?? $theme['download_count'] ?? 0 }}
                                </small>
                                <small class="text-muted">
                                    <i class="fas fa-star"></i>
                                    {{ number_format($theme['averageRating'] ?? $theme['average_rating'] ?? 0, 1) }}
                                    ({{ $theme['ratingCount'] ?? $theme['rating_count'] ?? 0 }})
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if($pagination['last_page'] > 1)
            <nav>
                <ul class="pagination">
                    @if($pagination['current_page'] > 1)
                        <li class="page-item">
                            <a class="page-link" href="{{ route('gallery.themes.index', array_merge(request()->query(), ['page' => $pagination['current_page'] - 1])) }}">
                                Previous
                            </a>
                        </li>
                    @endif

                    @for($i = 1; $i <= $pagination['last_page']; $i++)
                        <li class="page-item {{ $i === $pagination['current_page'] ? 'active' : '' }}">
                            <a class="page-link" href="{{ route('gallery.themes.index', array_merge(request()->query(), ['page' => $i])) }}">
                                {{ $i }}
                            </a>
                        </li>
                    @endfor

                    @if($pagination['current_page'] < $pagination['last_page'])
                        <li class="page-item">
                            <a class="page-link" href="{{ route('gallery.themes.index', array_merge(request()->query(), ['page' => $pagination['current_page'] + 1])) }}">
                                Next
                            </a>
                        </li>
                    @endif
                </ul>
            </nav>
        @endif
    @endif
</div>
@endsection
