<?php

namespace App\Http\Controllers\Api\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

trait BookTransformTrait
{
    private function getBookWithCover($book, bool $withCover = false, bool $inlineCovers = false, bool $enhanced = false): array
    {
        if (!is_array($book)) {
            Log::error('getBookWithCover received non-array book data', [
                'book_type'  => gettype($book),
                'book_value' => $book,
                'backtrace'  => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            ]);
            return ['error' => 'Invalid book data'];
        }

        $transformedBook = [
            'id'          => $book['id'] ?? null,
            'title'       => $book['title'] ?? '',
            'author'      => $this->normalizeArray($book['author'] ?? $book['author_name'] ?? []),
            'narrator'    => $this->normalizeArray($book['narrator'] ?? $book['narrator_name'] ?? []),
            'series'      => $this->formatSeriesData($book),
            'genre'       => $this->normalizeGenre($book['genre'] ?? []),
            'year'        => isset($book['published_year']) ? (int) $book['published_year'] : (isset($book['year']) ? (int) $book['year'] : null),
            'duration'    => $book['duration'] ?? null,
            'description' => $book['description'] ?? null,
            'file_count'  => isset($book['audio_file_count']) ? (int) $book['audio_file_count'] : (isset($book['file_count']) ? (int) $book['file_count'] : null),
            'total_size'  => isset($book['total_size']) ? (int) $book['total_size'] : null,
            'created_at'  => $book['created_at'] ?? $book['date_added'] ?? null,
            'updated_at'  => $book['updated_at'] ?? null,
        ];

        if (isset($book['progress'])) {
            $transformedBook['progress'] = $book['progress'];
        }
        if (isset($book['status'])) {
            $transformedBook['status'] = $book['status'];
        }
        if (isset($book['recommendation'])) {
            $transformedBook['recommendation'] = $book['recommendation'];
        }

        // Enhanced mode: add structured relationship objects with IDs so clients
        // can reliably navigate to the correct author/series/genre/narrator screen.
        if ($enhanced) {
            $transformedBook['authors']   = $this->extractRelationshipObjects($book, 'authors_data', 'authors');
            $transformedBook['genres']    = $this->extractRelationshipObjects($book, 'genres_data', 'genres');
            $transformedBook['narrators'] = $this->extractRelationshipObjects($book, 'narrators_data', 'narrators');
            $transformedBook['series']    = $this->extractSeriesObjects($book);
        }

        if (!empty($book['source'])) {
            $transformedBook['source'] = $book['source'];
        }

        if (!empty($book['coverImage'])) {
            $coverPath = $this->resolveCoverImagePath($book['coverImage'], $book['directoryPath'] ?? null);

            if ($inlineCovers && $coverPath && Storage::disk('books')->exists($coverPath)) {
                $fullPath = Storage::disk('books')->path($coverPath);
                $transformedBook['cover'] = [
                    'type' => 'base64',
                    'path' => $fullPath,
                    'data' => base64_encode(Storage::disk('books')->get($coverPath)),
                ];
            }
            $request = request();
            $transformedBook['cover_url'] = $this->normalizeCoverUrl($request->getSchemeAndHttpHost() . '/api/v1/books/' . ($book['id'] ?? '') . '/cover');
        } else {
            $transformedBook['cover_url'] = null;
        }

        return $transformedBook;
    }

