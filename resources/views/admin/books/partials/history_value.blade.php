@if($value === null || $value === '')
    <span class="text-muted">—</span>
@elseif(mb_strlen($value) > 120 || str_contains($value, "\n"))
    <details>
        <summary>{{ \Illuminate\Support\Str::limit(str_replace("\n", ' ', $value), 80) }}</summary>
        <pre class="small mb-0 mt-1 text-wrap">{{ $value }}</pre>
    </details>
@else
    {{ $value }}
@endif
