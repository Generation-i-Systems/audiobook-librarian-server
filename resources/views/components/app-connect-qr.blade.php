@props([
    'title' => 'Connect the mobile app',
    'description' => 'Scan this QR code from the Audiobook Librarian app to connect it to this server.',
    'connectUrl',
    'apiUrl',
])

<div class="card app-connect-card">
    <div class="card-body">
        <h5 class="card-title">{{ $title }}</h5>
        <p class="card-text text-muted">{{ $description }}</p>
        <div
            class="app-connect-qr d-flex align-items-center justify-content-center border rounded bg-white mx-auto"
            data-connect-url="{{ $connectUrl }}"
            aria-label="Server connection QR code"
        >
            <span class="text-muted small">Generating QR code...</span>
        </div>
        <div class="mt-3">
            <div class="small text-muted">Server API URL</div>
            <code class="d-block small app-connect-value">{{ $apiUrl }}</code>
        </div>
        <div class="mt-2">
            <div class="small text-muted">QR link</div>
            <code class="d-block small app-connect-value">{{ $connectUrl }}</code>
        </div>
    </div>
</div>

@once
    @push('styles')
        <style>
            .app-connect-qr {
                min-height: 220px;
                width: 220px;
            }

            .app-connect-value {
                white-space: normal;
                word-break: break-all;
            }
        </style>
    @endpush

@endonce
