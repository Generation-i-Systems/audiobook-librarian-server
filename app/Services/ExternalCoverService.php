<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExternalCoverService
{
    /**
     * Download an external cover image and save it to the book's directory
     *
     * @param  string  $url  The URL of the external cover image
     * @param  string  $directoryPath  The book's directory path
     * @param  string  $source  The source of the cover image (audible, googlebooks, etc.)
     * @param  string|null  $sourceId  The ID of the book in the external source (ASIN, Google Books ID, etc.)
     * @return array Returns an array with the saved file path and error message if any
     */
    public function downloadCoverImage(
        string $url,
        string $directoryPath,
        string $source,
        ?string $sourceId = null
    ): array {
        $result = [
            'success' => false,
            'path' => null,
            'error' => null,
        ];

        if (empty($url) || empty($directoryPath)) {
            $result['error'] = 'URL or directory path is empty';
            Log::error('ExternalCoverService: Cannot download image - URL or directory path is empty', [
                'url' => $url,
                'directoryPath' => $directoryPath,
            ]);

            return $result;
        }

        try {
            Log::info('Downloading external cover image', [
                'url' => $url,
                'directoryPath' => $directoryPath,
                'source' => $source,
                'sourceId' => $sourceId,
            ]);

            // Validate URL format before attempting to download
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $result['error'] = 'Invalid URL format';
                Log::error('ExternalCoverService: Invalid URL format', ['url' => $url]);

                return $result;
            }

            $response = Http::timeout(15)->get($url);

            if ($response->successful()) {
                $imageContents = $response->body();
                $extension = 'jpg'; // Default extension

                // Detect extension from content type if available
                $contentType = $response->header('Content-Type');
                if (strpos($contentType, 'image/jpeg') !== false) {
                    $extension = 'jpg';
                } elseif (strpos($contentType, 'image/png') !== false) {
                    $extension = 'png';
                } elseif (strpos($contentType, 'image/webp') !== false) {
                    $extension = 'webp';
                }

                // Generate filename based on source and ID
                $fileName = $this->generateFileName($source, $sourceId, $extension);
                $storagePath = $directoryPath . '/' . $fileName;

                // Store file in books disk
                // Verify image content is not empty
                if (empty($imageContents)) {
                    $result['error'] = 'Downloaded image content is empty';
                    Log::error('ExternalCoverService: Downloaded image content is empty', ['url' => $url]);

                    return $result;
                }

                // Verify the content is actually an image
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($imageContents);
                if (strpos($mimeType, 'image/') !== 0) {
                    $result['error'] = 'Downloaded content is not an image: ' . $mimeType;
                    Log::error('ExternalCoverService: Downloaded content is not an image', [
                        'url' => $url,
                        'mimeType' => $mimeType,
                    ]);

                    return $result;
                }

                // Make sure the directory exists
                $directoryExists = Storage::disk('books')->exists($directoryPath);
                if (!$directoryExists) {
                    try {
                        Storage::disk('books')->makeDirectory($directoryPath);
                    } catch (\Exception $e) {
                        $result['error'] = 'Failed to create directory: ' . $e->getMessage();
                        Log::error('ExternalCoverService: Failed to create directory', [
                            'directoryPath' => $directoryPath,
                            'error' => $e->getMessage(),
                        ]);

                        return $result;
                    }
                }

                try {
                    Storage::disk('books')->put($storagePath, $imageContents);
                } catch (\Exception $e) {
                    $result['error'] = 'Failed to save image: ' . $e->getMessage();
                    Log::error('ExternalCoverService: Failed to save image', [
                        'storagePath' => $storagePath,
                        'error' => $e->getMessage(),
                    ]);

                    return $result;
                }

                $result['success'] = true;
                $result['path'] = $storagePath;

                Log::info('External cover image downloaded successfully', [
                    'source' => $source,
                    'path' => $storagePath,
                ]);
            } else {
                $result['error'] = 'Failed to download image: HTTP ' . $response->status();
                Log::error('ExternalCoverService: Failed to download external cover image', [
                    'url' => $url,
                    'status' => $response->status(),
                    'response' => $response->body() ? Str::limit($response->body(), 200) : 'empty',
                ]);
            }
        } catch (\Exception $e) {
            $result['error'] = 'Exception: ' . $e->getMessage();
            Log::error('ExternalCoverService: Exception while downloading external cover image', [
                'url' => $url,
                'message' => $e->getMessage(),
                'trace' => Str::limit($e->getTraceAsString(), 1000),
                'directoryPath' => $directoryPath,
                'source' => $source,
                'sourceId' => $sourceId,
            ]);
        }

        return $result;
    }

    /**
     * Generate a filename for the external cover image
     *
     * @param  string  $source  The source of the cover image
     * @param  string|null  $sourceId  The ID of the book in the external source
     * @param  string  $extension  The file extension
     * @return string The generated filename
     */
    private function generateFileName(string $source, ?string $sourceId, string $extension): string
    {
        // Use normalizeSourceName to ensure consistent source names
        $normalizedSource = $this->normalizeSourceName($source);

        // If we have a source ID, use it in the filename
        if (!empty($sourceId)) {
            return "cover_{$normalizedSource}_{$sourceId}.{$extension}";
        }

        // Otherwise use a timestamp to ensure uniqueness
        $timestamp = time();

        return "cover_{$normalizedSource}_{$timestamp}.{$extension}";
    }

    /**
     * Get the appropriate source name for the given source identifier
     *
     * @param  string  $source  The source identifier (e.g., 'google', 'audible')
     * @return string The normalized source name
     */
    public function normalizeSourceName(string $source): string
    {
        $source = strtolower($source);

        switch ($source) {
            case 'google':
            case 'googlebooks':
                return 'googlebooks';
            case 'audible':
                return 'audible';
            case 'audiobookbay':
            case 'abb':
                return 'audiobookbay';
            case 'hardcover':
                return 'hardcover';
            default:
                return $source;
        }
    }
}
