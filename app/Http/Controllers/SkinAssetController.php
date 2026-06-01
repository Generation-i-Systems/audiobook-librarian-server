<?php

namespace App\Http\Controllers;

use App\Models\Skin;
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
            // Log::warning('No skin paths configured in SKIN_PATHS environment variable');
            abort(404, 'No skin paths configured');
        }

        // Determine candidate names for the skin folder
        $candidates = [$skinId];

        // If numeric ID, try to resolve name from DB
        if (is_numeric($skinId)) {
            try {
                $skin = Skin::with('forkedFrom')->find($skinId);
                if ($skin) {
                    // Add skin name (e.g. "Arctic Breeze")
                    $candidates[] = $skin->name;

                    // Add manifest skinName if available
                    if (isset($skin->manifest['skinName'])) {
                        $candidates[] = $skin->manifest['skinName'];
                    }

                    // If forked, try original skin name
                    if ($skin->forkedFrom) {
                        $candidates[] = $skin->forkedFrom->name;
                        if (isset($skin->forkedFrom->manifest['skinName'])) {
                            $candidates[] = $skin->forkedFrom->manifest['skinName'];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore DB errors, fall back to just ID
                Log::warning('SkinAssetController: Failed to resolve skin name from DB: ' . $e->getMessage());
            }
        }

        $candidates = array_unique($candidates);

        // Try to find the skin in each configured path using each candidate name
        foreach ($candidates as $candidate) {
            // Skip empty candidates
            if (empty($candidate)) {
                continue;
            }

            foreach ($skinPaths as $basePath) {
                $basePath = rtrim($basePath, '/');

                // Try as extracted directory first
                $extractedPath = $basePath . '/' . $candidate;
                if (is_dir($extractedPath)) {
                    $fullPath = $extractedPath . '/' . ltrim($path, '/');
                    if (file_exists($fullPath) && is_file($fullPath)) {
                        $realExtracted = realpath($extractedPath);
                        $realFull = realpath($fullPath);
                        if ($realFull !== false && $realExtracted !== false && str_starts_with($realFull, $realExtracted . DIRECTORY_SEPARATOR)) {
                            return $this->serveFile($realFull);
                        }
                    }
                }

                // Try as .zip file
                $zipPath = $basePath . '/' . $candidate . '.zip';
                if (file_exists($zipPath)) {
                    $fileContent = $this->extractFromZip($zipPath, $path);
                    if ($fileContent !== null) {
                        return $this->serveContent($fileContent, $path);
                    }
                }
            }
        }

        abort(404, 'Skin asset not found');
    }

    private function serveFile($fullPath)
    {
        $mime = mime_content_type($fullPath);

        return response()->file($fullPath, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function serveContent($content, $path)
    {
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
            'ttf' => 'font/ttf',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
        ];

        $mime = $mimeTypes[$extension] ?? 'application/octet-stream';

        return response($content, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function extractFromZip($zipPath, $filePath)
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $filePath = ltrim($filePath, '/');

        $content = $zip->getFromName($filePath);

        if ($content === false) {
            $content = $zip->getFromName('skin/' . $filePath);
        }

        $zip->close();

        return $content !== false ? $content : null;
    }
}
