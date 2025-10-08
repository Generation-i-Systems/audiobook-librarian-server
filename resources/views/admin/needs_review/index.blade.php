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
            <select id="reason" name="reason" class="form-select" onchange="if(this.value === '') { window.location.href = '{{ route('admin.needs_review.index') }}?limit={{ $limit }}'; } else { this.form.submit(); }">
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
                                            @php
                                                // Extract base reason (before "Parsed:" or "Document:" details)
                                                $parts = preg_split('/(Parsed:|Document:)/', $reason);
                                                $baseReason = trim($parts[0]);
                                            @endphp
                                            <li>{{ $baseReason }}</li>
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
            {{ $books->withQueryString()->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>

<style>
/* Fix pagination arrow sizing */
.pagination .page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2.5rem;
    padding: 0.375rem 0.75rem;
}
.pagination svg {
    width: 1em;
    height: 1em;
    display: inline-block;
    vertical-align: middle;
}
</style>
@endsection
