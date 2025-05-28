@component('mail::layout')
    @php
        use Illuminate\Support\Str;
    @endphp
    {{-- Header --}}
    @slot('header')
        @component('mail::header', ['url' => config('app.url')])
            {{ config('app.name') }}
        @endcomponent
    @endslot

    {{-- Body --}}
    @if($daysUntilExpiration > 0)
        # Hardcover API Token Expiring Soon
        
        Your Hardcover API token will expire in **{{ $daysUntilExpiration }} {{ Str::plural('day', $daysUntilExpiration) }}**.
        
        Please update your token in the `.env` file to avoid service interruption:
        
        ```
        HARDCOVER_API_TOKEN=your_new_token_here
        HARDCOVER_TOKEN_EXPIRES_AT=YYYY-MM-DD
        ```
        
        Then run:
        ```
        php artisan config:clear
        php artisan cache:clear
        ```
    @else
        # Hardcover API Token Has Expired
        
        Your Hardcover API token has expired. Please update your token in the `.env` file:
        
        ```
        HARDCOVER_API_TOKEN=your_new_token_here
        HARDCOVER_TOKEN_EXPIRES_AT=YYYY-MM-DD
        ```
        
        Then run:
        ```
        php artisan config:clear
        php artisan cache:clear
        ```
    @endif

    {{-- Footer --}}
    @slot('footer')
        @component('mail::footer')
            © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
        @endcomponent
    @endslot
@endcomponent
