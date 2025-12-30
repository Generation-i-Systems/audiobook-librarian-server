@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2><i class="fas fa-trash me-2"></i>Trash Management</h2>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Total Items</h5>
                    <p class="card-text display-6">{{ $stats['total_count'] }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Total Size</h5>
                    <p class="card-text display-6">{{ \Illuminate\Support\Number::fileSize($stats['total_size']) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Actions</h5>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAllModal">
                        <i class="fas fa-trash-alt me-2"></i>Delete All
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">Auto-Cleanup Settings</div>
                <div class="card-body">
                    <form action="{{ route('admin.trash.cleanup') }}" method="POST" class="row g-3">
                        @csrf
                        <div class="col-auto">
                            <label for="cleanupDays" class="col-form-label">Remove items older than:</label>
                        </div>
                        <div class="col-auto">
                            <input type="number" class="form-control" id="cleanupDays" name="days" value="90" min="1" max="365">
                        </div>
                        <div class="col-auto">
                            <span class="col-form-label">days</span>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">Apply Cleanup</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    Trash Items ({{ $total }})
                </div>
                <div class="card-body">
                    @if(empty($items))
                        <p class="text-muted">No items in trash.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Book Title</th>
                                        <th>Deleted Date</th>
                                        <th>Files</th>
                                        <th>Size</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($items as $item)
                                        <tr>
                                            <td>{{ $item['book_title'] }}</td>
                                            <td>{{ \Carbon\Carbon::parse($item['deleted_at'])->format('Y-m-d H:i') }}</td>
                                            <td>{{ $item['file_count'] }} file(s)</td>
                                            <td>{{ \Illuminate\Support\Number::fileSize($item['size']) }}</td>
                                            <td>
                                                <form action="{{ route('admin.trash.restore', $item['id']) }}" method="POST" style="display:inline;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-success" title="Restore">
                                                        <i class="fas fa-undo"></i> Restore
                                                    </button>
                                                </form>
                                                <form action="{{ route('admin.trash.destroy', $item['id']) }}" method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this book? This cannot be undone.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-danger" title="Permanently Delete">
                                                        <i class="fas fa-times"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if($total_pages > 1)
                            <nav>
                                <ul class="pagination">
                                    @for($i = 1; $i <= $total_pages; $i++)
                                        <li class="page-item {{ $i == $current_page ? 'active' : '' }}">
                                            <a class="page-link" href="?page={{ $i }}">{{ $i }}</a>
                                        </li>
                                    @endfor
                                </ul>
                            </nav>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteAllModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Permanently Delete All?</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>This will permanently delete <strong>all {{ $stats['total_count'] }} item(s)</strong> from trash.</p>
                <p class="text-danger"><strong>This action cannot be undone!</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form action="{{ route('admin.trash.destroyAll') }}" method="POST" style="display:inline;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Yes, Delete All</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
