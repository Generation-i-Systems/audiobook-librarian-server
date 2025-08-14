@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Books Needing Review</h1>
        <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Back to Admin Books</a>
    </div>

    <form method="GET" action="{{ route('admin.needs_review.index') }}" class="row g-2 mb-3">
        <div class="col-sm-6 col-md-4">
            <label for="reason" class="form-label">Filter by reason</label>
            <select id="reason" name="reason" class="form-select" onchange="this.form.submit()">
                <option value="">All reasons</option>
                @foreach ($reasons as $r)
                    <option value="{{ $r }}" {{ ($selectedReason === $r) ? 'selected' : '' }}>{{ $r }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-3 col-md-2">
            <label for="limit" class="form-label">Per page</label>
            <select id="limit" name="limit" class="form-select" onchange="this.form.submit()">
                @foreach ([10,20,50,100] as $opt)
                    <option value="{{ $opt }}" {{ ($limit == $opt) ? 'selected' : '' }}>{{ $opt }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if ($books->count() === 0)
        <div class="alert alert-info">No books are currently flagged for review.</div>
    @else
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th scope="col">Title</th>
                        <th scope="col">Directory</th>
                        <th scope="col">Reasons</th>
                        <th scope="col">Created</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($books as $book)
                        <tr>
                            <td>{{ $book['title'] }}</td>
                            <td><code>{{ $book['directoryPath'] }}</code></td>
                            <td>
                                @if (!empty($book['needsReviewReasons']))
                                    <ul class="mb-0 ps-3">
                                        @foreach ($book['needsReviewReasons'] as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $book['createdAt'] ? \Illuminate\Support\Carbon::parse($book['createdAt'])->toDateString() : '' }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.books.edit', $book['id']) }}" class="btn btn-sm btn-primary">Edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-center">
            {{ $books->withQueryString()->links() }}
        </div>
    @endif
</div>
@endsection
