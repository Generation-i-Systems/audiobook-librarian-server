@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row mb-4">
        <div class="col-md-12">
            <h1>Skin Gallery</h1>

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
                    <a href="{{ route('gallery.skins.index', ['sort' => 'recent']) }}"
                       class="btn btn-sm {{ $sort === 'recent' ? 'btn-primary' : 'btn-outline-primary' }}">
                        Recent
                    </a>
                    <a href="{{ route('gallery.skins.index', ['sort' => 'popular']) }}"
                       class="btn btn-sm {{ $sort === 'popular' ? 'btn-primary' : 'btn-outline-primary' }}">
                        Popular
                    </a>
                    <a href="{{ route('gallery.skins.index', ['sort' => 'top_rated']) }}"
                       class="btn btn-sm {{ $sort === 'top_rated' ? 'btn-primary' : 'btn-outline-primary' }}">
                        Top Rated
                    </a>
                </div>

                <div class="d-flex gap-2">
                    <a href="{{ route('gallery.skins.builtin.index') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-box"></i> Built-in Skins
                    </a>
                    @auth
                        <a href="{{ route('gallery.skins.create') }}" class="btn btn-success">Upload Skin</a>
                        <a href="{{ route('gallery.skins.my-skins') }}" class="btn btn-secondary">My Skins</a>
                    @endauth
                </div>
            </div>

            <form method="GET" class="mb-4">
                <div class="input-group">
                    <input type="text" name="search" class="form-control" placeholder="Search skins..."
                           value="{{ $search ?? '' }}">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </form>
        </div>
    </div>

    @if(empty($skins))
        <div class="alert alert-info">
            No skins found. @auth<a href="{{ route('gallery.skins.create') }}">Upload the first one!</a>@endauth
        </div>
    @else
        <div class="row">
            @foreach($skins as $skin)
                <div class="col-md-3 mb-4">
                    <div class="card h-100">
                        @if(!empty($skin['previewPath'] ?? $skin['preview_path']))
                            <img src="{{ asset('storage/' . ($skin['previewPath'] ?? $skin['preview_path'])) }}"
                                 class="card-img-top" alt="{{ $skin['name'] }}"
                                 style="height: 200px; object-fit: cover;">
                        @else
                            <div class="bg-secondary text-white d-flex align-items-center justify-content-center"
                                 style="height: 200px;">
                                <i class="fas fa-image fa-3x"></i>
                            </div>
                        @endif

                        <div class="card-body">
                            <h5 class="card-title">
                                <a href="{{ route('gallery.skins.show', $skin['id']) }}">
                                    {{ $skin['name'] }}
                                </a>
                            </h5>
                            <p class="card-text text-muted small">by {{ $skin['author'] }}</p>
                            @if(!empty($skin['description']))
                                <p class="card-text">{{ \Illuminate\Support\Str::limit($skin['description'], 100) }}</p>
                            @endif

                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small class="text-muted">
                                    <i class="fas fa-download"></i> {{ $skin['downloadCount'] ?? $skin['download_count'] ?? 0 }}
                                </small>
                                <small class="text-muted">
                                    <i class="fas fa-star"></i>
                                    {{ number_format($skin['averageRating'] ?? $skin['average_rating'] ?? 0, 1) }}
                                    ({{ $skin['ratingCount'] ?? $skin['rating_count'] ?? 0 }})
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
                            <a class="page-link" href="{{ route('gallery.skins.index', array_merge(request()->query(), ['page' => $pagination['current_page'] - 1])) }}">
                                Previous
                            </a>
                        </li>
                    @endif

                    @for($i = 1; $i <= $pagination['last_page']; $i++)
                        <li class="page-item {{ $i === $pagination['current_page'] ? 'active' : '' }}">
                            <a class="page-link" href="{{ route('gallery.skins.index', array_merge(request()->query(), ['page' => $i])) }}">
                                {{ $i }}
                            </a>
                        </li>
                    @endfor

                    @if($pagination['current_page'] < $pagination['last_page'])
                        <li class="page-item">
                            <a class="page-link" href="{{ route('gallery.skins.index', array_merge(request()->query(), ['page' => $pagination['current_page'] + 1])) }}">
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
