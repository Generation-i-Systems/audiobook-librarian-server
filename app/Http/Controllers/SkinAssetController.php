<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use ZipArchive;

class SkinAssetController extends Controller
{
    /**
     * Serve a skin asset from configured skin paths.
     * Supports both extracted directories and .zip files.
     *
     * Route: /skin-asset/{skinId}/{path}
     * Example: /skin-asset/89/assets/buttons/play.png
     */
    public function show($skinId, $path)
    {
        // Get configured skin paths from environment
        $skinPathsConfig = config('app.skin_paths', '');
        $skinPaths = array_filter(array_map('trim', explode(',', $skinPathsConfig)));

        if (empty($skinPaths)) {
            Log::warning('No skin paths configured in SKIN_PATHS environment variable');
            abort(404, 'No skin paths configured');
        }

        // Try to find the skin in each configured path
        foreach ($skinPaths as $basePath) {
            $basePath = rtrim($basePath, '/');

            // Try as extracted directory first
            $extractedPath = $basePath . '/' . $skinId;
            if (is_dir($extractedPath)) {
                $fullPath = $extractedPath . '/' . ltrim($path, '/');
                if (file_exists($fullPath) && is_file($fullPath)) {
                    return $this->serveFile($fullPath);
                }
            }

            // Try as .zip file
            $zipPath = $basePath . '/' . $skinId . '.zip';
            if (file_exists($zipPath)) {
                $fileContent = $this->extractFromZip($zipPath, $path);
                if ($fileContent !== null) {
                    return $this->serveContent($fileContent, $path);
                }
            }
        }

        Log::error("Skin asset not found: skinId={$skinId}, path={$path}");
        abort(404, 'Skin asset not found');
    }

    /**
     * Serve a file from the filesystem
     */
    private function serveFile($fullPath)
    {
        $mime = mime_content_type($fullPath);

        return response()->file($fullPath, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400', // Cache for 24 hours
        ]);
    }

    /**
     * Serve content directly from memory
     */
    private function serveContent($content, $path)
    {
        // Determine MIME type from file extension
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeTypes = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'json' => 'application/json',
            'css' => 'text/css',
            'js' => 'application/javascript',
        ];

        $mime = $mimeTypes[$extension] ?? 'application/octet-stream';

        return response($content, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400', // Cache for 24 hours
        ]);
    }

    /**
     * Extract a file from a ZIP archive
     *
     * @return string|null File content or null if not found
     */
    private function extractFromZip($zipPath, $filePath)
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            Log::error("Failed to open ZIP file: {$zipPath}");
            return null;
        }

        // Normalize the path (remove leading slash)
        $filePath = ltrim($filePath, '/');

        // Try to get the file content
        $content = $zip->getFromName($filePath);

        $zip->close();

        return $content !== false ? $content : null;
    }
}
