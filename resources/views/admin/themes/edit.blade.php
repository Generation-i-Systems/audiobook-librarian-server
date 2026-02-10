@extends('layouts.app')

@section('content')
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.themes.index') }}">Themes</a></li>
            <li class="breadcrumb-item active">Edit {{ $theme['name'] }}</li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Edit Theme (Admin Mode)</h5>
                </div>

                <div class="card-body">
                    <form method="POST" action="{{ route('admin.themes.update', $theme['id']) }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label">Theme Name</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $theme['name']) }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4">{{ old('description', $theme['description']) }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_public" id="is_public" value="1" {{ old('is_public', $theme['isPublic'] ?? $theme['is_public']) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_public">
                                    Publicly Visible
                                </label>
                            </div>
                        </div>

                        <div class="bg-light p-3 rounded mb-4">
                            <small class="text-muted d-block mb-1">Theme Metadata (ReadOnly)</small>
                            <div class="row small">
                                <div class="col-md-6">
                                    <strong>Author:</strong> {{ $theme['author'] }}<br>
                                    <strong>Version:</strong> {{ $theme['version'] }}<br>
                                    <strong>User ID:</strong> {{ $theme['userId'] }}
                                </div>
                                <div class="col-md-6">
                                    <strong>Downloads:</strong> {{ $theme['downloadCount'] }}<br>
                                    <strong>Rating:</strong> {{ $theme['averageRating'] }} ({{ $theme['ratingCount'] }})<br>
                                    <strong>Created:</strong> {{ \Carbon\Carbon::parse($theme['createdAt'])->format('Y-m-d H:i') }}
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('admin.themes.index') }}" class="btn btn-outline-secondary">Back to List</a>
                            <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
