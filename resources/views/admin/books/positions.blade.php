@use('Illuminate\Support\Str')
@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Books in Progress: {{ $user->name }}</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.events.timeline', $user) }}" class="btn btn-outline-secondary btn-sm">
                {{ __('Event Timeline') }}
            </a>
            <a href="{{ route('admin.users.show', $user) }}" class="btn btn-outline-secondary btn-sm">
                {{ __('Back to User') }}
            </a>
        </div>
    </div>

    <form method="GET" class="row g-2 mb-4">
        <div class="col-auto">
            <select name="device_id" class="form-select form-select-sm">
                <option value="">{{ __('All Devices') }}</option>
                @foreach($devices as $device)
                    <option value="{{ $device->device_id }}"
                        {{ request('device_id') === $device->device_id ? 'selected' : '' }}>
                        {{ $device->name ?? $device->device_id }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="completed" class="form-select form-select-sm">
                <option value="">{{ __('All Status') }}</option>
                <option value="0" {{ request('completed') === '0' ? 'selected' : '' }}>{{ __('In Progress') }}</option>
                <option value="1" {{ request('completed') === '1' ? 'selected' : '' }}>{{ __('Completed') }}</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">{{ __('Filter') }}</button>
            <a href="{{ route('admin.books.positions', $user) }}" class="btn btn-outline-secondary btn-sm">{{ __('Clear') }}</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm table-striped">
            <thead>
                <tr>
                    <th>{{ __('Book') }}</th>
                    <th>{{ __('Device') }}</th>
                    <th>{{ __('Position') }}</th>
                    <th>{{ __('Progress') }}</th>
                    <th>{{ __('Chapter') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Last Updated') }}</th>
                    <th>{{ __('Last Event') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($positions as $position)
                    <tr>
                        <td>
                            @if($position->book)
                                <small>{{ Str::limit($position->book->title, 40) }}</small>
                            @else
                                @php
                                    $meta = $position->lastEvent?->metadata ?? [];
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
                                    <small class="text-secondary" title="{{ __('Book not on server') }}">{{ Str::limit($display, 40) }}</small>
                                @else
                                    <small class="text-muted">Book #{{ $position->book_id }}</small>
                                @endif
                            @endif
                        </td>
                        <td>
                            @php $device = $devices->get($position->device_id); @endphp
                            <small>{{ $device->name ?? Str::limit($position->device_id, 16) }}</small>
                        </td>
                        <td>
                            @php
                                $posSeconds = intdiv($position->position_ms, 1000);
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
                            <div class="d-flex align-items-center gap-1">
                                <div class="progress flex-grow-1" style="height: 6px; min-width: 60px;">
                                    <div class="progress-bar"
                                         role="progressbar"
                                         style="width: {{ $position->progress_percentage }}%">
                                    </div>
                                </div>
                                <small class="text-muted">{{ number_format($position->progress_percentage, 1) }}%</small>
                            </div>
                        </td>
                        <td>
                            @if($position->current_chapter_name)
                                <small>{{ Str::limit($position->current_chapter_name, 25) }}</small>
                            @elseif($position->current_chapter !== null)
                                <small class="text-muted">Ch. {{ $position->current_chapter }}</small>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td>
                            @if($position->completed)
                                <span class="badge bg-success">{{ __('Completed') }}</span>
                            @else
                                <span class="badge bg-primary">{{ __('In Progress') }}</span>
                            @endif
                        </td>
                        <td>
                            @if($position->updated_at)
                                <small title="{{ $position->updated_at->format('Y-m-d H:i:s') }}">
                                    {{ $position->updated_at->diffForHumans() }}
                                </small>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.events.timeline', ['user' => $user, 'book_id' => $position->book_id, 'device_id' => $position->device_id]) }}"
                               class="btn btn-outline-secondary btn-sm py-0 px-1">
                                <small>{{ __('Events') }}</small>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">{{ __('No book positions found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="d-flex justify-content-center">
        {{ $positions->withQueryString()->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection
