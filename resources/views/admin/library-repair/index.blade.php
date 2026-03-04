@extends('layouts.app')

@section('content')
    @php
        $isPaginated = is_object($issues) && method_exists($issues, 'withQueryString');
        $issuesCount = (is_object($issues) && method_exists($issues, 'total'))
            ? $issues->total()
            : (is_countable($issues ?? null) ? count($issues) : 0);
    @endphp

    <div class="container">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h1 class="h3 mb-1">Library Repair</h1>
                <p class="text-muted mb-0">
                    Monitor and resolve directory issues detected by the library repair scanner.
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.needs_review.index') }}" class="btn btn-outline-secondary">
                    Needs Review
                </a>
                <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">
                    Admin Books
                </a>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.library-repair.index') }}" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="issue_type" class="form-label">Issue type</label>
                        <select id="issue_type" name="issue_type" class="form-select">
                            <option value="">All types</option>
                            @foreach($issueTypes as $value => $label)
                                <option value="{{ $value }}" @selected($filters['issue_type'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="search" class="form-label">Search</label>
                        <input type="search" id="search" name="search" class="form-control" value="{{ $filters['search'] }}"
                            placeholder="Title or directory" />
                    </div>
                    <div class="col-md-2">
                        <label for="limit" class="form-label">Per page</label>
                        <select id="limit" name="limit" class="form-select">
                            @foreach([10, 25, 50, 100] as $limitOption)
                                <option value="{{ $limitOption }}" @selected($filters['limit'] == $limitOption)>{{ $limitOption }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 form-check align-self-center mt-4 pt-1">
                        <input class="form-check-input" type="checkbox" value="1" id="show_resolved" name="show_resolved"
                            @checked($filters['show_resolved'])>
                        <label class="form-check-label" for="show_resolved">
                            Show resolved
                        </label>
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-primary">
                            Apply filters
                        </button>
                        <a href="{{ route('admin.library-repair.index') }}" class="btn btn-link">
                            Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        @if($issuesCount === 0)
            <div class="alert alert-success">
                <strong>No issues found.</strong> Run the library repair scan to generate new findings.
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle table-striped">
                    <thead>
                        <tr>
                            <th scope="col">Issue</th>
                            <th scope="col">Directory / Book</th>
                            <th scope="col">Details</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($issues as $issue)
                            <tr>
                                <td style="min-width: 140px;">
                                    <div class="d-flex flex-column gap-1">
                                        <span class="badge text-bg-secondary text-uppercase">
                                            {{ $issue['issueLabel'] ?? str($issue['issueType'])->replace('_', ' ')->headline() }}
                                        </span>
                                        @if($issue['autoResolved'])
                                            <span class="badge text-bg-success">Auto resolved</span>
                                        @endif
                                    </div>
                                </td>
                                <td style="min-width: 220px;">
                                    <div class="small">
                                        <div class="fw-semibold">
                                            {{ $issue['directoryPath'] ?? '—' }}
                                        </div>
                                        @if(!empty($issue['book']))
                                            <div class="mt-1">
                                                <a href="{{ route('admin.books.edit', $issue['book']['id']) }}"
                                                    class="fw-semibold text-decoration-none">
                                                    {{ $issue['book']['title'] }}
                                                </a>
                                                @if(!empty($issue['book']['authors']))
                                                    <div class="text-muted">
                                                        {{ implode(', ', $issue['book']['authors']) }}
                                                    </div>
                                                @endif
                                                <div class="text-muted">
                                                    Book ID: {{ $issue['book']['id'] }}
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-muted mt-1">
                                                Unlinked directory
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @php
                                        $metadata = $issue['metadata'] ?? [];
                                        $issueType = $issue['issueType'];
                                    @endphp
                                    <div class="small d-flex flex-column gap-2">
                                        <div>
                                            <strong>Directory:</strong> {{ $issue['directoryPath'] ?? '—' }}
                                        </div>
                                        @if(!empty($issue['book']))
                                            <div>
                                                <strong>Book:</strong>
                                                <a href="{{ route('admin.books.edit', $issue['book']['id']) }}"
                                                    class="text-decoration-none">
                                                    {{ $issue['book']['title'] }}
                                                </a>
                                                <span class="text-muted">(#{{ $issue['book']['id'] }})</span>
                                            </div>
                                        @endif
                                        @if($issueType === \App\Enums\LibraryRepairIssueType::MISSING_DIRECTORY->value)
                                            <div class="text-muted">
                                                Directory expected on disk but not found.
                                            </div>
                                            @if(!empty($issue['audiobookBayUrl']))
                                                <div>
                                                    <a href="{{ $issue['audiobookBayUrl'] }}" target="_blank" rel="noopener noreferrer"
                                                        class="link-primary">
                                                        Search on AudiobookBay
                                                    </a>
                                                </div>
                                            @endif
                                        @elseif($issueType === \App\Enums\LibraryRepairIssueType::DUPLICATE_DIRECTORY->value)
                                            <div>
                                                <strong>Conflicting book IDs:</strong>
                                                {{ implode(', ', $metadata['book_ids'] ?? []) ?: 'Unknown' }}
                                            </div>
                                        @elseif($issueType === \App\Enums\LibraryRepairIssueType::ORPHAN_DIRECTORY->value)
                                            <div>
                                                <strong>Filesystem path:</strong> {{ $metadata['full_path'] ?? 'Unknown' }}
                                            </div>
                                            <div class="text-muted">
                                                Contains audio files but no book references this path.
                                            </div>
                                        @elseif($issueType === \App\Enums\LibraryRepairIssueType::NESTED_AUDIO->value)
                                            <div class="text-muted">
                                                Audio files detected only inside a nested folder. Consider updating the book directory.
                                            </div>
                                        @elseif($issueType === \App\Enums\LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY->value)
                                            <div class="text-muted">
                                                Directory ends with a numeric suffix but no base directory exists.
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td style="min-width: 180px;">
                                    <div class="small">
                                        <div class="fw-semibold text-uppercase">{{ $issue['status'] }}</div>
                                        <div class="text-muted">
                                            Created:
                                            {{ $issue['createdAt'] ? \Illuminate\Support\Carbon::parse($issue['createdAt'])->diffForHumans() : '—' }}
                                        </div>
                                        @if($issue['resolvedAt'])
                                            <div class="text-muted">
                                                Resolved: {{ \Illuminate\Support\Carbon::parse($issue['resolvedAt'])->diffForHumans() }}
                                            </div>
                                        @endif
                                        @if(!empty($issue['resolutionNotes']))
                                            <div class="mt-2">
                                                <strong>Notes:</strong>
                                                <div>{{ $issue['resolutionNotes'] }}</div>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-end">
                                    @if(empty($issue['isResolved']))
                                        <div class="d-flex flex-column gap-2 align-items-end">
                                            @if(
                                                $issue['issueType'] === \App\Enums\LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY->value
                                                && !empty($issue['book'])
                                            )
                                                <a href="{{ route('admin.books.edit', $issue['book']['id']) }}"
                                                    class="btn btn-sm btn-outline-secondary w-100">
                                                    Edit book
                                                </a>
                                            @endif
                                            <form method="POST" action="{{ route('admin.library-repair.rescan', $issue['id']) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    Rescan issue
                                                </button>
                                            </form>

                                            @if($issue['issueType'] === \App\Enums\LibraryRepairIssueType::MISSING_DIRECTORY->value)
                                                <form method="POST"
                                                    action="{{ route('admin.library-repair.import-missing', $issue['id']) }}" class="w-100"
                                                    style="min-width: 220px;">
                                                    @csrf
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" name="import_path" class="form-control"
                                                            placeholder="Filesystem path" required>
                                                        <button type="submit" class="btn btn-success">
                                                            Import
                                                        </button>
                                                    </div>
                                                </form>
                                            @elseif($issue['issueType'] === \App\Enums\LibraryRepairIssueType::DUPLICATE_DIRECTORY->value)
                                                <a href="{{ route('admin.library-repair.compare', $issue['id']) }}"
                                                    class="btn btn-sm btn-warning w-100">
                                                    View &amp; Resolve Duplicates
                                                </a>
                                            @else
                                                <form method="POST" action="{{ route('admin.library-repair.resolve', $issue['id']) }}"
                                                    class="w-100" style="min-width: 220px;">
                                                    @csrf
                                                    <input type="text" name="resolution_notes" class="form-control form-control-sm mb-2"
                                                        placeholder="Resolution notes (optional)" maxlength="500" />
                                                    <button type="submit" class="btn btn-sm btn-success w-100">
                                                        Mark resolved
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-muted small">No actions available</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($isPaginated)
                <div class="d-flex justify-content-center mt-4">
                    {{ $issues->withQueryString()->links('pagination::bootstrap-5') }}
                </div>
            @endif
        @endif
    </div>

    <style>
        .metadata-list dt {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--bs-secondary-color);
            margin-bottom: 0.25rem;
        }

        .metadata-list dd {
            margin-bottom: 1rem;
        }

        .metadata-list dd:last-child {
            margin-bottom: 0;
        }
    </style>
@endsection
