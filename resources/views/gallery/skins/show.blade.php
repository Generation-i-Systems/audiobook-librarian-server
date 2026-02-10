@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8">
            <h1>{{ $skin['name'] }}</h1>
            <p class="text-muted">by {{ $skin['author'] }} • Version {{ $skin['version'] }}</p>

            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if(!empty($skin['previewPath'] ?? $skin['preview_path']))
                <img src="{{ asset('storage/' . ($skin['previewPath'] ?? $skin['preview_path'])) }}"
                     class="img-fluid mb-4" alt="{{ $skin['name'] }}">
            @endif

            @if(!empty($skin['description']))
                <h4>Description</h4>
                <p>{{ $skin['description'] }}</p>
            @endif

            <div class="d-flex gap-2 mb-4">
                <a href="/api/v1/skins/{{ $skin['id'] }}/download" class="btn btn-primary">
                    <i class="fas fa-download"></i> Download
                </a>

                @auth
                    @if(($skin['user_id'] ?? $skin['userId'] ?? null) == Auth::id())
                        <a href="{{ route('gallery.skins.edit', $skin['id']) }}" class="btn btn-warning">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <a href="{{ route('gallery.skins.designer', $skin['id']) }}" class="btn btn-info text-white">
                            <i class="fas fa-paint-brush"></i> Designer
                        </a>

                        <form method="POST" action="{{ route('gallery.skins.destroy', $skin['id']) }}"
                              onsubmit="return confirm('Are you sure you want to delete this skin?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    @else
                        <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#forkModal">
                            <i class="fas fa-code-branch"></i> Fork
                        </button>
                    @endif
                @endauth
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <h5>Stats</h5>
                    <p><strong>Downloads:</strong> {{ $skin['downloadCount'] ?? $skin['download_count'] ?? 0 }}</p>
                    <p><strong>Rating:</strong>
                        {{ number_format($skin['averageRating'] ?? $skin['average_rating'] ?? 0, 1) }}/5.0
                        ({{ $skin['ratingCount'] ?? $skin['rating_count'] ?? 0 }} ratings)
                    </p>
                    <p><strong>File Size:</strong> {{ number_format(($skin['fileSize'] ?? $skin['file_size'] ?? 0) / 1024 / 1024, 2) }} MB</p>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            @auth
                <div class="card mb-4">
                    <div class="card-header">
                        <h5>Rate this Skin</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('gallery.skins.rate', $skin['id']) }}">
                            @csrf
                            <div class="mb-3">
                                <label>Rating</label>
                                <select name="rating" class="form-select" required>
                                    <option value="">Select rating...</option>
                                    <option value="5">5 - Excellent</option>
                                    <option value="4">4 - Good</option>
                                    <option value="3">3 - Average</option>
                                    <option value="2">2 - Poor</option>
                                    <option value="1">1 - Terrible</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label>Comment (optional)</label>
                                <textarea name="comment" class="form-control" rows="3"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Submit Rating</button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>
    </div>
</div>

@auth
<!-- Fork Modal -->
<div class="modal fade" id="forkModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('gallery.skins.fork', $skin['id']) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Fork Skin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>New Skin Name</label>
                        <input type="text" name="name" class="form-control"
                               value="{{ $skin['name'] }} (Fork)" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Fork</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endauth
@endsection
