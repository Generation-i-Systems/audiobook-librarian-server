@if($item['type'] === 'directory')
<tr class="directory-row" data-path="{{ $item['path'] }}">
    <td class="d-flex align-items-center">
        <i class="fas fa-folder me-2"></i>
        <a href="#" class="directory-link" data-path="{{ $item['path'] }}">
            {{ $item['name'] }}
        </a>
    </td>
    <td>Directory</td>
    <td>-</td>
    <td>-</td>
    <td></td>
</tr>
@elseif($item['type'] === 'book')
<tr class="book-row" data-book-id="{{ $item['id'] }}" data-path="{{ $item['path'] }}">
    <td class="d-flex align-items-center">
        <i class="fas fa-book me-2"></i>
        <span>{{ $item['name'] }}</span>
    </td>
    <td>{{ $item['mime_type'] ?? 'Book' }}</td>
    <td>{{ number_format($item['size'] / 1024, 1) }} KB</td>
    <td>{{ $item['created_at'] ? $item['created_at']->format('Y-m-d H:i') : '-' }}</td>
    <td class="text-end">
        @if(isset($item['edit_url'])) 
            <button class="btn btn-sm btn-primary me-1 open-edit-book-modal" data-url="{{ $item['edit_url'] }}">
                <i class="fas fa-edit"></i> Edit
            </button>
        @else
            <button class="btn btn-sm btn-success create-book" data-path="{{ $item['path'] }}">
                <i class="fas fa-plus"></i> Create
            </button>
        @endif
    </td>
</tr>
@else
<tr class="file-row">
    <td class="d-flex align-items-center">
        <i class="fas fa-file me-2"></i>
        <span>{{ $item['name'] }}</span>
    </td>
    <td>{{ $item['mime_type'] ?? 'File' }}</td>
    <td>{{ number_format($item['size'] / 1024, 1) }} KB</td>
    <td>-</td>
    <td></td>
</tr>
@endif
