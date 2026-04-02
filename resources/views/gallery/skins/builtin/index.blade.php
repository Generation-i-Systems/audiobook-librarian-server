@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h1>Built-in Skins</h1>
                    <p class="text-muted">Skins included with the Audiobook Librarian client. Fork one to create your own customized version.</p>
                </div>
                <div>
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

            @if(!$isConfigured)
                <div class="alert alert-warning">
                    <strong>Not configured:</strong> Set <code>CLIENT_SKINS_PATH</code> in your <code>.env</code> file to the client repository's <code>src-skins</code> directory to enable built-in skin browsing.
                </div>
            @elseif(empty($skins))
                <div class="alert alert-info">No built-in skins found at the configured path.</div>
            @else
                <div class="row">
                    @foreach($skins as $skin)
                        <div class="col-md-3 mb-4">
                            <div class="card h-100">
                                @if($skin['preview'])
                                    <img src="{{ $skin['preview'] }}"
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
                                        <a href="{{ route('gallery.skins.builtin.show', $skin['slug']) }}">
                                            {{ $skin['name'] }}
                                        </a>
                                        <span class="badge bg-secondary ms-1" style="font-size: 0.65rem;">Built-in</span>
                                    </h5>
                                    @if($skin['author'])
                                        <p class="card-text text-muted small">by {{ $skin['author'] }}</p>
                                    @endif
                                    @if($skin['description'])
                                        <p class="card-text small flex-grow-1">{{ \Illuminate\Support\Str::limit($skin['description'], 100) }}</p>
                                    @endif

                                    <div class="mt-auto pt-2 d-flex gap-2">
                                        @auth
                                            <form action="{{ route('gallery.skins.builtin.fork', $skin['slug']) }}" method="POST" class="d-inline">
                                                @csrf
                                                <input type="hidden" name="name" value="{{ $skin['name'] }} (My Copy)">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-code-branch"></i> Fork
                                                </button>
                                            </form>

                                            @if(Auth::user()->is_admin)
                                                <a href="{{ route('gallery.skins.builtin.designer', $skin['slug']) }}"
                                                   class="btn btn-sm btn-warning">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                            @endif
                                        @endauth

                                        <a href="{{ route('gallery.skins.builtin.download', $skin['slug']) }}"
                                           class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-download"></i> ZIP
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
