@extends('layouts.app')

@section('styles')
    <style>
        /* Force pagination to reasonable size */
        nav[role="navigation"] {
            font-size: 14px !important;
        }

        nav[role="navigation"] svg {
            width: 16px !important;
            height: 16px !important;
            max-width: 16px !important;
            max-height: 16px !important;
            display: inline-block !important;
        }

        nav[role="navigation"] a,
        nav[role="navigation"] span {
            padding: 0.5rem 0.75rem !important;
            font-size: 14px !important;
            line-height: 1.5 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-width: 40px !important;
            max-width: 100px !important;
            height: 40px !important;
            max-height: 40px !important;
        }

        .pagination {
            font-size: 14px !important;
        }

        .pagination .page-link {
            padding: 0.5rem 0.75rem !important;
            font-size: 14px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-width: 40px !important;
            max-width: 100px !important;
            height: 40px !important;
            max-height: 40px !important;
        }

        .pagination svg {
            width: 16px !important;
            height: 16px !important;
            max-width: 16px !important;
            max-height: 16px !important;
        }

        .pagination .page-item {
            display: inline-block !important;
        }
    </style>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-md-12">
                <h1>Directory Validation Report</h1>

                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger">{{ session('error') }}</div>
                @endif

                @if(session('info'))
                    <div class="alert alert-info">{{ session('info') }}</div>
                @endif
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Missing Directories</h5>
                        <h2 class="text-danger">{{ number_format($validationResults['missing_directories']) }}</h2>
                        <small class="text-muted">Books without physical directories</small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Orphaned Directories</h5>
                        <h2 class="text-warning">{{ number_format($validationResults['orphaned_directories']) }}</h2>
                        <small class="text-muted">Directories without database entries</small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Last Scan</h5>
                        <p class="mb-0">{{ $validationResults['last_scan'] }}</p>
                        <form action="{{ route('admin.directory-validation.rescan') }}" method="POST" class="mt-2">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary">Rescan Now</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">AI Matching</h5>
                        <form action="{{ route('admin.directory-validation') }}" method="GET">
                            <input type="hidden" name="run_matching" value="1">
                            <button type="submit" class="btn btn-sm btn-success">
                                {{ !empty($matches) ? 'Refresh Matches' : 'Run AI Matching' }}
                            </button>
                        </form>
                        <small class="text-muted mt-2 d-block">Find potential matches</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Matches Section -->
        @if(!empty($matches))
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h3>AI Suggested Matches</h3>
                        </div>
                        <div class="card-body">
                            @foreach($matches as $match)
                                <div class="border-bottom pb-3 mb-3">
                                    <h5 class="text-dark">Orphaned: <code
                                            class="bg-light text-dark">{{ $match['orphaned_path'] }}</code></h5>
                                    <small class="text-dark">Size: {{ number_format($match['orphaned_size'] / 1024 / 1024, 2) }}
                                        MB</small>

                                    <div class="mt-2">
                                        <strong class="text-dark">Suggested Matches:</strong>
                                        @foreach($match['matches'] as $suggestion)
                                            <div class="card mt-2 bg-white">
                                                <div class="card-body">
                                                    <div class="row">
                                                        <div class="col-md-8">
                                                            <h6 class="text-dark">{{ $suggestion['book_title'] }}</h6>
                                                            <p class="mb-1 text-dark"><small>Authors:
                                                                    {{ implode(', ', $suggestion['authors']) }}</small></p>
                                                            <p class="mb-1 text-dark"><small>Series:
                                                                    {{ implode(', ', $suggestion['series']) }}</small></p>
                                                            <p class="mb-1 text-dark"><small>Expected Path: <code
                                                                        class="bg-light text-dark">{{ $suggestion['book_path'] }}</code></small>
                                                            </p>
                                                            <div class="mt-2">
                                                                @foreach($suggestion['reasons'] as $reason)
                                                                    <span class="badge bg-info text-dark">{{ $reason }}</span>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4 text-end">
                                                            <h4 class="mb-3">
                                                                <span class="badge bg-success">
                                                                    {{ $suggestion['confidence'] }}% Match
                                                                </span>
                                                            </h4>

                                                            @php
                                                                $orphanData = collect($orphanedDirs)->firstWhere('path', $match['orphaned_path']);
                                                                $hasPermission = $orphanData && ($orphanData['parent_writable'] ?? true);
                                                            @endphp

                                                            @if(!$hasPermission)
                                                                <div class="alert alert-warning p-2 mb-2">
                                                                    <small>⚠️ No write permission</small>
                                                                </div>
                                                            @endif

                                                            <div class="d-grid gap-2">
                                                                @if($hasPermission)
                                                                    <form action="{{ route('admin.directory-validation.rename') }}"
                                                                        method="POST">
                                                                        @csrf
                                                                        <input type="hidden" name="book_id"
                                                                            value="{{ $suggestion['book_id'] }}">
                                                                        <input type="hidden" name="orphaned_path"
                                                                            value="{{ $match['orphaned_path'] }}">
                                                                        <button type="submit" class="btn btn-sm btn-primary w-100 mb-2"
                                                                            onclick="return confirm('Rename orphaned directory to match this book?')">
                                                                            Rename to Match
                                                                        </button>
                                                                    </form>
                                                                @else
                                                                    <button type="button" class="btn btn-sm btn-primary w-100 mb-2" disabled
                                                                        title="No write permission">
                                                                        Rename to Match
                                                                    </button>
                                                                @endif

                                                                @if($orphanData)
                                                                    <a href="{{ route('admin.books.create') }}?path={{ urlencode($orphanData['path']) }}&return_url={{ urlencode(route('admin.directory-validation')) }}"
                                                                        class="btn btn-sm btn-success w-100 mb-2">
                                                                        Import as New
                                                                    </a>

                                                                    @if($hasPermission)
                                                                        <form action="{{ route('admin.directory-validation.delete-orphan') }}"
                                                                            method="POST" class="w-100">
                                                                            @csrf
                                                                            @method('DELETE')
                                                                            <input type="hidden" name="orphaned_path"
                                                                                value="{{ $orphanData['full_path'] }}">
                                                                            <button type="submit" class="btn btn-sm btn-danger w-100"
                                                                                onclick="return confirm('Delete directory &quot;{{ addslashes($orphanData['path']) }}&quot; and all its contents? This cannot be undone!')">
                                                                                Delete Directory
                                                                            </button>
                                                                        </form>
                                                                    @else
                                                                        <button type="button" class="btn btn-sm btn-danger w-100" disabled
                                                                            title="No write permission">
                                                                            Delete Directory
                                                                        </button>
                                                                    @endif
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Books with Missing Directories -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3>Books with Missing Directories ({{ $missingBooks->total() }})</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Authors</th>
                                        <th>Series</th>
                                        <th>Expected Path</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($missingBooks as $book)
                                        <tr>
                                            <td>{{ $book->id }}</td>
                                            <td>{{ $book->title }}</td>
                                            <td>{{ $book->authors->pluck('name')->implode(', ') }}</td>
                                            <td>{{ $book->series->pluck('name')->implode(', ') }}</td>
                                            <td><code>{{ $book->directory_path }}</code></td>
                                            <td>
                            </table>
                        </div>

                        <div class="mt-3">
                            {{ $missingBooks->links('pagination::bootstrap-5') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orphaned Directories -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3>Orphaned Directories ({{ count($orphanedDirs) }})</h3>
                        @if(count($orphanedDirs) === 0)
                            <p class="text-muted mb-0">No orphaned directories found. Click "Rescan Now" above to scan for
                                orphaned directories.</p>
                        @endif
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Path</th>
                                        <th>Size</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($orphanedDirs as $orphan)
                                        <tr>
                                            <td>
                                                <code>{{ $orphan['path'] }}</code>
                                                @if(!($orphan['writable'] ?? true) || !($orphan['parent_writable'] ?? true))
                                                    <br><small class="text-danger">⚠️ Permission denied - cannot rename or
                                                        delete</small>
                                                @endif
                                            </td>
                                            <td>{{ number_format($orphan['size'] / 1024 / 1024, 2) }} MB</td>
                                            <td>
                                                <a href="{{ route('admin.books.create') }}?path={{ urlencode($orphan['path']) }}&return_url={{ urlencode(route('admin.directory-validation')) }}"
                                                    class="btn btn-sm btn-success">
                                                    Import
                                                </a>

                                                @if(($orphan['parent_writable'] ?? true))
                                                    <button type="button" class="btn btn-sm btn-warning"
                                                        onclick="showRenameModal('{{ addslashes($orphan['path']) }}', '{{ addslashes($orphan['full_path']) }}')">
                                                        Rename
                                                    </button>
                                                @else
                                                    <button type="button" class="btn btn-sm btn-warning" disabled
                                                        title="No write permission">
                                                        Rename
                                                    </button>
                                                @endif

                                                @if(($orphan['parent_writable'] ?? true))
                                                    <form action="{{ route('admin.directory-validation.delete-orphan') }}"
                                                        method="POST" class="d-inline">
                                                        @csrf
                                                        @method('DELETE')
                                                        <input type="hidden" name="orphaned_path"
                                                            value="{{ $orphan['full_path'] }}">
                                                        <button type="submit" class="btn btn-sm btn-danger"
                                                            onclick="return confirm('Delete directory &quot;{{ addslashes($orphan['path']) }}&quot; and all its contents? This cannot be undone!')">
                                                            Delete
                                                        </button>
                                                    </form>
                                                @else
                                                    <button type="button" class="btn btn-sm btn-danger" disabled
                                                        title="No write permission">
                                                        Delete
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rename Modal -->
    <div class="modal fade" id="renameModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="{{ route('admin.directory-validation.rename-orphan') }}" method="POST">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Rename Directory</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="old_path" id="rename_old_path">

                        <div class="mb-3">
                            <label class="form-label">Current Full Path:</label>
                            <p><code id="rename_current_full_path"></code></p>
                        </div>

                        <div class="mb-3">
                            <label for="rename_new_path" class="form-label">New Full Path:</label>
                            <input type="text" class="form-control" name="new_path" id="rename_new_path" required>
                            <div class="form-text">Edit the full path as needed. Path is relative to book storage root.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Rename</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
    <script>
        function showRenameModal(relativePath, fullPath) {
            console.log('showRenameModal called', { relativePath, fullPath });

            document.getElementById('rename_old_path').value = fullPath;
            document.getElementById('rename_current_full_path').textContent = relativePath;
            document.getElementById('rename_new_path').value = relativePath;

            // Try Bootstrap 5 first, then fall back to Bootstrap 4/jQuery
            try {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    console.log('Using Bootstrap 5 Modal');
                    const modal = new bootstrap.Modal(document.getElementById('renameModal'));
                    modal.show();
                } else if (typeof $ !== 'undefined' && $.fn.modal) {
                    console.log('Using jQuery/Bootstrap 4 Modal');
                    $('#renameModal').modal('show');
                } else {
                    console.error('No modal library found');
                    alert('Modal library not loaded. Please refresh the page.');
                }
            } catch (e) {
                console.error('Error showing modal:', e);
                alert('Error showing modal: ' + e.message);
            }
        }
    </script>
@endsection
