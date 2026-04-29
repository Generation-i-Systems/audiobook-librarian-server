@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
<div class="container" style="max-width: 480px; margin: 64px auto; text-align: center; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <h1 style="font-size: 22px; margin-bottom: 12px;">Sign in</h1>

    @if(!$valid)
        <p style="color:#c0392b;">This sign-in link is no longer valid. It may have expired or already been used.</p>
        <p style="margin-top: 16px;"><a href="/login">Go to login</a></p>
    @else
        <p style="color:#444;">Opening the app&hellip;</p>

        <noscript>
            <p>Click below to continue.</p>
        </noscript>

        <p style="margin: 24px 0;">
            <a href="{{ $deepLink }}"
               id="open-in-app"
               data-deeplink="{{ $deepLink }}"
               style="display:inline-block; background:#3955ff; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:8px; font-weight:600; font-size:14px;">Open in app</a>
        </p>

        <form method="POST" action="{{ $continueUrl }}" style="margin-top: 16px;">
            @csrf
            <button type="submit"
                    style="background:transparent; color:#3955ff; border:1px solid #3955ff; padding:10px 18px; border-radius:8px; font-weight:600; cursor:pointer;">
                Continue in browser
            </button>
        </form>

        <p style="margin-top: 32px; font-size: 12px; color:#888;">If nothing happens, the app may not be installed on this device.</p>

        <script>
            // Try the deep link automatically. If the app handles it, the
            // browser will navigate away from this URL; otherwise the user
            // can click the visible button or "Continue in browser".
            (function () {
                var link = document.getElementById('open-in-app');
                var url = link ? link.getAttribute('data-deeplink') : null;
                if (!url) { return; }
                try {
                    window.location.href = url;
                } catch (e) {
                    // Ignore and let the user click manually.
                }
            })();
        </script>
    @endif
</div>
@endsection
