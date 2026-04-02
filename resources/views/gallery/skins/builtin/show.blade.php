@extends('layouts.app')

@section('content')
<div class="container">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('gallery.skins.builtin.index') }}">Built-in Skins</a></li>
            <li class="breadcrumb-item active">{{ $skin['name'] }}</li>
        </ol>
    </nav>

    <div class="row">
        <div class="col-md-4">
            @if($skin['preview'])
                <img src="{{ $skin['preview'] }}" class="img-fluid rounded" alt="{{ $skin['name'] }}">
            @else
                <div class="bg-secondary text-white d-flex align-items-center justify-content-center rounded"
                     style="height: 300px;">
                    <i class="fas fa-image fa-5x"></i>
                </div>
            @endif
        </div>

        <div class="col-md-8">
            <div class="d-flex align-items-center gap-2 mb-2">
                <h1 class="mb-0">{{ $skin['name'] }}</h1>
                <span class="badge bg-secondary">Built-in</span>
            </div>
            @if($skin['author'])
                <p class="text-muted">by {{ $skin['author'] }} &middot; v{{ $skin['version'] }}</p>
            @endif
            @if($skin['description'])
                <p>{{ $skin['description'] }}</p>
            @endif

            <div class="d-flex gap-2 mt-4">
                @auth
                    <form action="{{ route('gallery.skins.builtin.fork', $skin['slug']) }}" method="POST">
                        @csrf
                        <div class="input-group">
                            <input type="text" name="name" class="form-control" value="{{ $skin['name'] }} (My Copy)" placeholder="Name for your fork">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-code-branch"></i> Fork &amp; Edit
                            </button>
                        </div>
                    </form>

                    @if(Auth::user()->is_admin)
                        <a href="{{ route('gallery.skins.builtin.designer', $skin['slug']) }}"
                           class="btn btn-warning">
                            <i class="fas fa-edit"></i> Edit in Designer
                        </a>
                    @endif
                @else
                    <a href="{{ route('login') }}" class="btn btn-primary">Log in to Fork</a>
                @endauth

                <a href="{{ route('gallery.skins.builtin.download', $skin['slug']) }}" class="btn btn-outline-secondary">
                    <i class="fas fa-download"></i> Download ZIP
                </a>
            </div>

            @if(Auth::user()?->is_admin)
                <div class="mt-4 p-3 bg-light rounded border">
                    <strong><i class="fas fa-shield-alt"></i> Admin Info</strong>
                    <p class="mb-1 mt-1 text-muted small">Slug: <code>{{ $skin['slug'] }}</code></p>
                    <p class="mb-0 text-muted small">Path: <code>{{ $skin['dir'] }}</code></p>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
