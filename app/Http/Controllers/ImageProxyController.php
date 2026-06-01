<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImageProxyController extends Controller
{
    /**
     * Serve an image from BOOK_STORAGE_PATH for preview or display.
     * Usage: /image-proxy?file=...
     *        /cover/{path}
     */
    public function show(Request $request)
    {
        $storagePath = rtrim((string) config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');

        $dir = $request->query('dir') ?? '.';
        $file = $request->query('file');
        if (!$file) {
            abort(404);
        }
        if ($dir !== '.' && is_dir(rtrim($storagePath, '/') . '/' . ltrim($dir, '/'))) {
            $file = rtrim($dir, '/') . '/' . $file;
        }

        if ($dir !== '.' && !is_dir(rtrim($storagePath, '/') . '/' . ltrim($dir, '/'))) {
            abort(404);
        }
        $fullPath = rtrim($storagePath, '/') . '/' . ltrim($file, '/');
        if (!file_exists($fullPath)) {
            abort(404);
        }

        $realBase = realpath($storagePath);
        $realPath = realpath($fullPath);
        if ($realPath === false || $realBase === false || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $mime = mime_content_type($realPath);

        return response()->file($realPath, [
            'Content-Type' => $mime,
        ]);
    }

    /**
     * Pretty route: /cover/{path}
     * Supports slashes in {path}
     * Handles both encoded (%2F) and non-encoded (/) paths for backward compatibility
     */
    public function cover($path)
    {
        Log::info('Cover path (raw): ' . $path);

        // Decode the path to handle both old (encoded) and new (non-encoded) styles
        $decodedPath = rawurldecode($path);

        $storagePath = rtrim((string) config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $fullPath = rtrim($storagePath, '/') . '/' . ltrim($decodedPath, '/');

        // If not found with decoded path, try the original path (for non-encoded URLs)
        if (!file_exists($fullPath)) {
            $fullPath = rtrim($storagePath, '/') . '/' . ltrim($path, '/');
        }

        if (!file_exists($fullPath)) {
            Log::error('Cover not found at path: ' . $fullPath . ' (decoded: ' . $decodedPath . ')');
            abort(404);
        }

        $realBase = realpath($storagePath);
        $realPath = realpath($fullPath);
        if ($realPath === false || $realBase === false || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $mime = mime_content_type($realPath);

        return response()->file($realPath, [
            'Content-Type' => $mime,
        ]);
    }

    /**
     * Proxy Google Books cover images to avoid mixed content errors.
     * Accepts a base64-encoded URL as the path.
     * Example: /google-books-cover/{base64url}
     */
    public function googleBooksCover($encodedUrl)
    {
        $url = base64_decode($encodedUrl);
        // Only allow books.google.com URLs
        if (!preg_match('#^https?://books\\.google\\.com/#', $url)) {
            abort(403, 'Invalid Google Books cover URL.');
        }
        $client = new Client(['verify' => false, 'timeout' => 10]);
        try {
            $response = $client->get($url, ['stream' => true]);

            return response($response->getBody(), 200)
                ->header('Content-Type', $response->getHeaderLine('Content-Type'))
                ->header('Cache-Control', 'public, max-age=86400');
        } catch (\Exception $e) {
            abort(404, 'Could not fetch Google Books cover image.');
        }
    }
}
