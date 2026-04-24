@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>Browse by Genre</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.librivox.authors') }}" class="btn btn-outline-secondary">Browse Authors</a>
            <a href="{{ route('admin.librivox.search') }}" class="btn btn-outline-secondary">Search</a>
            <a href="{{ route('admin.librivox.index') }}" class="btn btn-outline-secondary">Imported Books</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
        <form action="{{ route('admin.librivox.genres') }}" method="GET" class="d-flex gap-2 align-items-center">
            <label class="form-label mb-0 text-muted small">Language:</label>
            <select name="language" class="form-select form-select-sm" style="max-width:160px;" onchange="this.form.submit()">
                <option value="" @selected(($language ?? '') === '')>All languages</option>
                <option value="English" @selected(($language ?? 'English') === 'English')>English</option>
                <option value="German" @selected(($language ?? '') === 'German')>German</option>
                <option value="Spanish" @selected(($language ?? '') === 'Spanish')>Spanish</option>
                <option value="French" @selected(($language ?? '') === 'French')>French</option>
                <option value="Latin" @selected(($language ?? '') === 'Latin')>Latin</option>
            </select>
        </form>

        <div class="text-muted small">
            @if($synced && $lastSync)
                Catalog synced {{ $lastSync->completed_at->diffForHumans() }}
                ({{ number_format($lastSync->books_imported) }} books · {{ $lastSync->sync_type }})
            @else
                Catalog not yet synced
            @endif
        </div>

        @php $runningSync = \App\Models\LibriVoxSyncLog::where('language', $language ?? 'English')->whereIn('status',['running','cancelling'])->latest('started_at')->first(); @endphp
        @if($runningSync)
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small">
                    {{ $runningSync->status === 'cancelling' ? 'Cancelling…' : 'Syncing… (' . number_format($runningSync->books_imported) . ' so far)' }}
                </span>
                @if($runningSync->status === 'running')
                    <form method="POST" action="{{ route('admin.librivox.sync.cancel') }}">
                        @csrf
                        <input type="hidden" name="language" value="{{ $language ?? 'English' }}">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                    </form>
                @endif
            </div>
        @else
            <form method="POST" action="{{ route('admin.librivox.sync') }}">
                @csrf
                <input type="hidden" name="language" value="{{ $language ?? 'English' }}">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    {{ $synced ? 'Sync Now (delta)' : 'Run Full Sync' }}
                </button>
            </form>
        @endif
    </div>

    @if(!$synced)
        <div class="alert alert-info">
            Run a full sync to populate the catalog. Genre book counts will appear once the sync completes.
        </div>
    @endif

    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-2">
        @foreach($genres as $genre)
            @php
                $imported = $importedCounts[$genre] ?? 0;
                $total = $apiCounts[$genre] ?? 0;
            @endphp
            @if($synced && $total === 0)
                @continue
            @endif
            <div class="col">
                <a href="{{ route('admin.librivox.genre.books', ['genre' => $genre]) }}?language={{ urlencode($language ?? 'English') }}"
                   class="text-decoration-none">
                    <div class="card h-100 {{ $imported > 0 ? 'border-success' : '' }}">
                        <div class="card-body py-2 px-3">
                            <div class="fw-semibold">{{ $genre }}</div>
                            @if($synced)
                                <div class="small text-muted">{{ number_format($total) }}{{ $total >= 500 ? '+' : '' }} books</div>
                            @endif
                            @if($imported > 0)
                                <div class="small text-success">{{ $imported }} imported</div>
                            @endif
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
</div>
@endsection
