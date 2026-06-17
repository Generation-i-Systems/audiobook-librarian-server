<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Api\Traits\BookTransformTrait;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookDownloadController extends Controller
{
    use BookTransformTrait;

    private const DOWNLOAD_CHUNK_SIZE = 8 * 1024 * 1024;

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    private const TRUSTED_CDN_HOSTS = [
        'archive.org',
        'www.archive.org',
        'ia800.archive.org',
        'librivox.org',
        'www.librivox.org',
    ];

    /**
     * Hash a file in fixed-size chunks for client-side range repair.
     *
     * @return array<int, array{offset: int, size: int, sha256: string}>
     */
    private function hashFileChunks(string $fullPath): array
    {
        $handle = fopen($fullPath, 'rb');
        if ($handle === false) {
            return [];
        }

        $chunks = [];
        $offset = 0;

        try {
            while (!feof($handle)) {
                $data = fread($handle, self::DOWNLOAD_CHUNK_SIZE);
                if ($data === false || $data === '') {
                    break;
                }

                $size = strlen($data);
                $chunks[] = [
                    'offset' => $offset,
                    'size' => $size,
                    'sha256' => hash('sha256', $data),
                ];
                $offset += $size;
            }
        } finally {
            fclose($handle);
        }

        return $chunks;
    }

    /**
     * Build a manifest for a LibriVox book using CDN chapter URLs.
     * download_url points to our auth-gated API which redirects to the CDN.
     * cdn_url is the raw CDN URL for clients that want direct access.
     */
    private function librivoxManifest(int|string $id, array $book)
    {
        $chapters = \App\Models\LibriVox\Chapter::where('book_id', $id)
            ->whereNotNull('listen_url')
            ->orderBy('chapter_number')
            ->get();

        if ($chapters->isEmpty()) {
            return response()->json([
                'error' => 'No chapters found',
                'message' => 'This LibriVox book has no chapter data. Try reimporting it.',
            ], 404);
        }

        $files = $chapters->map(fn ($ch) => [
            'filename'       => $ch->file_name ?: "chapter_{$ch->chapter_number}.mp3",
            'type'           => 'audio',
            'size'           => $ch->size_bytes ?? 0,
            'chapter_number' => $ch->chapter_number,
            'title'          => $ch->title,
            'reader'         => $ch->reader,
            'duration'       => $ch->duration,
            'download_url'   => $this->buildDownloadFileUrl($id, $ch->file_name ?: "chapter_{$ch->chapter_number}.mp3"),
            'cdn_url'        => $ch->listen_url,
        ])->values()->all();

        $librivoxInfo = $book['librivoxInfo'] ?? $book['librivox_info'] ?? [];
        $coverUrl = $librivoxInfo['cover_url'] ?? null;

        if ($coverUrl) {
            array_unshift($files, [
                'filename'     => 'cover.jpg',
                'type'         => 'cover',
                'size'         => 0,
                'download_url' => url('/api/v1/download/remote?url=' . rawurlencode($coverUrl)),
                'cdn_url'      => $coverUrl,
            ]);
        }

        $totalSize = (int) array_sum(array_column($files, 'size'));

        $manifest = [
            'book_id'    => (int) $id,
            'title'      => $book['title'] ?? '',
            'source'     => 'librivox',
            'total_files' => count($files),
            'total_size' => $totalSize,
            'files'      => $files,
            'librivox'   => [
                'url_librivox' => $librivoxInfo['url_librivox'] ?? null,
                'url_zip_file' => $librivoxInfo['url_zip_file'] ?? null,
                'url_iarchive' => $librivoxInfo['url_iarchive'] ?? null,
            ],
            'download_instructions' => [
                'method'   => 'Use download_url for auth-gated access (redirects to CDN). Use cdn_url for direct CDN access.',
                'resume'   => 'CDN supports HTTP Range headers for resumable downloads.',
                'auth'     => 'download_url requires Authorization header. cdn_url is public.',
            ],
            'generated_at' => now()->toISOString(),
        ];

        return response()->json($manifest);
    }

    /**
     * Redirect to a trusted remote CDN URL (LibriVox / archive.org).
     * Enforces authentication and domain allowlist before redirecting.
     */
    public function remoteDownload(Request $request)
    {
        $url = $request->query('url');

        if (!$url) {
            return response()->json(['error' => 'Missing url parameter'], 400);
        }

        $parsed = parse_url($url);
        $host   = strtolower($parsed['host'] ?? '');

        // Strip leading www. for wildcard matching of *.archive.org subdomains
        $isTrusted = in_array($host, self::TRUSTED_CDN_HOSTS, true)
            || str_ends_with($host, '.archive.org');

        if (!$isTrusted) {
            Log::warning('LibriVox remote download blocked: untrusted host', [
                'url'     => $url,
                'host'    => $host,
                'user_id' => Auth::id(),
            ]);
            return response()->json(['error' => 'URL host is not on the trusted CDN allowlist'], 403);
        }

        Log::info('LibriVox remote download redirect', [
            'url'     => $url,
            'user_id' => Auth::id(),
            'ip'      => $request->ip(),
        ]);

        return redirect($url, 302);
    }

    private function buildDownloadFileUrl(int|string $bookId, string $relativeFile): string
    {
        $encodedFile = implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), explode('/', $relativeFile)));

        return url("/api/v1/books/{$bookId}/download/{$encodedFile}");
    }

    public function download($id)
    {
        // Log download request with token preview for comparison
        $authHeader = request()->header('Authorization');
        $tokenPreview = 'none';
        if ($authHeader && preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
            $token = $matches[1];
            $tokenPreview = substr($token, 0, 8) . '...' . substr($token, -4);
        }

        Log::info('Book download requested', [
            'book_id' => $id,
            'ip' => request()->ip(),
            'user_agent' => request()->header('User-Agent'),
            'token_preview' => $tokenPreview,
            'user_id' => Auth::id(),
            'user_email' => Auth::user()->email ?? null
        ]);

        $book = $this->documentStoreService->getBook($id);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        // Hide needs_review books unless explicitly requested
        $includeNeedsReview = request()->boolean('includeNeedsReview', request()->boolean('include_needs_review', false));
        $isNeedsReview = !empty($book['needs_review']) || !empty($book['needsReview']);
        if ($isNeedsReview && !$includeNeedsReview) {
            return response()->json([
                'error' => 'File not found',
                'message' => 'Files not available for a book pending review',
            ], 404);
        }

        if (($book['source'] ?? null) === 'librivox') {
            return $this->librivoxManifest($id, $book);
        }

        $directoryPath = $book['directoryPath'] ?? null;
        if (!$directoryPath || (!Storage::disk('books')->exists($directoryPath) && empty(Storage::disk('books')->allFiles($directoryPath)))) {
            return response()->json([
                'error' => 'Book directory not found',
                'message' => 'The book files could not be located',
            ], 404);
        }

        $files = Storage::disk('books')->allFiles($directoryPath);
        if (empty($files)) {
            return response()->json([
                'error' => 'No files found',
                'message' => 'No audio files found for this book',
            ], 404);
        }

        // Filter and categorize files
        $audioFiles = [];
        $coverFiles = [];
        $otherFiles = [];
        $includeChecksums = request()->boolean('include_checksums', request()->boolean('checksums', false));
        $includeChunks = request()->boolean('include_chunks', request()->boolean('chunks', false));

        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $relativeFile = str_replace($directoryPath . '/', '', $file);

            $fullPath = Storage::disk('books')->path($file);
            $entry = [
                'filename' => $relativeFile,
                'path' => $file,
                'size' => Storage::disk('books')->size($file),
                'download_url' => $this->buildDownloadFileUrl($id, $relativeFile),
            ];
            if ($includeChecksums || $includeChunks) {
                $entry['checksum'] = is_file($fullPath) ? 'sha256:' . hash_file('sha256', $fullPath) : null;
            }
            if ($includeChunks) {
                $entry['chunk_size'] = self::DOWNLOAD_CHUNK_SIZE;
                $entry['chunks'] = is_file($fullPath) ? $this->hashFileChunks($fullPath) : [];
            }

            if (in_array($extension, ['mp3', 'm4a', 'wav', 'aac', 'ogg', 'flac', 'm4b'])) {
                if (in_array($extension, ['m4b', 'm4a'])) {
                    $analysis = $this->analyzeMp4File($fullPath);
                    $entry['is_fast_start_friendly'] = $analysis['is_fast_start'];
                    if (!$analysis['is_fast_start']) {
                        $entry['moov_offset'] = $analysis['moov_offset'];
                        $entry['moov_size'] = $analysis['moov_size'];
                    }
                } else {
                    $entry['is_fast_start_friendly'] = true;
                }
                $audioFiles[] = array_merge($entry, ['type' => 'audio']);
            } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $coverFiles[] = array_merge($entry, ['type' => 'cover']);
            } else {
                $otherFiles[] = array_merge($entry, ['type' => 'other']);
            }
        }

        // Sort audio files alphanumerically
        usort($audioFiles, function ($a, $b) {
            return strnatcmp($a['filename'], $b['filename']);
        });

        // Sort cover files: db cover first, then alphanumerically
        $dbCoverFile = $book['coverFile'] ?? null;
        usort($coverFiles, function ($a, $b) use ($dbCoverFile) {
            $aIsDb = $dbCoverFile && $a['filename'] === basename($dbCoverFile);
            $bIsDb = $dbCoverFile && $b['filename'] === basename($dbCoverFile);
            if ($aIsDb !== $bIsDb) {
                return $aIsDb ? -1 : 1;
            }
            return strnatcmp($a['filename'], $b['filename']);
        });

        // Calculate total size
        $totalSize = array_sum(array_column($audioFiles, 'size'))
            + array_sum(array_column($coverFiles, 'size'))
            + array_sum(array_column($otherFiles, 'size'));

        // Build ordered file list (covers first, then audio files alphabetically, then other files)
        $orderedFiles = array_merge($coverFiles, $audioFiles, $otherFiles);

        $manifest = [
            'book_id' => (int) $id,
            'title' => $book['title'] ?? '',
            'total_files' => count($orderedFiles),
            'total_size' => $totalSize,
            'files' => $orderedFiles,
            'download_instructions' => [
                'order' => 'Files should be downloaded in the provided order',
                'recommended_start' => 'Start with cover image, then first audio file',
                'resume' => 'Use Range headers to resume interrupted downloads',
            ],
            'generated_at' => now()->toISOString(),
        ];

        // If client wants JSON, return the manifest instead of the ZIP file
        if (request()->wantsJson()) {
            return response()->json($manifest);
        }

        // Ensure temp directory exists
        if (!file_exists(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        // Create ZIP file
        $zipFileName = 'book_' . $id . '_' . Str::slug($book['title'] ?? 'unknown') . '.zip';
        $zipPath = storage_path('app/temp/' . $zipFileName);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            // Add metadata.json
            $zip->addFromString('metadata.json', json_encode($orderedFiles, JSON_PRETTY_PRINT));

            // Add all files in manifest order
            foreach ($orderedFiles as $file) {
                $zip->addFile(Storage::disk('books')->path($file['path']), $file['path']);
            }

            $zip->close();
        } else {
            return response()->json([
               'error' => 'Zip creation failed',
               'message' => 'Could not create zip file',
            ], 500);
        }

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    /**
     * Download individual file from a book
     */
    public function downloadFile($id, $fileName)
    {
        // Log file download request with token preview for comparison
        $authHeader = request()->header('Authorization');
        $tokenPreview = 'none';
        if ($authHeader && preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
            $token = $matches[1];
            $tokenPreview = substr($token, 0, 8) . '...' . substr($token, -4);
        }

        Log::info('Book file download requested', [
            'book_id' => $id,
            'file_name' => $fileName,
            'ip' => request()->ip(),
            'user_agent' => request()->header('User-Agent'),
            'token_preview' => $tokenPreview,
            'user_id' => Auth::id(),
            'user_email' => Auth::user()->email ?? null
        ]);

        $book = $this->documentStoreService->getBook($id);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        // LibriVox: redirect to chapter CDN URL by chapter number or filename
        if (($book['source'] ?? null) === 'librivox') {
            $decoded = urldecode($fileName);
            $chapter = \App\Models\LibriVox\Chapter::where('book_id', $id)
                ->where(function ($q) use ($decoded): void {
                    $q->where('file_name', $decoded)->orWhere('chapter_number', (int) $decoded);
                })
                ->whereNotNull('listen_url')
                ->first();

            if (!$chapter) {
                return response()->json(['error' => 'Chapter not found'], 404);
            }

            return redirect((string) $chapter->listen_url, 302);
        }

        $directoryPath = $book['directoryPath'] ?? null;
        if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
            return response()->json([
                'error' => 'Book directory not found',
                'message' => 'The book files could not be located',
            ], 404);
        }

        // Resolve the file path relative to the book directory
        $fileName = urldecode($fileName);
        $filePath = $directoryPath . '/' . $fileName;

        if (!Storage::disk('books')->exists($filePath)) {
            return response()->json([
                'error' => 'File not found',
                'message' => 'The requested file could not be found',
            ], 404);
        }

        // Determine content type based on file extension
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $contentType = $this->getContentTypeByExtension($extension);

        // Get file size for Range header support
        $fileSize = Storage::disk('books')->size($filePath);
        $fullPath = Storage::disk('books')->path($filePath);

        // Log download start with file details
        Log::info('File download starting', [
            'book_id' => $id,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'file_size_mb' => round($fileSize / 1024 / 1024, 2),
            'full_path' => $fullPath,
            'user_id' => Auth::id(),
            'ip' => request()->ip(),
        ]);

        // Handle Range requests for resumable downloads
        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => $fileSize,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Range',
            'Access-Control-Expose-Headers' => 'Content-Range, Accept-Ranges, Content-Length',
        ];

        $request = request();
        $rangeHeader = $request->header('Range');

        if ($rangeHeader) {
            return $this->handleRangeRequest($fullPath, $fileSize, $rangeHeader, $contentType);
        }

        // Regular download
        return response()->stream(function () use ($fullPath, $fileSize, $book, $fileName, $id) {
            $startTime = microtime(true);
            $bytesSent = 0;
            $chunkCount = 0;
            $user = Auth::user();

            Log::info('Starting file download stream', [
                'book_id' => $book['id'] ?? $id,
                'book_title' => $book['title'] ?? 'Unknown',
                'file_name' => $fileName,
                'file_path' => $fullPath,
                'file_size_bytes' => $fileSize,
                'file_size_mb' => round($fileSize / (1024 * 1024), 2),
                'user_id' => $user->id,
                'user_email' => $user->email,
                'client_ip' => request()->ip(),
                'user_agent' => request()->header('User-Agent'),
                'start_time' => date('Y-m-d H:i:s'),
                'start_timestamp' => $startTime,
            ]);

            $handle = fopen($fullPath, 'rb');
            if ($handle === false) {
                Log::error('Failed to open file for streaming', [
                    'book_id' => $book['id'] ?? $id,
                    'file_name' => $fileName,
                    'file_path' => $fullPath,
                    'user_id' => $user->id,
                    'error' => 'fopen_failed',
                ]);
                return;
            }

            $lastProgressLog = 0;
            $progressLogInterval = 10 * 1024 * 1024; // Log every 10MB

            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 8192);
                    if ($chunk !== false && strlen($chunk) > 0) {
                        echo $chunk;
                        $bytesSent += strlen($chunk);
                        $chunkCount++;

                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();

                        // Log progress every 10MB or at significant milestones
                        if (
                            $bytesSent - $lastProgressLog >= $progressLogInterval ||
                            ($fileSize > 0 && $bytesSent >= $fileSize)
                        ) {
                            $currentTime = microtime(true);
                            $elapsedSeconds = $currentTime - $startTime;
                            $progressPercent = $fileSize > 0 ? round(($bytesSent / $fileSize) * 100, 2) : 0;
                            $avgSpeedMBps = $elapsedSeconds > 0 ? round(($bytesSent / (1024 * 1024)) / $elapsedSeconds, 2) : 0;

                            Log::info('File download progress', [
                                'book_id' => $book['id'] ?? $id,
                                'file_name' => $fileName,
                                'user_id' => $user->id,
                                'bytes_sent' => $bytesSent,
                                'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                                'total_size_bytes' => $fileSize,
                                'total_size_mb' => round($fileSize / (1024 * 1024), 2),
                                'progress_percent' => $progressPercent,
                                'elapsed_seconds' => round($elapsedSeconds, 2),
                                'avg_speed_mbps' => $avgSpeedMBps,
                                'chunks_sent' => $chunkCount,
                                'is_complete' => $bytesSent >= $fileSize
                            ]);

                            $lastProgressLog = $bytesSent;
                        }
                    }

                    if (connection_aborted()) {
                        $endTime = microtime(true);
                        $elapsedSeconds = $endTime - $startTime;

                        Log::warning('File download connection aborted by client', [
                            'book_id' => $book['id'] ?? $id,
                            'file_name' => $fileName,
                            'user_id' => $user->id,
                            'bytes_sent' => $bytesSent,
                            'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                            'total_size_bytes' => $fileSize,
                            'progress_percent' => $fileSize > 0 ? round(($bytesSent / $fileSize) * 100, 2) : 0,
                            'elapsed_seconds' => round($elapsedSeconds, 2),
                            'chunks_sent' => $chunkCount,
                            'reason' => 'connection_aborted',
                        ]);
                        break;
                    }
                }

                $endTime = microtime(true);
                $elapsedSeconds = $endTime - $startTime;
                $isComplete = $bytesSent >= $fileSize;

                if ($isComplete) {
                    Log::info('File download completed successfully', [
                        'book_id' => $book['id'] ?? $id,
                        'file_name' => $fileName,
                        'user_id' => $user->id,
                        'bytes_sent' => $bytesSent,
                        'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                        'total_size_bytes' => $fileSize,
                        'elapsed_seconds' => round($elapsedSeconds, 2),
                        'avg_speed_mbps' => $elapsedSeconds > 0 ? round(($bytesSent / (1024 * 1024)) / $elapsedSeconds, 2) : 0,
                        'chunks_sent' => $chunkCount,
                        'status' => 'completed',
                    ]);
                } else {
                    Log::warning('File download ended incomplete', [
                        'book_id' => $book['id'] ?? $id,
                        'file_name' => $fileName,
                        'user_id' => $user->id,
                        'bytes_sent' => $bytesSent,
                        'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                        'total_size_bytes' => $fileSize,
                        'progress_percent' => $fileSize > 0 ? round(($bytesSent / $fileSize) * 100, 2) : 0, // @phpstan-ignore greater.alwaysTrue
                        'elapsed_seconds' => round($elapsedSeconds, 2),
                        'chunks_sent' => $chunkCount,
                        'status' => 'incomplete',
                        'reason' => 'stream_ended_early',
                    ]);
                }
            } catch (\Exception $e) {
                $endTime = microtime(true);
                $elapsedSeconds = $endTime - $startTime;

                Log::error('File download failed with exception', [
                    'book_id' => $book['id'] ?? $id,
                    'file_name' => $fileName,
                    'user_id' => $user->id,
                    'bytes_sent' => $bytesSent,
                    'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                    'elapsed_seconds' => round($elapsedSeconds, 2),
                    'chunks_sent' => $chunkCount,
                    'exception_message' => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'status' => 'error',
                ]);
                throw $e;
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Get a temporary download URL for a book
     */
    public function downloadUrl($id)
    {
        $book = $this->documentStoreService->getBook($id);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        $directoryPath = $book['directoryPath'] ?? null;
        if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
            return response()->json([
                'error' => 'Book directory not found',
                'message' => 'The book files could not be located',
            ], 404);
        }

        $files = Storage::disk('books')->files($directoryPath);
        if (empty($files)) {
            return response()->json([
                'error' => 'No files found',
                'message' => 'No audio files found for this book',
            ], 404);
        }

        // Generate signed URLs for individual files with 1 hour expiration
        $expiresAt = now()->addHour();
        $signature = hash('sha256', $id . $expiresAt->timestamp . config('app.key'));

        $fileUrls = [];
        $totalSize = 0;

        foreach ($files as $file) {
            $fileName = basename($file);
            $fileSize = Storage::disk('books')->size($file);
            $totalSize += $fileSize;

            $fileUrls[] = [
                'filename' => $fileName,
                'size' => $fileSize,
                'download_url' => $this->buildDownloadFileUrl($id, $fileName) . "?expires={$expiresAt->timestamp}&signature={$signature}",
            ];
        }

        // Sort files to prioritize cover first, then audio files alphabetically
        usort($fileUrls, function ($a, $b) {
            $extensionA = strtolower(pathinfo($a['filename'], PATHINFO_EXTENSION));
            $extensionB = strtolower(pathinfo($b['filename'], PATHINFO_EXTENSION));

            // Cover images first
            $isCoverA = in_array($extensionA, ['jpg', 'jpeg', 'png', 'gif', 'webp']) &&
                (strpos(strtolower($a['filename']), 'cover') !== false || strpos(strtolower($a['filename']), 'folder') !== false);
            $isCoverB = in_array($extensionB, ['jpg', 'jpeg', 'png', 'gif', 'webp']) &&
                (strpos(strtolower($b['filename']), 'cover') !== false || strpos(strtolower($b['filename']), 'folder') !== false);

            if ($isCoverA && !$isCoverB) {
                return -1;
            }
            if (!$isCoverA && $isCoverB) {
                return 1;
            }

            // Then alphabetical
            return strnatcmp($a['filename'], $b['filename']);
        });

        return response()->json([
            'book_id' => (int) $id,
            'title' => $book['title'] ?? '',
            'expires_at' => $expiresAt->toISOString(),
            'total_size' => $totalSize,
            'total_files' => count($fileUrls),
            'files' => $fileUrls,
            'download_instructions' => [
                'order' => 'Download files in the provided order for best experience',
                'resume' => 'All URLs support HTTP Range headers for resumable downloads',
                'authentication' => 'URLs are signed and will expire at the specified time',
            ],
        ]);
    }

    /**
     * Get the download manifest for a book
     *
     * Provides metadata about the contents of the book download zip without downloading the file
     *
     * @param  string  $id  Book ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function downloadManifest($id)
    {
        // Get the book details
        $book = $this->documentStoreService->getBook($id);

        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        // Check if the book has audio files
        $audioPath = 'books/' . $id . '/audio';
        $hasAudio = Storage::disk('books')->exists($audioPath);

        // Get audio file metadata
        $chapters = [];
        $totalDuration = 0;

        if ($hasAudio) {
            $files = Storage::disk('books')->files($audioPath);
            sort($files); // Ensure files are in order

            foreach ($files as $index => $file) {
                // Only include audio files
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                if (!in_array(strtolower($extension), ['mp3', 'm4a', 'wav', 'aac', 'ogg', 'flac'])) {
                    continue;
                }

                $chapterNum = $index + 1;
                $fileName = basename($file);

                // Extract duration if available in metadata (this is a placeholder - implement actual duration extraction)
                $duration = $book['chapters'][$index]['duration'] ?? 0;
                $totalDuration += $duration;

                $chapters[] = [
                    'chapter_number' => $chapterNum,
                    'file_name' => $fileName,
                    'format' => $extension,
                    'duration' => $duration,
                    'size_bytes' => Storage::disk('books')->size($file),
                ];
            }
        }

        $resolvedCoverPath = null;
        if (!empty($book['coverImage'])) {
            $resolvedCoverPath = $this->resolveCoverImagePath($book['coverImage'], $book['directoryPath'] ?? null);
        }

        // Build the manifest
        $manifest = [
            'book_id' => $id,
            'title' => $book['title'] ?? '',
            'author' => $book['author_name'] ?? '',
            'series' => $book['series_name'] ?? '',
            'series_number' => $book['series_number'] ?? null,
            'total_duration_seconds' => $totalDuration,
            'cover_included' => !empty($resolvedCoverPath) && Storage::disk('books')->exists($resolvedCoverPath),
            'format' => 'zip',
            'chapters' => $chapters,
            'has_audio' => $hasAudio,
            'total_chapters' => count($chapters),
            'total_files' => count($chapters) + (!empty($book['coverImage']) ? 1 : 0),
        ];

        return response()->json($manifest);
    }

    public function queueDownload(Request $request)
    {
        // Validate incoming request for book IDs (manual to ensure JSON 422)
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'book_ids' => ['required', 'array', 'min:1'],
            'book_ids.*' => ['integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }

        $bookIds = $validator->validated()['book_ids'] ?? [];

        // Ensure all books exist
        $missing = [];
        foreach ($bookIds as $bid) {
            if (!\App\Models\Book::query()->whereKey($bid)->exists()) {
                $missing[] = $bid;
            }
        }
        if (count($missing) > 0) {
            return response()->json([
                'message' => 'One or more book IDs are invalid',
                'errors' => [
                    'book_ids' => ['Some provided book IDs do not exist.'],
                ],
            ], 422);
        }

        // Simulate creating a queued zip and return an identifier
        $zipId = 'zip_' . Str::random(16);
        \Illuminate\Support\Facades\Cache::put('download_zip:' . $zipId, [
            'book_ids' => $bookIds,
            'status' => 'ready',
            'created_at' => now()->toISOString(),
        ], now()->addMinutes(30));

        return response()->json([
            'zipId' => $zipId,
            'message' => 'Download queued successfully',
            'book_count' => count($bookIds),
        ]);
    }

    public function downloadQueuedZip($zipId)
    {
        $state = \Illuminate\Support\Facades\Cache::get('download_zip:' . $zipId);
        if (!$state) {
            return response()->json([
                'error' => 'Zip file not found',
                'message' => 'The requested download is not available',
            ], 404);
        }

        // For testing purposes, return JSON indicating readiness
        return response()->json([
            'zipId' => $zipId,
            'status' => $state['status'] ?? 'processing',
        ], 200);
    }

    public function markZipDownloaded($zipId)
    {
        $key = 'download_zip:' . $zipId;
        $state = \Illuminate\Support\Facades\Cache::get($key);
        if (!$state) {
            return response()->json([
                'error' => 'Zip file not found',
                'message' => 'The requested download is not available',
            ], 404);
        }

        if (($state['status'] ?? null) === 'downloaded') {
            return response()->json([
                'message' => 'Already marked as downloaded',
            ], 409);
        }

        $state['status'] = 'downloaded';
        \Illuminate\Support\Facades\Cache::put($key, $state, now()->addMinutes(5));

        return response()->json(['message' => 'Zip file marked as downloaded']);
    }

    /**
     * Parse MP4/M4B file to check if it is fast-start optimized and locate the moov atom.
     * Returns an array: ['is_fast_start' => bool, 'moov_offset' => int|null, 'moov_size' => int|null]
     */
    private function analyzeMp4File(string $filePath): array
    {
        if (!is_file($filePath)) {
            return ['is_fast_start' => false, 'moov_offset' => null, 'moov_size' => null];
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return ['is_fast_start' => false, 'moov_offset' => null, 'moov_size' => null];
        }

        $fileSize = filesize($filePath);
        $offset = 0;
        $moovOffset = null;
        $moovSize = null;
        $mdatOffset = null;

        while ($offset < $fileSize) {
            // Seek to the box start
            if (fseek($handle, $offset) !== 0) {
                break;
            }

            // Read size and type
            $header = fread($handle, 8);
            if (strlen($header) < 8) {
                break;
            }

            $size = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);

            $boxSize = $size;
            $headerSize = 8;

            if ($size === 1) {
                // 64-bit size
                $extHeader = fread($handle, 8);
                if (strlen($extHeader) < 8) {
                    break;
                }
                $boxSize = unpack('J', $extHeader)[1];
                $headerSize = 16;
            }

            if ($type === 'moov') {
                $moovOffset = $offset;
                $moovSize = $boxSize;
            } elseif ($type === 'mdat') {
                $mdatOffset = $offset;
            }

            if ($boxSize <= 0) {
                // If box size is 0, it means till end of file (only allowed for last box)
                break;
            }

            $offset += $boxSize;
        }

        fclose($handle);

        $isFastStart = false;
        if ($moovOffset !== null && $mdatOffset !== null) {
            // Fast-start means moov appears before mdat
            $isFastStart = $moovOffset < $mdatOffset;
        }

        return [
            'is_fast_start' => $isFastStart,
            'moov_offset' => $moovOffset,
            'moov_size' => $moovSize,
        ];
    }
}
