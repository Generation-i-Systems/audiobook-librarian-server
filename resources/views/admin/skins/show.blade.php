@extends('layouts.app')

@section('content')
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.skins.index') }}">Skins</a></li>
            <li class="breadcrumb-item active">{{ $skin['name'] }}</li>
        </ol>
    </nav>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    @if($skin['previewUrl'])
                        <img src="{{ $skin['previewUrl'] }}" alt="{{ $skin['name'] }}" class="img-fluid rounded shadow-sm">
                    @else
                        <div class="bg-light rounded d-flex align-items-center justify-content-center" style="height: 300px;">
                            <i class="fas fa-image fa-4x text-muted"></i>
                        </div>
                    @endif
                </div>
                <div class="col-md-8">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h1>{{ $skin['name'] }}</h1>
                            <p class="lead text-muted">by {{ $skin['author'] }}</p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('gallery.skins.show', $skin['id']) }}" class="btn btn-outline-primary" target="_blank">
                                <i class="fas fa-external-link-alt"></i> View in Gallery
                            </a>
                            <a href="{{ route('admin.skins.edit', $skin['id']) }}" class="btn btn-warning">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                        </div>
                    </div>

                    <hr>

                    <div class="row mb-4">
                        <div class="col-sm-3 fw-bold">Version</div>
                        <div class="col-sm-9">{{ $skin['version'] }}</div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-sm-3 fw-bold">Description</div>
                        <div class="col-sm-9">{{ $skin['description'] ?: 'No description provided.' }}</div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-sm-3 fw-bold">Visibility</div>
                        <div class="col-sm-9">
                            @if($skin['isPublic'])
                                <span class="badge bg-success">Public</span>
                            @else
                                <span class="badge bg-warning">Private</span>
                            @endif
                        </div>
                    </div>

                    <div class="bg-light p-3 rounded">
                        <h5>Statistics</h5>
                        <div class="row text-center">
                            <div class="col">
                                <div class="h3 mb-0">{{ $skin['downloadCount'] }}</div>
                                <div class="small text-muted">Downloads</div>
                            </div>
                            <div class="col border-start">
                                <div class="h3 mb-0">{{ $skin['averageRating'] }}</div>
                                <div class="small text-muted">Avg Rating</div>
                            </div>
                            <div class="col border-start">
                                <div class="h3 mb-0">{{ $skin['ratingCount'] }}</div>
                                <div class="small text-muted">Ratings</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
