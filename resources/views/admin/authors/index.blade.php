@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Manage Authors</h1>

        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @include('components.ai-query-prompt')

        {{-- Selected for Merge Section --}}
        @if(count($selectedAuthors) > 0)
            <div class="card mb-4 border-warning">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-users-cog me-2"></i>Selected for Merge ({{ count($selectedAuthors) }})</h5>
                    <form action="{{ route('admin.authors.clear-merge') }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-dark">Clear All</button>
                    </form>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.authors.merge') }}" method="POST">
                        @csrf
                        <div class="row">
                            <div class="col-md-8">
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach($selectedAuthors as $selAuthor)
                                        <div class="badge bg-light text-dark border p-2 d-flex align-items-center">
                                            <input type="checkbox" name="author_ids[]" value="{{ $selAuthor['id'] }}" checked class="form-check-input me-2 merge-toggle-checkbox" data-id="{{ $selAuthor['id'] }}">
                                            {{ $selAuthor['name'] }}
                                            <span class="text-muted ms-2 small">({{ $selAuthor['bookCount'] }} books)</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="row g-2 align-items-end">
                                    <div class="col-12">
                                        <label for="primary_author_id" class="form-label">Primary Author</label>
                                        <select name="primary_author_id" id="primary_author_id" class="form-select" required>
                                            <option value="">Select primary...</option>
                                            @foreach($selectedAuthors as $selAuthorOption)
                                                <option value="{{ $selAuthorOption['id'] }}">{{ $selAuthorOption['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12 d-grid">
                                        <button type="submit" class="btn btn-danger" {{ count($selectedAuthors) < 2 ? 'disabled' : '' }}>
                                            <i class="fas fa-compress-alt"></i> Merge Selected
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        <form action="{{ route('admin.authors.index') }}" method="GET" class="mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" id="search" class="form-control" placeholder="Search author name" name="search"
                        value="{{ $search }}">
                </div>
                <div class="col-md-2">
                    <label for="sort" class="form-label">Sort by</label>
                    <select name="sort" id="sort" class="form-select">
                        <option value="name" {{ $sort === 'name' ? 'selected' : '' }}>Name</option>
                        <option value="books" {{ $sort === 'books' ? 'selected' : '' }}>Book count</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="direction" class="form-label">Direction</label>
                    <select name="direction" id="direction" class="form-select">
                        <option value="asc" {{ $direction === 'asc' ? 'selected' : '' }}>Ascending</option>
                        <option value="desc" {{ $direction === 'desc' ? 'selected' : '' }}>Descending</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="perPage" class="form-label">Per Page</label>
                    <select name="perPage" id="perPage" class="form-select">
                        <option value="25" {{ request('perPage') == 25 ? 'selected' : '' }}>25</option>
                        <option value="50" {{ request('perPage') == 50 ? 'selected' : '' }}>50</option>
                        <option value="100" {{ request('perPage') == 100 ? 'selected' : '' }}>100</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-outline-secondary" type="submit">Apply</button>
                </div>
            </div>
        </form>

        <div class="mb-3">
            <a href="{{ route('admin.authors.create') }}" class="btn btn-primary">Add New Author</a>
        </div>

        <div class="mb-2 text-muted small">
            <span>Total authors: <strong>{{ $authors->total() }}</strong></span>
            <span class="ms-3">Showing {{ $authors->count() }} of {{ $authors->total() }} authors</span>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>Merge</th>
                    <th>Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($authors as $author)
                    <tr class="{{ in_array($author->id, $selectedMergeIds) ? 'table-warning' : ($loop->iteration % 2 == 0 ? 'table-secondary' : '') }}">
                        <td>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input merge-toggle-checkbox"
                                    data-id="{{ $author->id }}"
                                    {{ in_array($author->id, $selectedMergeIds) ? 'checked' : '' }}>
                            </div>
                        </td>
                        <td>
                            <div>{{ $author->name }}</div>
                            <div class="text-muted small">{{ $author->book_count ?? 0 }} book(s)</div>
                        </td>
                        <td>
                            <a href="{{ route('admin.books.create') }}?author_id={{ $author->id }}"
                                class="btn btn-sm btn-outline-success" title="Add Book"><i
                                    class="fas fa-plus-circle"></i></a>
                            <a href="{{ route('admin.authors.edit', $author->id) }}"
                                class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pencil-alt"></i></a>
                            <a href="{{ route('admin.authors.browse', $author->id) }}"
                                class="btn btn-sm btn-outline-secondary" title="Browse"><i class="fas fa-sitemap"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Delete"
                                onclick="if(confirm('Are you sure you want to delete author &quot;{{ addslashes($author->name) }}&quot;?')) { document.getElementById('delete-author-form').action = '{{ route('admin.authors.destroy', $author->id) }}'; document.getElementById('delete-author-form').submit(); }">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-muted">No authors found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="mt-3">
            {{ $authors->appends(request()->except('page'))->onEachSide(2)->links('pagination.admin-books') }}
        </div>

        <form id="delete-author-form" action="" method="POST" style="display: none;">
            @csrf
            @method('DELETE')
            <input type="hidden" name="return_url" value="{{ request()->fullUrl() }}">
        </form>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('.merge-toggle-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const id = this.dataset.id;
                    const isChecked = this.checked;

                    fetch('{{ route('admin.authors.toggle-merge') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({ id: id })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Reload to update the "Selected for Merge" section
                            window.location.reload();
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to update merge selection.');
                        this.checked = !isChecked; // Revert checkbox
                    });
                });
            });
        });
    </script>
@endsection
