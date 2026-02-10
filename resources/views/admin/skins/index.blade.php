@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Manage Skins</h1>
            <a href="{{ route('gallery.skins.create') }}" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Upload New Skin
            </a>
        </div>

        <div class="card mb-4 shadow-sm border-0">
            <div class="card-body">
                <form action="{{ route('admin.skins.index') }}" method="GET" class="row g-3">
                    <div class="col-md-8">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" class="form-control border-start-0" placeholder="Search skins by name, author, or description..." name="search" value="{{ $search }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select" onchange="this.form.submit()">
                            <option value="recent" {{ $sort == 'recent' ? 'selected' : '' }}>Most Recent</option>
                            <option value="popular" {{ $sort == 'popular' ? 'selected' : '' }}>Most popular</option>
                            <option value="top_rated" {{ $sort == 'top_rated' ? 'selected' : '' }}>Top Rated</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-outline-secondary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        @if(count($skins) > 0)
            <div class="card shadow-sm border-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-4">Skin</th>
                                <th>Author</th>
                                <th>Version</th>
                                <th>Stats</th>
                                <th>Visibility</th>
                                <th>Created</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($skins as $skin)
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            @if($skin['previewUrl'])
                                                <img src="{{ $skin['previewUrl'] }}" alt="{{ $skin['name'] }}" class="rounded me-3" style="width: 48px; height: 48px; object-fit: cover;">
                                            @else
                                                <div class="rounded bg-light d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px;">
                                                    <i class="fas fa-image text-muted"></i>
                                                </div>
                                            @endif
                                            <div>
                                                <div class="fw-bold text-dark">{{ $skin['name'] }}</div>
                                                <div class="text-muted small text-truncate" style="max-width: 250px;">{{ $skin['description'] }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $skin['author'] }}</td>
                                    <td><span class="badge bg-light text-dark border">{{ $skin['version'] }}</span></td>
                                    <td>
                                        <div class="small">
                                            <div class="mb-1"><i class="fas fa-download me-1 text-primary"></i> {{ $skin['downloadCount'] }}</div>
                                            <div><i class="fas fa-star me-1 text-warning"></i> {{ $skin['averageRating'] }} ({{ $skin['ratingCount'] }})</div>
                                        </div>
                                    </td>
                                    <td>
                                        @if($skin['isPublic'])
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                <i class="fas fa-globe me-1"></i> Public
                                            </span>
                                        @else
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                                <i class="fas fa-lock me-1"></i> Private
                                            </span>
                                        @endif
                                    </td>
                                    <td>{{ \Carbon\Carbon::parse($skin['createdAt'])->format('M d, Y') }}</td>
                                    <td class="text-end pe-4">
                                        <div class="btn-group btn-group-sm">
                                            <a href="{{ route('gallery.skins.show', $skin['id']) }}" class="btn btn-outline-secondary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('admin.skins.edit', $skin['id']) }}" class="btn btn-outline-secondary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form action="{{ route('admin.skins.destroy', $skin['id']) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this skin?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4 d-flex justify-content-center">
                {{-- Simple pagination since we are using arrays from service --}}
                <nav>
                    <ul class="pagination">
                        @if($pagination['current_page'] > 1)
                            <li class="page-item">
                                <a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $pagination['current_page'] - 1]) }}">Previous</a>
                            </li>
                        @endif
                        <li class="page-item active"><span class="page-link">{{ $pagination['current_page'] }} / {{ $pagination['last_page'] }}</span></li>
                        @if($pagination['current_page'] < $pagination['last_page'])
                            <li class="page-item">
                                <a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $pagination['current_page'] + 1]) }}">Next</a>
                            </li>
                        @endif
                    </ul>
                </nav>
            </div>
        @else
            <div class="text-center py-5">
                <i class="fas fa-paint-brush fa-3x text-muted mb-3"></i>
                <p class="lead text-muted">No skins found match your search.</p>
                <a href="{{ route('admin.skins.index') }}" class="btn btn-link">Clear and view all</a>
            </div>
        @endif
    </div>
@endsection
