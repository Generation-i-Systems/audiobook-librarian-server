@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4>Edit Skin: {{ $skin['name'] }}</h4>
                </div>

                <div class="card-body">
                    <form method="POST" action="{{ route('gallery.skins.update', $skin['id']) }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label">Skin Name</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $skin['name']) }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4">{{ old('description', $skin['description']) }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_public" id="is_public" value="1" {{ old('is_public', $skin['isPublic'] ?? $skin['is_public']) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_public">
                                    Make this skin public (visible to everyone)
                                </label>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <div class="d-flex gap-2">
                                <a href="{{ route('gallery.skins.show', $skin['id']) }}" class="btn btn-outline-secondary">Cancel</a>
                                <a href="{{ route('gallery.skins.designer', $skin['id']) }}" class="btn btn-warning">
                                    <i class="fas fa-paint-brush"></i> Open in Designer
                                </a>
                                <button type="submit" class="btn btn-primary">Update Skin</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
