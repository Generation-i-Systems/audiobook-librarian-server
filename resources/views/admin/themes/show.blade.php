@extends('layouts.app')

@section('content')
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.themes.index') }}">Themes</a></li>
            <li class="breadcrumb-item active">{{ $theme['name'] }}</li>
        </ol>
    </nav>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="rounded d-flex align-items-center justify-content-center shadow-sm" style="height: 300px; background-color: {{ $theme['themeData']['colors']['primary'] ?? '#ddd' }}; color: {{ $theme['themeData']['colors']['onPrimary'] ?? '#000' }};">
                        <i class="fas fa-palette fa-5x"></i>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h1>{{ $theme['name'] }}</h1>
                            <p class="lead text-muted">by {{ $theme['author'] }}</p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('gallery.themes.show', $theme['id']) }}" class="btn btn-outline-primary" target="_blank">
                                <i class="fas fa-external-link-alt"></i> View in Gallery
                            </a>
                            <a href="{{ route('admin.themes.edit', $theme['id']) }}" class="btn btn-warning">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                        </div>
                    </div>

                    <hr>

                    <div class="row mb-4">
                        <div class="col-sm-3 fw-bold">Version</div>
                        <div class="col-sm-9">{{ $theme['version'] }}</div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-sm-3 fw-bold">Description</div>
                        <div class="col-sm-9">{{ $theme['description'] ?: 'No description provided.' }}</div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-sm-3 fw-bold">Visibility</div>
                        <div class="col-sm-9">
                            @if($theme['isPublic'])
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
                                <div class="h3 mb-0">{{ $theme['downloadCount'] }}</div>
                                <div class="small text-muted">Downloads</div>
                            </div>
                            <div class="col border-start">
                                <div class="h3 mb-0">{{ $theme['averageRating'] }}</div>
                                <div class="small text-muted">Avg Rating</div>
                            </div>
                            <div class="col border-start">
                                <div class="h3 mb-0">{{ $theme['ratingCount'] }}</div>
                                <div class="small text-muted">Ratings</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <h5>Colors</h5>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach($theme['themeData']['colors'] ?? [] as $key => $color)
                                <div class="p-2 border rounded d-flex align-items-center" style="min-width: 150px;">
                                    <div class="rounded me-2" style="width: 24px; height: 24px; background-color: {{ $color }}; border: 1px solid #ccc;"></div>
                                    <div class="small">
                                        <div class="fw-bold">{{ $key }}</div>
                                        <div class="text-muted">{{ $color }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
