@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Edit Series</h1>
            <a href="{{ route('series.manage') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to Series
            </a>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">Series Details</div>
                    <div class="card-body">
                        <form action="{{ route('admin.series.update', $series['id']) }}" method="POST">
                            @csrf
                            @method('PUT')

                            <div class="form-group mb-3">
                                <label for="name">Name:</label>
                                <input type="text" class="form-control" id="name" name="name" value="{{ $series['name'] }}" required>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 mb-2">
                                <i class="fas fa-save"></i> Update Series
                            </button>
                        </form>

                        <form action="{{ route('admin.series.destroy', $series['id']) }}" method="POST" class="mt-2">
                            @csrf
                            @method('DELETE')
                            @if($total > 0)
                                <button type="button" class="btn btn-outline-danger w-100" disabled data-bs-toggle="tooltip" title="Cannot delete series with books. Remove all books first.">
                                    <i class="fas fa-trash-alt"></i> Delete Series ({{ $total }} books)
                                </button>
                            @else
                                <button type="submit" class="btn btn-outline-danger w-100" onclick="return confirm('Are you sure you want to delete this series?')">
                                    <i class="fas fa-trash-alt"></i> Delete Series
                                </button>
                            @endif
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        Books in {{ $series['name'] }}
                        @if($total > 0)
                            ({{ $total }} total)
                        @endif
                    </div>
                    <div class="card-body">
                        @if(count($books) > 0)
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Authors</th>
                                            <th>Genres</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($books as $book)
                                            <tr>
                                                <td>{{ $book['title'] ?? 'Unknown' }}</td>
                                                <td>
                                                    @if(isset($book['authors']) && is_array($book['authors']))
                                                        {{ implode(', ', array_column($book['authors'], 'name')) }}
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                                <td>
                                                    @if(isset($book['genres']) && is_array($book['genres']))
                                                        {{ implode(', ', array_column($book['genres'], 'name')) }}
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                                <td>
                                                    <a href="{{ route('admin.books.edit', $book['id']) }}" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if($totalPages > 1)
                                <nav aria-label="Books pagination">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item {{ $currentPage <= 1 ? 'disabled' : '' }}">
                                            <a class="page-link" href="{{ route('admin.series.edit', ['series' => $series['id'], 'page' => $currentPage - 1]) }}">Previous</a>
                                        </li>

                                        @for($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++)
                                            <li class="page-item {{ $i == $currentPage ? 'active' : '' }}">
                                                <a class="page-link" href="{{ route('admin.series.edit', ['series' => $series['id'], 'page' => $i]) }}">{{ $i }}</a>
                                            </li>
                                        @endfor

                                        <li class="page-item {{ $currentPage >= $totalPages ? 'disabled' : '' }}">
                                            <a class="page-link" href="{{ route('admin.series.edit', ['series' => $series['id'], 'page' => $currentPage + 1]) }}">Next</a>
                                        </li>
                                    </ul>
                                </nav>
                            @endif
                        @else
                            <p class="text-muted text-center py-4">No books found in this series.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
@endsection
