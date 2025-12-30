@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Manage Series</h1>
        <form method="GET" action="{{ route('admin.series.manage') }}" class="d-flex">
            <input type="text" name="search" class="form-control me-2" placeholder="Search series..." value="{{ $search }}">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if(session('warning'))
        @php $warning = session('warning'); @endphp
        <div class="alert alert-warning">
            <p><strong>{{ $warning['message'] }}</strong></p>
            <p>This will move {{ $warning['book_count'] }} book(s) from "{{ $warning['old_name'] }}" to "{{ $warning['new_name'] }}".</p>
            <form method="POST" action="{{ route('admin.series.rename') }}" class="d-inline">
                @csrf
                <input type="hidden" name="old_name" value="{{ $warning['old_name'] }}">
                <input type="hidden" name="new_name" value="{{ $warning['new_name'] }}">
                <input type="hidden" name="merge" value="1">
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-compress-alt me-2"></i>Yes, Merge Series
                </button>
                <a href="{{ route('admin.series.manage') }}" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    @endif

    {{-- Potential Merges Section --}}
    @if(count($potentialMerges) > 0)
    <div class="card mb-4">
        <div class="card-header bg-warning">
            <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Potential Merges Detected</h5>
        </div>
        <div class="card-body">
            <p>These books appear to be split across multiple subdirectories and may need to be merged:</p>
            @foreach($potentialMerges as $merge)
            <div class="card mb-3">
                <div class="card-header">
                    <strong>{{ $merge['series'] }}</strong> - {{ $merge['parentPath'] }}
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.series.merge') }}">
                        @csrf
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Select</th>
                                        <th>Primary</th>
                                        <th>Title</th>
                                        <th>Directory</th>
                                        <th>Files</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($merge['books'] as $book)
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="book_ids[]" value="{{ $book['_id'] }}" class="merge-checkbox" data-group="{{ $loop->parent->index }}">
                                        </td>
                                        <td>
                                            <input type="radio" name="primary_book_id" value="{{ $book['_id'] }}" class="primary-radio" data-group="{{ $loop->parent->index }}">
                                        </td>
                                        <td>{{ $book['title'] ?? 'N/A' }}</td>
                                        <td><small>{{ $book['directoryPath'] ?? 'N/A' }}</small></td>
                                        <td>{{ $book['audioFileCount'] ?? 0 }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="form-check mb-2">
                            <input type="checkbox" name="flatten_directories" value="1" class="form-check-input" id="flatten-{{ $loop->index }}">
                            <label class="form-check-label" for="flatten-{{ $loop->index }}">
                                Flatten directories (move all audio files to primary directory)
                            </label>
                        </div>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-compress-alt me-2"></i>Merge Selected Books
                        </button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- All Series Section --}}
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">All Series ({{ count($seriesGroups) }})</h5>
        </div>
        <div class="card-body">
            @if(count($seriesGroups) === 0)
                <p class="text-muted">No series found.</p>
            @else
                <div class="accordion" id="seriesAccordion">
                    @foreach($seriesGroups as $seriesName => $books)
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="heading-{{ $loop->index }}">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-{{ $loop->index }}">
                                <strong>{{ $seriesName }}</strong>
                                <span class="badge bg-primary ms-2">{{ count($books) }} book(s)</span>
                            </button>
                        </h2>
                        <div id="collapse-{{ $loop->index }}" class="accordion-collapse collapse" data-bs-parent="#seriesAccordion">
                            <div class="accordion-body">
                                <div class="mb-3">
                                    @if(isset($seriesIds[$seriesName]))
                                        <a href="{{ route('admin.series.edit', $seriesIds[$seriesName]) }}" class="btn btn-sm btn-primary">
                                            <i class="fas fa-pencil-alt me-1"></i>Edit Series
                                        </a>
                                    @endif
                                    <button class="btn btn-sm btn-secondary" onclick="showRenameForm('{{ $seriesName }}', {{ $loop->index }})">
                                        <i class="fas fa-edit me-1"></i>Rename Series
                                    </button>
                                </div>
                                <div id="rename-form-{{ $loop->index }}" style="display: none;" class="mb-3">
                                    <form method="POST" action="{{ route('admin.series.rename') }}" class="row g-2">
                                        @csrf
                                        <input type="hidden" name="old_name" value="{{ $seriesName }}">
                                        <div class="col-auto">
                                            <input type="text" name="new_name" class="form-control" placeholder="New series name" required>
                                        </div>
                                        <div class="col-auto">
                                            <button type="submit" class="btn btn-primary">Save</button>
                                            <button type="button" class="btn btn-secondary" onclick="hideRenameForm({{ $loop->index }})">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Author</th>
                                            <th>Directory</th>
                                            <th>Files</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($books as $book)
                                        <tr>
                                            <td>{{ $book['title'] ?? 'N/A' }}</td>
                                            <td>
                                                @if(is_array($book['author'] ?? null))
                                                    {{ implode(', ', $book['author']) }}
                                                @else
                                                    {{ $book['author'] ?? 'N/A' }}
                                                @endif
                                            </td>
                                            <td><small>{{ $book['directoryPath'] ?? 'N/A' }}</small></td>
                                            <td>{{ $book['audioFileCount'] ?? 0 }}</td>
                                            <td>
                                                <a href="{{ route('admin.books.edit', $book['_id']) }}" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

<script>
function showRenameForm(seriesName, index) {
    document.getElementById('rename-form-' + index).style.display = 'block';
}

function hideRenameForm(index) {
    document.getElementById('rename-form-' + index).style.display = 'none';
}

// Auto-check primary radio when checkbox is selected
document.querySelectorAll('.merge-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        if (this.checked) {
            const group = this.dataset.group;
            const radio = this.closest('tr').querySelector('.primary-radio');
            const groupCheckboxes = document.querySelectorAll(`.merge-checkbox[data-group="${group}"]`);
            const checkedCount = Array.from(groupCheckboxes).filter(cb => cb.checked).length;
            
            // If this is the first checkbox checked, make it primary
            if (checkedCount === 1) {
                radio.checked = true;
            }
        }
    });
});
</script>
@endsection
