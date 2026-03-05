@use('Illuminate\Support\Str')
@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Event Timeline: {{ $user->name }}</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.books.positions', $user) }}" class="btn btn-outline-secondary btn-sm">
                Books in Progress
            </a>
            <a href="{{ route('admin.users.show', $user) }}" class="btn btn-outline-secondary btn-sm">
                Back to User
            </a>
        </div>
    </div>

    <form method="GET" class="row g-2 mb-4">
        <div class="col-auto">
            <select name="device_id" class="form-select form-select-sm">
                <option value="">All Devices</option>
                @foreach($devices as $device)
                    <option value="{{ $device->device_id }}"
                        {{ request('device_id') === $device->device_id ? 'selected' : '' }}>
                        {{ $device->name ?? $device->device_id }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="event_type" class="form-select form-select-sm">
                <option value="">All Event Types</option>
                @foreach($eventTypes as $type)
                    <option value="{{ $type }}"
                        {{ request('event_type') === $type ? 'selected' : '' }}>
                        {{ $type }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="book_id" class="form-select form-select-sm">
                <option value="">All Books</option>
                @foreach($books as $book)
                    <option value="{{ $book->id }}"
                        {{ request('book_id') == $book->id ? 'selected' : '' }}>
                        {{ Str::limit($book->title, 40) }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="{{ route('admin.events.timeline', $user) }}" class="btn btn-outline-secondary btn-sm">
                Clear
            </a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm table-striped">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Event Type</th>
                    <th>Book</th>
                    <th>Position</th>
                    <th>Device</th>
                    <th>Metadata</th>
                    <th>Sync</th>
                </tr>
            </thead>
            <tbody>
                @forelse($events as $event)
                    <tr>
                        <td class="text-nowrap">
                            <small>
                                @php
                                    $dt = \Carbon\Carbon::createFromTimestampMs($event->timestamp_ms);
                                @endphp
                                {{ $dt->format('Y-m-d H:i:s') }}
                                <br>
                                <span class="text-muted">{{ $dt->diffForHumans() }}</span>
                            </small>
                        </td>
                        <td>
                            @php
                                $badgeClass = match(true) {
                                    str_starts_with($event->event_type, 'PLAY_') => 'bg-info',
                                    str_starts_with($event->event_type, 'SESSION_') => 'bg-primary',
                                    str_starts_with($event->event_type, 'BOOK_') => 'bg-success',
                                    str_starts_with($event->event_type, 'BOOKMARK_') => 'bg-warning',
                                    default => 'bg-secondary',
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ $event->event_type }}</span>
                        </td>
                        <td>
                            @if($event->book)
                                <small>{{ Str::limit($event->book->title, 30) }}</small>
                            @else
                                @php
                                    $meta = $event->metadata ?? [];
                                    $fallbackTitle = $meta['fallbackTitle'] ?? null;
                                    $fallbackAuthor = $meta['fallbackAuthor'] ?? null;
                                    $fallbackSeries = $meta['fallbackSeries'] ?? null;
                                    $fallbackSeriesNum = $meta['fallbackSeriesNumber'] ?? null;

                                    $display = $fallbackTitle;
                                    if ($display && $fallbackAuthor) {
                                        $display .= " by " . $fallbackAuthor;
                                    }
                                    if ($display && $fallbackSeries) {
                                        $display .= " ($fallbackSeries" . ($fallbackSeriesNum ? " #$fallbackSeriesNum" : "") . ")";
                                    }
                                @endphp
                                @if($display)
                                    <small class="text-secondary" title="Book not on server">{{ Str::limit($display, 40) }}</small>
                                @else
                                    <small class="text-muted">Book #{{ $event->book_id }}</small>
                                @endif
                            @endif
                        </td>
                        <td>
                            @php
                                $posSeconds = intdiv($event->position_ms, 1000);
                                $h = intdiv($posSeconds, 3600);
                                $m = intdiv($posSeconds % 3600, 60);
                                $s = $posSeconds % 60;
                                $posFormatted = $h > 0
                                    ? sprintf('%d:%02d:%02d', $h, $m, $s)
                                    : sprintf('%d:%02d', $m, $s);
                            @endphp
                            <code>{{ $posFormatted }}</code>
                        </td>
                        <td>
                            @php
                                $device = $devices->get($event->device_id);
                            @endphp
                            <small>{{ $device->name ?? Str::limit($event->device_id, 12) }}</small>
                        </td>
                        <td>
                            @if($event->metadata)
                                <small>
                                    @php
                                        $meta = $event->metadata;
                                        $parts = [];
                                        if (isset($meta['playbackSpeed'])) {
                                            $parts[] = $meta['playbackSpeed'] . 'x';
                                        }
                                        if (isset($meta['sessionDurationMs'])) {
                                            $dur = intdiv($meta['sessionDurationMs'], 1000);
                                            $parts[] = gmdate('H:i:s', $dur);
                                        }
                                        if (isset($meta['chapterName'])) {
                                            $parts[] = Str::limit($meta['chapterName'], 20);
                                        }
                                        if (!empty($meta['manuallyMarked'])) {
                                            $parts[] = 'manual';
                                        }
                                    @endphp
                                    {{ implode(' | ', $parts) }}
                                </small>
                            @endif
                        </td>
                        <td>
                            @if($event->migrated_from)
                                <span class="badge bg-secondary">migrated</span>
                            @else
                                <span class="badge bg-light text-dark">synced</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No events found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="d-flex justify-content-center">
        {{ $events->withQueryString()->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection
