{{-- Read-only: edit timeline (from audits) and every stored field the form above does not show --}}
@if(!empty($history))
    <details class="card mt-4" id="book-history">
        <summary class="card-header">{{ __('Edit history') }} ({{ count($history['entries']) }})</summary>
        <div class="card-body">
            <p class="text-muted small mb-3">{{ __('Author, narrator, series and genre changes are not recorded here.') }}</p>

            @if($history['housekeeping']['count'] > 0)
                <p class="text-muted small">
                    {{ __(':count automatic directory checks are not listed (last on :date).', [
                        'count' => $history['housekeeping']['count'],
                        'date' => $history['housekeeping']['last']?->format('M j, Y g:i A'),
                    ]) }}
                </p>
            @endif

            @if($history['truncated'])
                <p class="text-muted small">{{ __('Only the most recent changes are shown.') }}</p>
            @endif

            <ol class="list-unstyled mb-0">
                @foreach($history['entries'] as $entry)
                    <li class="border-start border-3 ps-3 pb-3 {{ $entry['event'] === 'created' ? 'border-success' : 'border-primary' }}">
                        <div>
                            <strong>{{ $entry['label'] }}</strong>
                            <span class="text-muted">· {{ $entry['at']?->format('M j, Y g:i A') }} · {{ $entry['source'] }}</span>
                            @if($entry['user'])
                                <span class="text-muted">· {{ __('by :name', ['name' => $entry['user']]) }}</span>
                            @endif
                        </div>
                        @if(!empty($entry['changes']))
                            <table class="table table-sm small mt-2 mb-0">
                                <thead>
                                    <tr>
                                        <th class="w-25">{{ __('Field') }}</th>
                                        @if($entry['event'] === 'updated')
                                            <th>{{ __('Before') }}</th>
                                        @endif
                                        <th>{{ $entry['event'] === 'updated' ? __('After') : __('Value') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($entry['changes'] as $change)
                                        <tr>
                                            <td>{{ \Illuminate\Support\Str::headline($change['field']) }}</td>
                                            @if($entry['event'] === 'updated')
                                                <td>@include('admin.books.partials.history_value', ['value' => $change['old']])</td>
                                            @endif
                                            <td>@include('admin.books.partials.history_value', ['value' => $change['new']])</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </li>
                @endforeach

                @if(!$history['importRecorded'])
                    <li class="border-start border-3 border-success ps-3 pb-3">
                        {{ __('Added to library on :date. No import record is stored for this book.', [
                            'date' => $history['addedAt']?->format('M j, Y g:i A'),
                        ]) }}
                    </li>
                @endif
            </ol>
        </div>
    </details>
@endif

@if(!empty($otherData))
    <details class="card mt-3 mb-4" id="book-other-data">
        <summary class="card-header">{{ __('Other data') }}</summary>
        <div class="card-body">
            <p class="text-muted small">{{ __('Stored fields not shown in the form above.') }}</p>
            <table class="table table-sm small mb-0">
                <tbody>
                    @foreach($otherData as $row)
                        <tr>
                            <th class="w-25">{{ \Illuminate\Support\Str::headline($row['field']) }}</th>
                            <td>@include('admin.books.partials.history_value', ['value' => $row['value']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif
