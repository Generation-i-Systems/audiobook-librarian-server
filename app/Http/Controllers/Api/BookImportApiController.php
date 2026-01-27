<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use App\Services\BookImportService;
use App\Services\ImportQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * API Controller for book import operations.
 *
 * Provides endpoints for the desktop app (NativePHP) to:
 * - Check for duplicate books before queuing
 * - Get valid genres
 * - Search/create authors and series
 * - Check status of queued imports
 */
class BookImportApiController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;
    protected BookImportService $bookImportService;
    protected ImportQueueService $queueService;

    public function __construct(
        DocumentStoreServiceInterface $documentStoreService,
        BookImportService $bookImportService,
        ImportQueueService $queueService
    ) {
        $this->documentStoreService = $documentStoreService;
        $this->bookImportService = $bookImportService;
        $this->queueService = $queueService;
    }

    /**
     * Check if a book with the given title/author already exists.
     *
     * Used by the desktop app to detect duplicates before queuing an import.
     *
     * @return JsonResponse
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:500',
            'author' => 'required|string|max:255',
            'series' => 'nullable|string|max:255',
            'series_number' => 'nullable|string|max:50',
            'isbn' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $metadata = [
            'title' => $request->input('title'),
            'author' => $request->input('author'),
            'series' => $request->input('series'),
            'series_number' => $request->input('series_number'),
            'isbn' => $request->input('isbn'),
        ];

        $existingBook = $this->bookImportService->findExistingBook('', $metadata);

        if ($existingBook) {
            return response()->json([
                'is_duplicate' => true,
                'existing_book' => [
                    'id' => $existingBook->id,
                    'title' => $existingBook->title,
                    'authors' => $existingBook->authors->pluck('name')->toArray(),
                    'series' => $existingBook->series->first()?->name,
            // @phpstan-ignore-next-line
            'series_number' => $existingBook->series->first()?->pivot?->series_number,
            'directory_path' => $existingBook->directory_path,
        ],
            ]);
        }

        return response()->json([
            'is_duplicate' => false,
            'existing_book' => null,
        ]);
    }

    /**
     * Get all valid genres for book categorization.
     *
     * @return JsonResponse
     */
    public function getGenres(Request $request): JsonResponse
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Genre> $genreCollection */
        $genreCollection = Genre::orderBy('name')->get();
        $genres = $genreCollection->map(fn (Genre $genre) => [
            'id' => $genre->id,
            'name' => $genre->name,
        ]);

        return response()->json([
            'data' => $genres,
        ]);
    }

    /**
     * Search for existing authors by name.
     *
     * @return JsonResponse
     */
    public function searchAuthors(Request $request): JsonResponse
    {
        $query = $request->input('q', $request->input('query', '')) ?? '';
        $limit = min((int) $request->input('limit', 20), 100);

        if (strlen($query) < 1) {
            return response()->json(['data' => []]);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Author> $authorCollection */
        $authorCollection = Author::where('name', 'like', '%' . $query . '%')
            ->orderBy('name')
            ->limit($limit)
            ->get();
        $authors = $authorCollection->map(fn (Author $author) => [
            'id' => $author->id,
            'name' => $author->name,
            'book_count' => $author->books()->count(),
        ]);

        return response()->json([
            'data' => $authors,
        ]);
    }

    /**
     * Create a new author.
     *
     * @return JsonResponse
     */
    public function createAuthor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $name = trim($request->input('name'));

        /** @var Author|null $existingAuthor */
        $existingAuthor = Author::where('name', $name)->first();
        if ($existingAuthor) {
            return response()->json([
                'data' => [
                    'id' => $existingAuthor->id,
                    'name' => $existingAuthor->name,
                ],
                'created' => false,
            ]);
        }

        /** @var Author $author */
        $author = Author::create(['name' => $name]);

        Log::info('BookImportApiController: Created author via API', [
            'author_id' => $author->id,
            'name' => $name,
            'user_id' => Auth::id(),
        ]);

        return response()->json([
            'data' => [
                'id' => $author->id,
                'name' => $author->name,
            ],
            'created' => true,
        ], 201);
    }

    /**
     * Search for existing series by name.
     *
     * @return JsonResponse
     */
    public function searchSeries(Request $request): JsonResponse
    {
        $query = $request->input('q', $request->input('query', '')) ?? '';
        $limit = min((int) $request->input('limit', 20), 100);

        if (strlen($query) < 1) {
            return response()->json(['data' => []]);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Series> $seriesCollection */
        $seriesCollection = Series::where('name', 'like', '%' . $query . '%')
            ->orderBy('name')
            ->limit($limit)
            ->get();
        $series = $seriesCollection->map(fn (Series $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'is_collection' => $s->is_collection,
            'book_count' => $s->books()->count(),
        ]);

        return response()->json([
            'data' => $series,
        ]);
    }

    /**
     * Create a new series.
     *
     * @return JsonResponse
     */
    public function createSeries(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'is_collection' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $name = trim($request->input('name'));
        $isCollection = (bool) $request->input('is_collection', false);

        /** @var Series|null $existingSeries */
        $existingSeries = Series::where('name', $name)->first();
        if ($existingSeries) {
            return response()->json([
                'data' => [
                    'id' => $existingSeries->id,
                    'name' => $existingSeries->name,
                    'is_collection' => $existingSeries->is_collection,
                ],
                'created' => false,
            ]);
        }

        /** @var Series $series */
        $series = Series::create([
            'name' => $name,
            'is_collection' => $isCollection,
        ]);

        Log::info('BookImportApiController: Created series via API', [
            'series_id' => $series->id,
            'name' => $name,
            'user_id' => Auth::id(),
        ]);

        return response()->json([
            'data' => [
                'id' => $series->id,
                'name' => $series->name,
                'is_collection' => $series->is_collection,
            ],
            'created' => true,
        ], 201);
    }

    /**
     * Get the status of a queued import.
     *
     * @param string $importId The import ID (e.g., 'import-uuid')
     * @return JsonResponse
     */
    public function getImportStatus(string $importId): JsonResponse
    {
        $status = $this->queueService->getStatus($importId);

        if ($status) {
            return response()->json([
                'found' => true,
                'status' => $status,
            ]);
        }

        $pendingImports = $this->queueService->getPendingImports();
        $isPending = false;
        $pendingInfo = null;

        foreach ($pendingImports as $import) {
            if ($import['id'] === $importId) {
                $isPending = true;
                $pendingInfo = [
                    'id' => $import['id'],
                    'title' => $import['metadata']['title'] ?? 'Unknown',
                    'queued_at' => $import['queued_at'],
                ];
                break;
            }
        }

        if ($isPending) {
            return response()->json([
                'found' => true,
                'status' => [
                    'state' => 'pending',
                    'import_id' => $importId,
                    'pending_info' => $pendingInfo,
                ],
            ]);
        }

        return response()->json([
            'found' => false,
            'status' => null,
            'message' => 'Import not found in queue or status history',
        ], 404);
    }

    /**
     * Get queue statistics.
     *
     * @return JsonResponse
     */
    public function getQueueStats(): JsonResponse
    {
        $stats = $this->queueService->getQueueStats();

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get all pending imports in the queue.
     *
     * @return JsonResponse
     */
    public function getPendingImports(Request $request): JsonResponse
    {
        $pendingImports = $this->queueService->getPendingImports();

        $simplified = array_map(function ($import) {
            return [
                'id' => $import['id'],
                'title' => $import['metadata']['title'] ?? 'Unknown',
                'authors' => $import['metadata']['authors'] ?? $import['metadata']['author'] ?? [],
                'queued_at' => $import['queued_at'],
            ];
        }, $pendingImports);

        return response()->json([
            'data' => $simplified,
            'count' => count($simplified),
        ]);
    }
}
