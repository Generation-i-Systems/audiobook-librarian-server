@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4>Create New Theme</h4>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('gallery.themes.store') }}">
                        @csrf

                        <div class="mb-3">
                            <label for="name" class="form-label">Theme Name *</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="name" name="name" value="{{ old('name') }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="author" class="form-label">Author *</label>
                            <input type="text" class="form-control @error('author') is-invalid @enderror"
                                   id="author" name="author" value="{{ old('author', Auth::user()->name) }}" required>
                            @error('author')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="version" class="form-label">Version *</label>
                            <input type="text" class="form-control @error('version') is-invalid @enderror"
                                   id="version" name="version" value="{{ old('version', '1.0') }}" required>
                            @error('version')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror"
                                      id="description" name="description" rows="4">{{ old('description') }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="theme_data" class="form-label">Theme Data (JSON) *</label>
                            <textarea class="form-control @error('theme_data') is-invalid @enderror"
                                      id="theme_data" name="theme_data" rows="15" required>{{ old('theme_data', '{
  "primary": "#007bff",
  "background": "#ffffff",
  "surface": "#f8f9fa",
  "text": "#212529",
  "accent": "#28a745",
  "progressActive": "#007bff",
  "progressInactive": "#e9ecef",
  "timeText": "#6c757d"
}') }}</textarea>
                            @error('theme_data')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">
                                Must be valid JSON with required color fields
                            </div>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="is_public" name="is_public"
                                   value="1" checked>
                            <label class="form-check-label" for="is_public">
                                Make this theme public
                            </label>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Create Theme</button>
                            <a href="{{ route('gallery.themes.index') }}" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
