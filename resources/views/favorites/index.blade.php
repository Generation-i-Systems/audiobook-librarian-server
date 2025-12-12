@extends('layouts.app')

@section('content')
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">
                        <i class="fas fa-star"></i> Favorite Authors
                    </h4>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show">
                            {{ session('success') }}
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="alert alert-danger alert-dismissible fade show">
                            {{ session('error') }}
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                        </div>
                    @endif

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Add Favorite Author</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="{{ route('favorites.store') }}">
                                        @csrf
                                        <div class="form-group">
                                            <label for="author_name">Author Name</label>
                                            <input type="text"
                                                   class="form-control @error('author_name') is-invalid @enderror"
                                                   id="author_name"
                                                   name="author_name"
                                                   list="authors-list"
                                                   placeholder="Enter or select author name"
                                                   required>
                                            <datalist id="authors-list">
                                                @foreach($allAuthors as $author)
                                                    <option value="{{ $author->name }}">
                                                @endforeach
                                            </datalist>
                                            @error('author_name')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-plus"></i> Add to Favorites
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">About Favorite Authors</h5>
                                </div>
                                <div class="card-body">
                                    <p>When you favorite an author, the system will:</p>
                                    <ul>
                                        <li>Scrape AudiobookBay daily for new releases</li>
                                        <li>Send you email notifications for new books</li>
                                        <li>Track discovered books in your dashboard</li>
                                    </ul>
                                    <p class="mb-0"><strong>Categories monitored:</strong> Sci-Fi, Fantasy, LitRPG</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                Your Favorite Authors ({{ $favorites->count() }})
                            </h5>
                        </div>
                        <div class="card-body">
                            @if($favorites->isEmpty())
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i>
                                    You haven't added any favorite authors yet. Add one above to start receiving notifications!
                                </div>
                            @else
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Author Name</th>
                                                <th>Email Notifications</th>
                                                <th>Added On</th>
                                                <th style="width: 200px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($favorites as $favorite)
                                                <tr>
                                                    <td>
                                                        <strong>{{ $favorite->author_name }}</strong>
                                                    </td>
                                                    <td>
                                                        @if($favorite->notify_email)
                                                            <span class="badge badge-success">
                                                                <i class="fas fa-check"></i> Enabled
                                                            </span>
                                                        @else
                                                            <span class="badge badge-secondary">
                                                                <i class="fas fa-times"></i> Disabled
                                                            </span>
                                                        @endif
                                                    </td>
                                                    <td>{{ $favorite->created_at->format('M d, Y') }}</td>
                                                    <td>
                                                        <form method="POST"
                                                              action="{{ route('favorites.toggle-notifications', $favorite) }}"
                                                              style="display: inline;">
                                                            @csrf
                                                            @method('PATCH')
                                                            <button type="submit"
                                                                    class="btn btn-sm btn-{{ $favorite->notify_email ? 'warning' : 'success' }}"
                                                                    title="{{ $favorite->notify_email ? 'Disable' : 'Enable' }} notifications">
                                                                <i class="fas fa-bell{{ $favorite->notify_email ? '-slash' : '' }}"></i>
                                                            </button>
                                                        </form>

                                                        <form method="POST"
                                                              action="{{ route('favorites.destroy', $favorite) }}"
                                                              style="display: inline;"
                                                              onsubmit="return confirm('Remove {{ $favorite->author_name }} from favorites?');">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="btn btn-sm btn-danger" title="Remove from favorites">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
