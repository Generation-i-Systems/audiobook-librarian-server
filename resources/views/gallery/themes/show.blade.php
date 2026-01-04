@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8">
            <h1>{{ $theme['name'] }}</h1>
            <p class="text-muted">by {{ $theme['author'] }} • Version {{ $theme['version'] }}</p>

            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if(!empty($theme['description']))
                <h4>Description</h4>
                <p>{{ $theme['description'] }}</p>
            @endif

            @if(!empty($theme['themeData'] ?? $theme['theme_data']))
                <h4>Theme Colors</h4>
                <div class="row">
                    @foreach(($theme['themeData'] ?? $theme['theme_data']) as $key => $value)
                        @if(is_string($value) && str_starts_with($value, '#'))
                            <div class="col-md-4 mb-3">
                                <div class="d-flex align-items-center">
                                    <div style="width:40px;height:40px;background:{{ $value }};border:1px solid #ccc;margin-right:10px;"></div>
                                    <div>
                                        <strong>{{ ucfirst(str_replace('_', ' ', $key)) }}</strong><br>
                                        <small class="text-muted">{{ $value }}</small>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="d-flex gap-2 mb-4">
                <a href="/api/v1/themes/{{ $theme['id'] }}/download" class="btn btn-primary">
                    <i class="fas fa-download"></i> Download JSON
                </a>

                @auth
                    @if(($theme['user_id'] ?? $theme['userId'] ?? null) == Auth::id())
                        <a href="{{ route('gallery.themes.edit', $theme['id']) }}" class="btn btn-warning">
                            <i class="fas fa-edit"></i> Edit
                        </a>

                        <form method="POST" action="{{ route('gallery.themes.destroy', $theme['id']) }}"
                              onsubmit="return confirm('Are you sure you want to delete this theme?');">
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
                    <p><strong>Downloads:</strong> {{ $theme['downloadCount'] ?? $theme['download_count'] ?? 0 }}</p>
                    <p><strong>Rating:</strong>
                        {{ number_format($theme['averageRating'] ?? $theme['average_rating'] ?? 0, 1) }}/5.0
                        ({{ $theme['ratingCount'] ?? $theme['rating_count'] ?? 0 }} ratings)
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            @auth
                <div class="card mb-4">
                    <div class="card-header">
                        <h5>Rate this Theme</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('gallery.themes.rate', $theme['id']) }}">
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
            <form method="POST" action="{{ route('gallery.themes.fork', $theme['id']) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Fork Theme</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>New Theme Name</label>
                        <input type="text" name="name" class="form-control"
                               value="{{ $theme['name'] }} (Fork)" required>
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