    /**
     * Extract structured relationship objects (id + name) from pre-eagerly-loaded data.
     * Falls back to authors/genres/narrators key if the _data variant is absent.
     *
     * @return array<int, array{id: mixed, name: string}>
     */
    private function extractRelationshipObjects(array $book, string $dataKey, string $fallbackKey): array
    {
        $raw = $book[$dataKey] ?? $book[$fallbackKey] ?? [];

        if (!is_array($raw) || empty($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $item) {
            if (is_array($item) && isset($item['id'])) {
                $result[] = ['id' => $item['id'], 'name' => $item['name'] ?? ''];
            } elseif (is_string($item)) {
                // Flat string, no ID available — keep as object for consistent shape
                $result[] = ['id' => null, 'name' => $item];
            }
        }

        return $result;
    }

    private function normalizeCoverUrl(string $url): string
    {
        if (strpos($url, 'http://') === 0) {
            return 'https://' . substr($url, 7);
        }

        return $url;
    }

    /**
     * Extract series as structured objects with id, name, series_number, is_collection.
     *
     * @return array<int, array{id: mixed, name: string, series_number: string|null, is_collection: bool}>
     */
    private function extractSeriesObjects(array $book): array
    {
        $raw = $book['series_data'] ?? $book['series'] ?? [];

        if (!is_array($raw) || empty($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = $item['name'] ?? $item['seriesName'] ?? null;
            if (empty($name)) {
                continue;
            }
            $seriesNumber = $item['pivot']['series_number']
                ?? $item['series_number']
                ?? $item['number']
                ?? null;

            $result[] = [
                'id'            => $item['id'] ?? null,
                'name'          => $name,
                'series_number' => $seriesNumber !== null ? (string) $seriesNumber : null,
                'is_collection' => (bool) ($item['is_collection'] ?? false),
                'pivot'         => ['series_number' => $seriesNumber !== null ? (string) $seriesNumber : null],
            ];
        }

        return $result;
    }

    /**
     * Normalize array data - ensure it's always an array of strings
     */
    private function normalizeArray($data)
    {
        if (is_string($data)) {
            return [$data];
        }

        if (is_array($data)) {
            return array_values(array_filter(array_map('trim', $data)));
        }

        return [];
    }

    /**
     * Format series data as an array with name and series number
     * Returns only entries with valid (non-null, non-empty) series names
     */
    private function formatSeriesData($book): array
    {
        // Handle case where series is already loaded as a relationship
        if (isset($book['series']) && is_array($book['series']) && !empty($book['series'])) {
            $result = [];
            foreach ($book['series'] as $series) {
                $name = null;
                $seriesNumber = null;

                if (is_array($series)) {
                    $name = $series['name'] ?? $series['seriesName'] ?? null;
                    $seriesNumber = $series['pivot']['series_number'] ?? $series['series_number'] ?? $series['number'] ?? null;
                } elseif (is_object($series)) {
                    $name = $series->name ?? $series->seriesName ?? null;
                    $seriesNumber = $series->pivot->series_number ?? $series->series_number ?? $series->number ?? null;
                }

                // Only include entries with valid series names (not null or empty)
                if (!empty($name)) {
                    $result[] = [
                        'name' => $name,
                        'series_number' => $seriesNumber !== null ? (string)$seriesNumber : null,
                    ];
                }
            }
            return $result;
        }

        // Handle case where series info is directly in the book array
        $seriesName = $book['series_name'] ?? ($book['series']['name'] ?? null);
        $seriesNumber = $book['series_number'] ?? null;

        if (empty($seriesName)) {
            return [];
        }

        return [
            [
                'name' => $seriesName,
                'series_number' => $seriesNumber,
            ],
        ];
    }

    /**
     * Normalize genre data - ensure it's always an array of strings
     */
    private function normalizeGenre($data)
    {
        if (is_string($data)) {
            return [$data];
        }

        if (is_array($data)) {
            return array_values(array_filter(array_map('trim', $data)));
        }

        return [];
    }

    /**
     * Resolve cover image path, handling both filename-only and full path formats
     * Also handles filesystem corruption where directory names have stray quotes
     *
     * @param string $coverImage The cover image value from database
     * @param string|null $directoryPath The directory path for the book
     * @return string The resolved cover image path
     */
    private function resolveCoverImagePath(string $coverImage, ?string $directoryPath): string
    {
        // Clean up any corrupted paths (remove quotes, etc.)
        $coverImage = trim($coverImage, "'\"");
        $coverImage = str_replace("'/", "/", $coverImage);
        $coverImage = ltrim($coverImage, '/');

        // If it's a full path (contains slashes), use as-is
        if (str_contains($coverImage, '/')) {
            return $coverImage;
        }

        // It's just a filename - combine with directory path
        if ($directoryPath) {
            $cleanDirectoryPath = rtrim($directoryPath, '/');
            $primaryPath = $cleanDirectoryPath . '/' . $coverImage;

            // Check if the clean path exists first
            if (Storage::disk('books')->exists($primaryPath)) {
                return $primaryPath;
            }

            // Fallback: check if filesystem has corrupted directory names with trailing quotes
            // This handles cases where DB was cleaned but filesystem still has corruption
            $corruptedPath = $cleanDirectoryPath . "'/" . $coverImage;
            if (Storage::disk('books')->exists($corruptedPath)) {
                Log::info('Found cover image at corrupted filesystem path', [
                    'clean_path' => $primaryPath,
                    'corrupted_path' => $corruptedPath,
                ]);
                return $corruptedPath;
            }

            // // Try other common corruption patterns
            $patterns = [
                $cleanDirectoryPath . '"/' . $coverImage,  // Double quote
                $cleanDirectoryPath . ' /' . $coverImage,  // Space
                $cleanDirectoryPath . '\\' . $coverImage,  // Backslash
            ];

            foreach ($patterns as $pattern) {
                if (Storage::disk('books')->exists($pattern)) {
                    Log::info('Found cover image at alternative corrupted path', [
                        'clean_path' => $primaryPath,
                        'found_path' => $pattern,
                    ]);
                    return $pattern;
                }
            }

            return $primaryPath; // Return clean path even if not found (for error logging)
        }

        // No directory path available, try as-is (might be in root)
        return $coverImage;
    }

    /**
     * Check if a URL is remote (starts with http:// or https://)
     *
     * @param string $url
     * @return bool
     */
    private function isRemoteUrl(string $url): bool
    {
        return (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0);
    }

    /**
     * Proxy a remote cover image URL with caching and error handling
     *
     * @param string $url The remote URL to proxy
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    private function proxyRemoteCoverImage(string $url): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        try {
            Log::info('Proxying remote cover image', ['url' => $url]);

            // Use Laravel's HTTP client with a reasonable timeout
            /** @var \Illuminate\Http\Client\Response $response */
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; BooksCoverProxy/1.0)',
                    'Accept' => 'image/*,*/*;q=0.8',
                ])
                ->get($url);

            if ($response->successful()) {
                $content = $response->body();
                $contentType = $response->header('Content-Type');

                // Default to image/jpeg if no content type or invalid content type
                if (!$contentType || !str_starts_with($contentType, 'image/')) {
                    $contentType = 'image/jpeg';
                }

                // Validate that we actually got image content
                if (empty($content)) {
                    Log::warning('Remote cover image returned empty content', ['url' => $url]);
                    return $this->coverNotFoundResponse();
                }

                // Optional: Validate image content using finfo
                if (function_exists('finfo_buffer')) {
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $detectedMime = $finfo->buffer($content);
                    if ($detectedMime && str_starts_with($detectedMime, 'image/')) {
                        $contentType = $detectedMime;
                    }
                }

                return response($content, 200)
                    ->header('Content-Type', $contentType)
                    ->header('Access-Control-Allow-Origin', '*')
                    ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization')
                    ->header('Cache-Control', 'public, max-age=3600')
                    ->header('X-Proxied-From', parse_url($url, PHP_URL_HOST));
            } else {
                Log::warning('Remote cover image request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'response' => \Illuminate\Support\Str::limit($response->body(), 200),
                ]);
                return $this->coverNotFoundResponse();
            }
        } catch (\Exception $e) {
            Log::error('Exception while proxying remote cover image', [
                'url' => $url,
                'message' => $e->getMessage(),
                'trace' => \Illuminate\Support\Str::limit($e->getTraceAsString(), 500),
            ]);
            return $this->coverNotFoundResponse();
        }
    }

    /**
     * Return a standardized "cover not found" response
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function coverNotFoundResponse(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error' => 'Cover not found',
            'message' => 'Cover image file could not be found',
        ], 404);
    }

    /**
     * Get MIME content type by file extension
     */
    private function getContentTypeByExtension($extension)
    {
        $mimeTypes = [
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'm4b' => 'audio/mp4',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'txt' => 'text/plain',
            'nfo' => 'text/plain',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Handle HTTP Range requests for resumable downloads
     */
    private function handleRangeRequest($filePath, $fileSize, $rangeHeader, $contentType)
    {
        if (!preg_match('/bytes=(\d+)-(\d+)?/', $rangeHeader, $matches)) {
            return response('Invalid range', 416);
        }

        $start = (int) $matches[1];
        $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1; // @phpstan-ignore notIdentical.alwaysTrue

        if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
            return response('Range not satisfiable', 416, [
                'Content-Range' => "bytes */{$fileSize}",
            ]);
        }

        $length = $end - $start + 1;

        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => $length,
            'Accept-Ranges' => 'bytes',
            'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Range',
            'Access-Control-Expose-Headers' => 'Content-Range, Accept-Ranges, Content-Length',
        ];

        return response()->stream(function () use ($filePath, $start, $length, $fileSize) {
            $startTime = microtime(true);
            $bytesSent = 0;
            $chunkCount = 0;
            $user = \Illuminate\Support\Facades\Auth::user();

            Log::info('Starting range download stream', [
                'file_path' => basename($filePath),
                'range_start' => $start,
                'range_length' => $length,
                'range_end' => $start + $length - 1,
                'total_file_size' => $fileSize,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'client_ip' => request()->ip(),
                'user_agent' => request()->header('User-Agent'),
                'start_time' => date('Y-m-d H:i:s'),
                'start_timestamp' => $startTime,
            ]);

            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                Log::error('Failed to open file for range streaming', [
                    'file_path' => basename($filePath),
                    'range_start' => $start,
                    'range_length' => $length,
                    'user_id' => $user->id,
                    'error' => 'fopen_failed',
                ]);
                return;
            }

            fseek($handle, $start);
            $remaining = $length;
            $progressLogInterval = max(1048576, $length / 10); // Log every 1MB or 10% of range
            $lastProgressLog = 0;

            try {
                while ($remaining > 0 && !feof($handle)) {
                    $chunkSize = min(8192, $remaining);
                    $chunk = fread($handle, $chunkSize);

                    if ($chunk === false || strlen($chunk) === 0) {
                        break;
                    }

                    echo $chunk;
                    $bytesSent += strlen($chunk);
                    $chunkCount++;
                    $remaining -= strlen($chunk);

                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();

                    // Log progress for range downloads
                    if ($bytesSent - $lastProgressLog >= $progressLogInterval || $remaining <= 0) {
                        $currentTime = microtime(true);
                        $elapsedSeconds = $currentTime - $startTime;
                        $progressPercent = $length > 0 ? round(($bytesSent / $length) * 100, 2) : 100;
                        $avgSpeedMBps = $elapsedSeconds > 0 ? round(($bytesSent / (1024 * 1024)) / $elapsedSeconds, 2) : 0;

                        Log::info('Range download progress', [
                            'file_path' => basename($filePath),
                            'user_id' => $user->id,
                            'range_start' => $start,
                            'bytes_sent' => $bytesSent,
                            'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                            'range_length' => $length,
                            'range_length_mb' => round($length / (1024 * 1024), 2),
                            'progress_percent' => $progressPercent,
                            'elapsed_seconds' => round($elapsedSeconds, 2),
                            'avg_speed_mbps' => $avgSpeedMBps,
                            'chunks_sent' => $chunkCount,
                            'remaining_bytes' => $remaining,
                            'is_complete' => $remaining <= 0
                        ]);

                        $lastProgressLog = $bytesSent;
                    }

                    if (connection_aborted()) {
                        $endTime = microtime(true);
                        $elapsedSeconds = $endTime - $startTime;

                        Log::warning('Range download connection aborted by client', [
                            'file_path' => basename($filePath),
                            'user_id' => $user->id,
                            'range_start' => $start,
                            'bytes_sent' => $bytesSent,
                            'range_length' => $length,
                            'progress_percent' => $length > 0 ? round(($bytesSent / $length) * 100, 2) : 0,
                            'elapsed_seconds' => round($elapsedSeconds, 2),
                            'chunks_sent' => $chunkCount,
                            'remaining_bytes' => $remaining,
                            'reason' => 'connection_aborted',
                        ]);
                        break;
                    }
                }

                $endTime = microtime(true);
                $elapsedSeconds = $endTime - $startTime;
                $isComplete = $remaining <= 0;

                if ($isComplete) {
                    Log::info('Range download completed successfully', [
                        'file_path' => basename($filePath),
                        'user_id' => $user->id,
                        'range_start' => $start,
                        'bytes_sent' => $bytesSent,
                        'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                        'range_length' => $length,
                        'elapsed_seconds' => round($elapsedSeconds, 2),
                        'avg_speed_mbps' => $elapsedSeconds > 0 ? round(($bytesSent / (1024 * 1024)) / $elapsedSeconds, 2) : 0,
                        'chunks_sent' => $chunkCount,
                        'status' => 'completed',
                    ]);
                } else {
                    Log::warning('Range download ended incomplete', [
                        'file_path' => basename($filePath),
                        'user_id' => $user->id,
                        'range_start' => $start,
                        'bytes_sent' => $bytesSent,
                        'range_length' => $length,
                        'progress_percent' => $length > 0 ? round(($bytesSent / $length) * 100, 2) : 0,
                        'elapsed_seconds' => round($elapsedSeconds, 2),
                        'chunks_sent' => $chunkCount,
                        'remaining_bytes' => $remaining,
                        'status' => 'incomplete',
                        'reason' => 'stream_ended_early',
                    ]);
                }
            } catch (\Exception $e) {
                $endTime = microtime(true);
                $elapsedSeconds = $endTime - $startTime;

                Log::error('Range download failed with exception', [
                    'file_path' => basename($filePath),
                    'user_id' => $user->id,
                    'range_start' => $start,
                    'bytes_sent' => $bytesSent,
                    'elapsed_seconds' => round($elapsedSeconds, 2),
                    'chunks_sent' => $chunkCount,
                    'remaining_bytes' => $remaining,
                    'exception_message' => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'status' => 'error',
                ]);
                throw $e;
            }

            fclose($handle);
        }, 206, $headers);
    }
}
