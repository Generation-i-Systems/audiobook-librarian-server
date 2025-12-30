@php
    $entityType = $results['entity_type'] ?? 'books';
    $items = $results['items'] ?? $results['books'] ?? [];
    $entityLabel = ucfirst($entityType);
    $entityLabelSingular = rtrim($entityLabel, 's');

    $page = (int) request()->input('page', 1);
    $perPage = 100;
    $total = count($items);
    $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
    $offset = ($page - 1) * $perPage;
    $paginatedItems = array_slice($items, $offset, $perPage);
@endphp

<div class="card">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fas fa-list"></i> {{ $entityLabel }}
            @if($total > 0)
                ({{ $total }} results)
            @endif
        </h5>
    </div>
    <div class="card-body">
        @if(count($items) > 0)
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            @if($entityType === 'books')
                                <th>Title</th>
                                <th>Authors</th>
                                <th>Genres</th>
                                <th>Series</th>
                                <th>Directory Path</th>
                            @elseif(in_array($entityType, ['authors', 'genres', 'series', 'narrators']))
                                <th>Name</th>
                                <th>Book Count</th>
                            @else
                                <th>ID</th>
                                @foreach(array_keys($items[0] ?? []) as $key)
                                    @if($key !== 'id' && $key !== 'edit_url')
                                        <th>{{ ucfirst(str_replace('_', ' ', $key)) }}</th>
                                    @endif
                                @endforeach
                            @endif
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($paginatedItems as $item)
                            <tr>
                                @if($entityType === 'books')
                                    <td>{{ $item['title'] ?? 'Unknown' }}</td>
                                    <td>
                                        @if(isset($item['authors']) && is_array($item['authors']))
                                            {{ implode(', ', $item['authors']) }}
                                        @else
                                            {{ $item['authors'] ?? '-' }}
                                        @endif
                                    </td>
                                    <td>
                                        @if(isset($item['genres']) && is_array($item['genres']))
                                            {{ implode(', ', $item['genres']) }}
                                        @else
                                            {{ $item['genres'] ?? '-' }}
                                        @endif
                                    </td>
                                    <td>{{ $item['series'] ?? '-' }}</td>
                                    <td><small class="text-muted">{{ $item['directory_path'] ?? '-' }}</small></td>
                                @elseif(in_array($entityType, ['authors', 'genres', 'series', 'narrators']))
                                    <td>{{ $item['name'] ?? 'Unknown' }}</td>
                                    <td>{{ $item['book_count'] ?? 0 }}</td>
                                @else
                                    <td>{{ $item['id'] ?? '-' }}</td>
                                    @foreach(array_keys($item) as $key)
                                        @if($key !== 'id' && $key !== 'edit_url')
                                            <td>
                                                @if(is_array($item[$key]))
                                                    {{ implode(', ', $item[$key]) }}
                                                @else
                                                    {{ $item[$key] }}
                                                @endif
                                            </td>
                                        @endif
                                    @endforeach
                                @endif
                                <td>
                                    @if(isset($item['edit_url']) && $item['edit_url'] !== '#')
                                        <a href="{{ $item['edit_url'] }}" class="btn btn-sm btn-primary">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($totalPages > 1)
                <nav aria-label="Results pagination" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <li class="page-item {{ $page <= 1 ? 'disabled' : '' }}">
                            <a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}">Previous</a>
                        </li>

                        @for($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++)
                            <li class="page-item {{ $i == $page ? 'active' : '' }}">
                                <a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $i]) }}">{{ $i }}</a>
                            </li>
                        @endfor

                        <li class="page-item {{ $page >= $totalPages ? 'disabled' : '' }}">
                            <a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}">Next</a>
                        </li>
                    </ul>
                    <p class="text-center text-muted small">
                        Showing {{ $offset + 1 }}-{{ min($offset + $perPage, $total) }} of {{ $total }} results
                    </p>
                </nav>
            @endif
        @else
            <p class="text-muted">No {{ strtolower($entityLabel) }} found matching your criteria.</p>
        @endif
    </div>
</div>
