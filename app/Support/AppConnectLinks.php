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
        return self::buildAndroidIntentLink('connect/server?apiUrl=' . rawurlencode($apiUrl), $packageName);
    }

    public static function magicPlayerDeepLink(string $token, string $apiUrl): string
    {
        return 'ablibrarian://auth/magic?' . self::magicQuery($token, $apiUrl);
    }

    public static function magicLibraryDeepLink(string $token, string $apiUrl): string
    {
        return 'ablibrarian-library://auth/magic?' . self::magicQuery($token, $apiUrl);
    }

    public static function androidMagicIntentLink(string $token, string $apiUrl, string $packageName): string
    {
        return self::buildAndroidIntentLink('auth/magic?' . self::magicQuery($token, $apiUrl), $packageName);
    }

    private static function magicQuery(string $token, string $apiUrl): string
    {
        return 'token=' . rawurlencode($token) . '&apiUrl=' . rawurlencode($apiUrl);
    }

    private static function buildAndroidIntentLink(string $pathAndQuery, string $packageName): string
    {
        $fallbackUrl = rawurlencode((string) config('app.mobile_android_store_url', 'https://play.google.com/store/apps/details?id=com.ablibrarian.library'));

        return 'intent://' . $pathAndQuery
            . '#Intent;scheme=ablibrarian;package=' . $packageName
            . ';S.browser_fallback_url=' . $fallbackUrl
            . ';end';
    }
}
