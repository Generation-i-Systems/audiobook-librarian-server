<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImageProxyController extends Controller
{
    /**
     * Serve an image from BOOK_STORAGE_PATH for preview or display.
     * Usage: /image-proxy?dir=...&file=...
     */
    public function show(Request $request)
    {
        $directory = $request->query('dir', '.');
        $filename = $request->query('file');
        if (!$directory || !$filename) {
            abort(404);
        }
        $storagePath = env('BOOK_STORAGE_PATH');
        $fullPath = rtrim($storagePath, '/') . '/' . ltrim($directory, '/') . '/' . ltrim($filename, '/');
        if (!file_exists($fullPath)) {
            abort(404);
        }
        // Optional: Add access control here, e.g.:
        // if (!Auth::check()) abort(403);
        $mime = mime_content_type($fullPath);
        return response()->file($fullPath, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store',
        ]);
    }
}
