<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class AppConnectLinks
{
    public static function apiBaseUrl(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/') . '/api/v1';
    }

    public static function redirectorUrl(Request $request, ?string $apiUrl = null): string
    {
        $serverApiUrl = $apiUrl ?: self::apiBaseUrl($request);

        return url('/app/connect/server') . '?apiUrl=' . rawurlencode($serverApiUrl);
    }

    public static function playerDeepLink(string $apiUrl): string
    {
        return 'ablibrarian://connect/server?apiUrl=' . rawurlencode($apiUrl);
    }

    public static function libraryDeepLink(string $apiUrl): string
    {
        return 'ablibrarian-library://connect/server?apiUrl=' . rawurlencode($apiUrl);
    }

    public static function androidIntentLink(string $apiUrl, string $packageName): string
    {
        $fallbackUrl = rawurlencode((string) config('app.mobile_android_store_url', 'https://play.google.com/store/apps/details?id=com.ablibrarian.library'));

        return 'intent://connect/server?apiUrl=' . rawurlencode($apiUrl)
            . '#Intent;scheme=ablibrarian;package=' . $packageName
            . ';S.browser_fallback_url=' . $fallbackUrl
            . ';end';
    }
}
