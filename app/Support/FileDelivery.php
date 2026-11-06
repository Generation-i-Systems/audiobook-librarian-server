<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Response;

/**
 * Hands a book file to the web server instead of streaming it through PHP, when
 * `file_delivery.mode` asks for it (see config/file_delivery.php). The web server then serves
 * the bytes, Range requests included, so a PHP worker isn't held for the whole download.
 */
final class FileDelivery
{
    /**
     * @param array<string, string|int> $headers sent with the file (Content-Length is left to the web server)
     */
    public static function response(string $relativePath, string $fullPath, array $headers): ?Response
    {
        $header = match (config('file_delivery.mode')) {
            'x-accel-redirect' => ['X-Accel-Redirect' => self::accelUri($relativePath)],
            'x-sendfile' => ['X-Sendfile' => $fullPath],
            default => null,
        };
        if ($header === null) {
            return null;
        }

        unset($headers['Content-Length']);

        return new Response('', 200, $headers + $header);
    }

    private static function accelUri(string $relativePath): string
    {
        $prefix = rtrim((string) config('file_delivery.accel_prefix'), '/');
        $segments = array_map('rawurlencode', explode('/', ltrim($relativePath, '/')));

        return $prefix . '/' . implode('/', $segments);
    }
}
