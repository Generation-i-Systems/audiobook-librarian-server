@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1>My Skins</h1>
                <div class="d-flex gap-2">
                    <a href="{{ route('gallery.skins.designerNew') }}" class="btn btn-info text-white">
                        <i class="fas fa-paint-brush"></i> Design New Skin
                    </a>
                    <a href="{{ route('gallery.skins.create') }}" class="btn btn-success">Upload Skin</a>
                    <a href="{{ route('gallery.skins.index') }}" class="btn btn-outline-secondary">Community Gallery</a>
                </div>
            </div>

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
        </div>
    </div>

    @if(empty($skins))
        <div class="alert alert-info">
            You haven't created any skins yet.
            <a href="{{ route('gallery.skins.create') }}">Upload one</a>
            or <a href="{{ route('gallery.skins.designerNew') }}">design one from scratch</a>.
        </div>
    @else
        <div class="row">
            @foreach($skins as $skin)
                <div class="col-md-3 mb-4">
                    <div class="card h-100">
                        @if(!empty($skin['previewPath']))
                            <img src="{{ asset('storage/' . $skin['previewPath']) }}"
                                 class="card-img-top" alt="{{ $skin['name'] }}"
                                 style="height: 200px; object-fit: cover;">
                        @else
                            <div class="bg-secondary text-white d-flex align-items-center justify-content-center"
                                 style="height: 200px;">
                                <i class="fas fa-image fa-3x"></i>
                            </div>
                        @endif

                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">
                                <a href="{{ route('gallery.skins.show', $skin['id']) }}">
                                    {{ $skin['name'] }}
                                </a>
                                @if(!($skin['isPublic'] ?? $skin['is_public'] ?? false))
                                    <span class="badge bg-secondary ms-1" style="font-size: 0.65rem;">Private</span>
                                @endif
                            </h5>
                            @if(!empty($skin['description']))
                                <p class="card-text flex-grow-1">{{ \Illuminate\Support\Str::limit($skin['description'], 100) }}</p>
                            @endif

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <small class="text-muted">
                                    <i class="fas fa-download"></i> {{ $skin['downloadCount'] ?? $skin['download_count'] ?? 0 }}
                                </small>
                                <small class="text-muted">
                                    <i class="fas fa-star"></i>
                                    {{ number_format($skin['averageRating'] ?? $skin['average_rating'] ?? 0, 1) }}
                                    ({{ $skin['ratingCount'] ?? $skin['rating_count'] ?? 0 }})
                                </small>
                            </div>

                            <div class="mt-auto pt-2 d-flex flex-wrap gap-2">
                                <a href="{{ route('gallery.skins.edit', $skin['id']) }}" class="btn btn-sm btn-warning">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="{{ route('gallery.skins.designer', $skin['id']) }}" class="btn btn-sm btn-info text-white">
                                    <i class="fas fa-paint-brush"></i> Designer
                                </a>
                                <form method="POST" action="{{ route('gallery.skins.destroy', $skin['id']) }}"
                                      onsubmit="return confirm('Are you sure you want to delete this skin?');" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
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
                            <a class="page-link" href="{{ route('gallery.skins.my-skins', ['page' => $pagination['current_page'] - 1]) }}">
                                Previous
                            </a>
                        </li>
                    @endif

                    @for($i = 1; $i <= $pagination['last_page']; $i++)
                        <li class="page-item {{ $i === $pagination['current_page'] ? 'active' : '' }}">
                            <a class="page-link" href="{{ route('gallery.skins.my-skins', ['page' => $i]) }}">
                                {{ $i }}
                            </a>
                        </li>
                    @endfor

                    @if($pagination['current_page'] < $pagination['last_page'])
                        <li class="page-item">
                            <a class="page-link" href="{{ route('gallery.skins.my-skins', ['page' => $pagination['current_page'] + 1]) }}">
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
