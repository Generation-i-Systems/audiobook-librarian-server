<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AppConnectLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AppConnectController extends Controller
{
    public function server(Request $request): Response
    {
        $apiUrl = $this->validatedApiUrl((string) $request->query('apiUrl', ''))
            ?: AppConnectLinks::apiBaseUrl($request);

        return response()->view('app-connect.server', [
            'apiUrl' => $apiUrl,
            'playerDeepLink' => AppConnectLinks::playerDeepLink($apiUrl),
            'libraryDeepLink' => AppConnectLinks::libraryDeepLink($apiUrl),
            'androidLibraryIntent' => AppConnectLinks::androidIntentLink($apiUrl, 'com.ablibrarian.library'),
            'androidPlayerIntent' => AppConnectLinks::androidIntentLink($apiUrl, 'com.ablibrarian.player'),
            'androidStoreUrl' => (string) config('app.mobile_android_store_url', 'https://play.google.com/store/apps/details?id=com.ablibrarian.library'),
            'iosStoreUrl' => (string) config('app.mobile_ios_store_url', '#'),
        ])->cookie(
            'ablibrarian_connect_api_url',
            $apiUrl,
            60,
            null,
            null,
            $request->isSecure(),
            false,
            false,
            'Lax',
        );
    }

    private function validatedApiUrl(string $apiUrl): ?string
    {
        $trimmed = rtrim(trim($apiUrl), '/');
        if ($trimmed === '' || ! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $trimmed;
    }
}
