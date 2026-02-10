@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Manage Themes</h1>
            <a href="{{ route('gallery.themes.create') }}" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Create New Theme
            </a>
        </div>

        <div class="card mb-4 shadow-sm border-0">
            <div class="card-body">
                <form action="{{ route('admin.themes.index') }}" method="GET" class="row g-3">
                    <div class="col-md-8">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" class="form-control border-start-0" placeholder="Search themes by name, author, or description..." name="search" value="{{ $search }}">
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

        @if(count($themes) > 0)
            <div class="card shadow-sm border-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-muted small text-uppercase">
                            <tr>
                                <th class="ps-4">Theme</th>
                                <th>Author</th>
                                <th>Version</th>
                                <th>Stats</th>
                                <th>Visibility</th>
                                <th>Created</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($themes as $theme)
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            <div class="rounded d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; background-color: {{ $theme['themeData']['colors']['primary'] ?? '#ddd' }}; color: {{ $theme['themeData']['colors']['onPrimary'] ?? '#000' }};">
                                                <i class="fas fa-palette"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark">{{ $theme['name'] }}</div>
                                                <div class="text-muted small text-truncate" style="max-width: 250px;">{{ $theme['description'] }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $theme['author'] }}</td>
                                    <td><span class="badge bg-light text-dark border">{{ $theme['version'] }}</span></td>
                                    <td>
                                        <div class="small">
                                            <div class="mb-1"><i class="fas fa-download me-1 text-primary"></i> {{ $theme['downloadCount'] }}</div>
                                            <div><i class="fas fa-star me-1 text-warning"></i> {{ $theme['averageRating'] }} ({{ $theme['ratingCount'] }})</div>
                                        </div>
                                    </td>
                                    <td>
                                        @if($theme['isPublic'])
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                <i class="fas fa-globe me-1"></i> Public
                                            </span>
                                        @else
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                                <i class="fas fa-lock me-1"></i> Private
                                            </span>
                                        @endif
                                    </td>
                                    <td>{{ \Carbon\Carbon::parse($theme['createdAt'])->format('M d, Y') }}</td>
                                    <td class="text-end pe-4">
                                        <div class="btn-group btn-group-sm">
                                            <a href="{{ route('gallery.themes.show', $theme['id']) }}" class="btn btn-outline-secondary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('admin.themes.edit', $theme['id']) }}" class="btn btn-outline-secondary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form action="{{ route('admin.themes.destroy', $theme['id']) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this theme?')">
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
                <i class="fas fa-palette fa-3x text-muted mb-3"></i>
                <p class="lead text-muted">No themes found match your search.</p>
                <a href="{{ route('admin.themes.index') }}" class="btn btn-link">Clear and view all</a>
            </div>
        @endif
    </div>
@endsection
