<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * API Health Check Controller
 *
 * Provides endpoints for uptime monitoring and API spec validation.
 * These endpoints can be accessed without authentication.
 */
class ApiHealthController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * Basic health check - verifies API is responding
     */
    public function ping(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'service' => 'audiobook-librarian-api',
        ]);
    }

    /**
     * Detailed health check - validates API responses conform to OpenAPI spec
     *
     * This endpoint validates:
     * - Database connectivity
     * - Book data format compliance
     * - Series, author, narrator, genre array formats
     * - Storage volume availability and free space
     */
    public function health(): JsonResponse
    {
        $checks = [];
        $allPassed = true;

        // Check 1: Database connectivity
        $checks['database'] = $this->checkDatabase();
        if (!$checks['database']['passed']) {
            $allPassed = false;
        }

        // Check 2: Book data format validation
        $checks['book_format'] = $this->checkBookFormat();
        if (!$checks['book_format']['passed']) {
            $allPassed = false;
        }

        // Check 3: OpenAPI spec compliance for series data
        $checks['series_format'] = $this->checkSeriesFormat();
        if (!$checks['series_format']['passed']) {
            $allPassed = false;
        }

        // Check 4: Storage volume accessibility and free space
        $checks['storage'] = $this->checkStorageVolumes();
        if (!$checks['storage']['passed']) {
            $allPassed = false;
        }

        // @phpstan-ignore-next-line
        return response()->json([
            'status' => $allPassed ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
            'api_version' => 'v1',
        ], $allPassed ? 200 : 503);
    }

    /**
     * Full spec validation - validates multiple API endpoints
     */
    public function validateSpec(): JsonResponse
    {
        $validations = [];
        $allPassed = true;
        $sampleBookId = null;

        // Get a sample book for validation
        try {
            $booksData = $this->documentStoreService->listBooks(1, 1, []);
            if (!empty($booksData['data'])) {
                $sampleBookId = $booksData['data'][0]['id'] ?? null;
            }
        } catch (\Exception $e) {
            Log::warning('Health check: Failed to get sample book', ['error' => $e->getMessage()]);
        }

        // Validate books list endpoint
        $validations['books_list'] = $this->validateBooksListEndpoint();
        if (!$validations['books_list']['passed']) {
            $allPassed = false;
        }

        // Validate single book endpoint
        if ($sampleBookId) {
            $validations['single_book'] = $this->validateSingleBookEndpoint((int) $sampleBookId);
            if (!$validations['single_book']['passed']) {
                $allPassed = false;
            }
        }

        // Validate data formats
        $validations['array_formats'] = $this->validateArrayFormats();
        if (!$validations['array_formats']['passed']) {
            $allPassed = false;
        }

        return response()->json([
            'status' => $allPassed ? 'spec_compliant' : 'spec_violations_found',
            'timestamp' => now()->toIso8601String(),
            'validations' => $validations,
            'api_version' => 'v1',
            'openapi_version' => '3.0.3',
        ], $allPassed ? 200 : 422);
    }

    /**
     * Capability discovery endpoint — returns what features this server supports.
     * Clients that receive 404 from this endpoint should assume all capabilities.
     */
    public function capabilities(): JsonResponse
    {
        return response()->json([
            'serverType' => 'ablibrarian-full',
            'syncApiVersion' => '1',
            'capabilities' => [
                'BROWSE',
                'DOWNLOAD',
                'HISTORY_SYNC',
                'STATS',
                'BOOKMARKS_SYNC',
                'RECOMMENDATIONS',
                'METADATA_MATCH',
                'SKINS_GALLERY',
                'PLAYLISTS',
                'BADGES',
            ],
            'requiresAuth' => true,
            'authMethods' => [
                'username_password',
                'google_oauth',
                'facebook_oauth',
                'apple_oauth',
                'discord_oauth',
                'email_otp',
            ],
        ]);
    }

    /**
     * Check all configured storage volumes for accessibility and free space
     */
    protected function checkStorageVolumes(): array
    {
        $paths = $this->getStoragePaths();
        $volumes = [];
        $allHealthy = true;

        foreach ($paths as $name => $path) {
            $exists = is_dir($path);
            $readable = $exists && is_readable($path);

            $freeBytes = $readable ? disk_free_space($path) : false;
            $totalBytes = $readable ? disk_total_space($path) : false;

            $freeBytesInt = $freeBytes !== false ? (int) $freeBytes : null;
            $totalBytesInt = $totalBytes !== false ? (int) $totalBytes : null;
            $usedPercent = ($freeBytesInt !== null && $totalBytesInt !== null && $totalBytesInt > 0) ? round((($totalBytesInt - $freeBytesInt) / $totalBytesInt) * 100, 1) : null;

            $passed = $exists && $readable && $freeBytes !== false;
            if (!$passed) {
                $allHealthy = false;
            }

            $volumes[$name] = [
                'passed' => $passed,
                'path' => $path,
                'exists' => $exists,
                'readable' => $readable,
                'free_bytes' => $freeBytesInt,
                'total_bytes' => $totalBytesInt,
                'used_percent' => $usedPercent,
                'message' => $passed ? 'Volume accessible' : ($exists ? 'Volume not readable' : 'Directory does not exist'),
            ];
        }

        return [
            'passed' => $allHealthy,
            'message' => $allHealthy ? 'All storage volumes accessible' : 'One or more storage volumes have issues',
            'volumes' => $volumes,
        ];
    }

    /**
     * Return the named storage paths to monitor
     */
    protected function getStoragePaths(): array
    {
        $paths = [
            'books' => (string) config('filesystems.disks.books.root', ''),
            'trash' => (string) config('filesystems.disks.trash.root', ''),
        ];

        return array_filter($paths);
    }

    /**
     * Check database connectivity
     */
    protected function checkDatabase(): array
    {
        try {
            $booksData = $this->documentStoreService->listBooks(1, 1, []);
            return [
                'passed' => true,
                'message' => 'Database connection successful',
                // @phpstan-ignore-next-line
                'book_count' => $booksData['total'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::error('Health check: Database connection failed', ['error' => $e->getMessage()]);
            return [
                'passed' => false,
                'message' => 'Database connection failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check book data format compliance
     */
    protected function checkBookFormat(): array
    {
        try {
            $booksData = $this->documentStoreService->listBooks(1, 5, []);
            $books = $booksData['data'] ?? [];

            if (empty($books)) {
                return [
                    'passed' => true,
                    'message' => 'No books to validate',
                    'sample_count' => 0,
                ];
            }

            $issues = [];
            foreach ($books as $book) {
                // Transform book to API format
                $transformed = $this->transformBookForValidation($book);
                $bookIssues = $this->validateBookStructure($transformed);
                if (!empty($bookIssues)) {
                    $issues[$book['id'] ?? 'unknown'] = $bookIssues;
                }
            }

            return [
                'passed' => empty($issues),
                'message' => empty($issues) ? 'All books comply with format' : 'Format issues found',
                'sample_count' => count($books),
                'issues' => $issues,
            ];
        } catch (\Exception $e) {
            Log::error('Health check: Book format check failed', ['error' => $e->getMessage()]);
            return [
                'passed' => false,
                'message' => 'Format check failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check series format specifically (main issue being fixed)
     */
    protected function checkSeriesFormat(): array
    {
        try {
            $booksData = $this->documentStoreService->listBooks(1, 10, []);
            $books = $booksData['data'] ?? [];

            $seriesIssues = [];
            $booksWithSeries = 0;

            foreach ($books as $book) {
                $transformed = $this->transformBookForValidation($book);
                $series = $transformed['series'] ?? [];

                if (!empty($series)) {
                    $booksWithSeries++;

                    foreach ($series as $index => $seriesEntry) {
                        // Check for null name (the bug we're fixing)
                        if (!isset($seriesEntry['name'])) {
                            $seriesIssues[] = [
                                'book_id' => $book['id'] ?? 'unknown',
                                'issue' => 'Series entry has null name',
                                'series_index' => $index,
                            ];
                        }

                        // Check for empty name
                        if (isset($seriesEntry['name']) && $seriesEntry['name'] === '') {
                            $seriesIssues[] = [
                                'book_id' => $book['id'] ?? 'unknown',
                                'issue' => 'Series entry has empty name',
                                'series_index' => $index,
                            ];
                        }
                    }
                }
            }

            return [
                'passed' => empty($seriesIssues),
                'message' => empty($seriesIssues) ? 'Series format compliant' : 'Series format issues found',
                'books_checked' => count($books),
                'books_with_series' => $booksWithSeries,
                'issues' => $seriesIssues,
            ];
        } catch (\Exception $e) {
            Log::error('Health check: Series format check failed', ['error' => $e->getMessage()]);
            return [
                'passed' => false,
                'message' => 'Series format check failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate books list endpoint structure
     */
    protected function validateBooksListEndpoint(): array
    {
        try {
            $booksData = $this->documentStoreService->listBooks(1, 5, []);

            $hasData = isset($booksData['data']) && is_array($booksData['data']);
            $hasTotal = isset($booksData['total']);

            return [
                'passed' => $hasData && $hasTotal,
                'message' => ($hasData && $hasTotal) ? 'Books list endpoint valid' : 'Books list endpoint structure invalid',
                'has_data_array' => $hasData,
                'has_total' => $hasTotal,
            ];
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'message' => 'Books list validation failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate single book endpoint structure
     */
    protected function validateSingleBookEndpoint(int $bookId): array
    {
        try {
            $book = $this->documentStoreService->getBook((string) $bookId);

            if (!$book) {
                return [
                    'passed' => true, // Book not found is not a spec violation
                    'message' => 'Sample book not found, skipping validation',
                ];
            }

            $transformed = $this->transformBookForValidation($book);
            $issues = $this->validateBookStructure($transformed);

            return [
                'passed' => empty($issues),
                'message' => empty($issues) ? 'Single book endpoint valid' : 'Single book endpoint has issues',
                'book_id' => $bookId,
                'issues' => $issues,
            ];
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'message' => 'Single book validation failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate that array fields are properly formatted
     */
    protected function validateArrayFormats(): array
    {
        try {
            $booksData = $this->documentStoreService->listBooks(1, 5, []);
            $books = $booksData['data'] ?? [];
            $issues = [];

            foreach ($books as $book) {
                $transformed = $this->transformBookForValidation($book);
                $bookId = $book['id'] ?? 'unknown';

                // Validate author is array of strings
                if (!is_array($transformed['author'])) {
                    $issues[] = ['book_id' => $bookId, 'field' => 'author', 'issue' => 'not an array'];
                }

                // Validate narrator is array of strings
                if (!is_array($transformed['narrator'])) {
                    $issues[] = ['book_id' => $bookId, 'field' => 'narrator', 'issue' => 'not an array'];
                }

                // Validate genre is array of strings
                if (!is_array($transformed['genre'])) {
                    $issues[] = ['book_id' => $bookId, 'field' => 'genre', 'issue' => 'not an array'];
                }

                // Validate series is array
                if (!is_array($transformed['series'])) {
                    $issues[] = ['book_id' => $bookId, 'field' => 'series', 'issue' => 'not an array'];
                }
            }

            return [
                'passed' => empty($issues),
                'message' => empty($issues) ? 'Array formats valid' : 'Array format issues found',
                'issues' => $issues,
            ];
        } catch (\Exception $e) {
            return [
                'passed' => false,
                'message' => 'Array format validation failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Transform book data using same logic as API controller
     */
    protected function transformBookForValidation(array $book): array
    {
        return [
            'id' => $book['id'] ?? null,
            'title' => $book['title'] ?? '',
            'author' => $this->normalizeArray($book['author'] ?? $book['author_name'] ?? []),
            'narrator' => $this->normalizeArray($book['narrator'] ?? $book['narrator_name'] ?? []),
            'series' => $this->formatSeriesData($book),
            'genre' => $this->normalizeArray($book['genre'] ?? []),
            'year' => isset($book['published_year']) ? (int) $book['published_year'] : (isset($book['year']) ? (int) $book['year'] : null),
            'duration' => $book['duration'] ?? null,
            'description' => $book['description'] ?? null,
            'file_count' => isset($book['audio_file_count']) ? (int) $book['audio_file_count'] : (isset($book['file_count']) ? (int) $book['file_count'] : null),
            'total_size' => isset($book['total_size']) ? (int) $book['total_size'] : null,
            'created_at' => $book['created_at'] ?? $book['date_added'] ?? null,
            'updated_at' => $book['updated_at'] ?? null,
            'cover_url' => null,
        ];
    }

    /**
     * Normalize array data
     */
    protected function normalizeArray($data): array
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
     * Format series data (same logic as BookApiController)
     */
    protected function formatSeriesData(array $book): array
    {
        if (isset($book['series']) && is_array($book['series']) && !empty($book['series'])) {
            $result = [];
            foreach ($book['series'] as $series) {
                $name = null;
                $seriesNumber = null;

                if (is_array($series)) {
                    $name = $series['name'] ?? null;
                    $seriesNumber = $series['pivot']['series_number'] ?? $series['series_number'] ?? null;
                } elseif (is_object($series)) {
                    $name = $series->name ?? null;
                    $seriesNumber = $series->pivot->series_number ?? $series->series_number ?? null;
                }

                // Only include entries with valid series names
                if (!empty($name)) {
                    $result[] = [
                        'name' => $name,
                        'series_number' => $seriesNumber,
                    ];
                }
            }
            return $result;
        }

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
     * Validate book structure matches OpenAPI spec
     */
    protected function validateBookStructure(array $book): array
    {
        $issues = [];

        // Required fields
        $requiredFields = ['id', 'title', 'author', 'narrator', 'series', 'genre'];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $book)) {
                $issues[] = "Missing required field: {$field}";
            }
        }

        // Type validations
        if (isset($book['id']) && !is_int($book['id'])) {
            $issues[] = 'id must be integer';
        }

        if (isset($book['title']) && !is_string($book['title'])) {
            $issues[] = 'title must be string';
        }

        if (isset($book['author']) && !is_array($book['author'])) {
            $issues[] = 'author must be array';
        }

        if (isset($book['narrator']) && !is_array($book['narrator'])) {
            $issues[] = 'narrator must be array';
        }

        if (isset($book['genre']) && !is_array($book['genre'])) {
            $issues[] = 'genre must be array';
        }

        if (isset($book['series']) && !is_array($book['series'])) {
            $issues[] = 'series must be array';
        }

        // Series validation - check for null names (the bug being fixed)
        if (isset($book['series']) && is_array($book['series'])) {
            foreach ($book['series'] as $index => $seriesEntry) {
                if (!is_array($seriesEntry)) {
                    $issues[] = "series[{$index}] must be object";
                    continue;
                }

                if (!isset($seriesEntry['name'])) {
                    $issues[] = "series[{$index}].name must not be null";
                }

                if (isset($seriesEntry['name']) && !is_string($seriesEntry['name'])) {
                    $issues[] = "series[{$index}].name must be string";
                }

                // @phpstan-ignore-next-line
                if (isset($seriesEntry['name']) && $seriesEntry['name'] === '') {
                    $issues[] = "series[{$index}].name must not be empty";
                }
            }
        }

        return $issues;
    }
}
