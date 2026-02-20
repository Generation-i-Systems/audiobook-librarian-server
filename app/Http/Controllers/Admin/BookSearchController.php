<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AudibleService;
use App\Services\AudiobookBayService;
use App\Services\GoogleBooksApiService;
use App\Services\HardcoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookSearchController extends Controller
{
    protected AudibleService $audibleService;
    protected GoogleBooksApiService $googleBooksApiService;

    public function __construct(
        AudibleService $audibleService,
        GoogleBooksApiService $googleBooksApiService
    ) {
        $this->audibleService = $audibleService;
        $this->googleBooksApiService = $googleBooksApiService;
    }

    /**
     * Unified search endpoint for all book APIs (Audible, Google Books, etc.)
     */
    public function searchBooks(Request $request): JsonResponse
    {
        $title = $request->input('title');
        $author = $request->input('author', '');
        $apiId = $request->input('api_id', '');
        $source = $request->input('source', 'audible');
        $limit = min((int) $request->input('limit', 10), 40);

        if (!$apiId) {
            $requiresTitle = true;
            if (strtolower($source) === 'googlebooks' && !empty($author)) {
                $requiresTitle = false;
            }
            if ($requiresTitle && empty($title)) {
                return response()->json([
                    'error' => 'Title or API ID is required.',
                ], 400);
            }
        }

        try {
            $results = [];

            switch (strtolower($source)) {
                case 'audible':
                    if ($apiId) {
                        $bookDetails = $this->audibleService->getBookDetails($apiId);
                        if ($bookDetails) {
                            $results[] = $bookDetails;
                        }
                    } else {
                        $authorParam = !empty($author) ? $author : null;
                        $results = $this->audibleService->searchBooksWithFiltering($title, $authorParam, [
                            'limit' => $limit,
                        ]);
                    }
                    break;

                case 'googlebooks':
                    $authorQuery = '';
                    if (!empty($author)) {
                        $authorQuery = " inauthor:\"{$author}\"";
                    }
                    $titleQuery = !empty($title) ? "intitle:\"{$title}\"" : '';
                    $query = trim($titleQuery . $authorQuery);
                    $results = $this->googleBooksApiService->searchBooks($query, ['limit' => $limit]);
                    break;

                case 'audiobookbay':
                    $audiobookBayService = app(AudiobookBayService::class);
                    if ($apiId) {
                        $bookDetails = $audiobookBayService->getBookDetails($apiId);
                        if ($bookDetails) {
                            $results[] = $bookDetails;
                        }
                    } else {
                        $searchQuery = trim($title . ' ' . $author);
                        $searchResults = $audiobookBayService->searchBooks($searchQuery, ['limit' => $limit]);
                        $results = is_array($searchResults) ? $searchResults : [];
                    }
                    break;

                case 'hardcover':
                    $hardcoverService = app(HardcoverService::class);
                    if (!$hardcoverService->isAvailable()) {
                        return response()->json([
                            'error' => 'Hardcover service is not configured. Please set HARDCOVER_API_KEY in your .env file.',
                        ], 400);
                    }

                    if ($apiId) {
                        $bookDetails = $hardcoverService->getBookDetails($apiId);
                        if ($bookDetails) {
                            $results[] = $bookDetails;
                        }
                    } else {
                        $searchResults = $hardcoverService->searchBooks($title, [
                            'author' => $author,
                            'limit' => $limit,
                        ]);
                        $results = is_array($searchResults) ? $searchResults : [];
                    }
                    break;

                default:
                    return response()->json([
                        'error' => 'Invalid source specified. Supported sources: audible, googlebooks, audiobookbay, hardcover',
                    ], 400);
            }

            return response()->json($results);
        } catch (\Exception $e) {
            Log::error($source . ' search failed: ' . $e->getMessage(), [
                'exception' => $e,
                'source' => $source,
                'title' => $title,
                'author' => $author,
                'api_id' => $apiId,
            ]);

            return response()->json([
                'error' => ucfirst($source) . ' search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search Google Books for books matching the given criteria.
     * @deprecated Use searchBooks with source=googlebooks instead
     */
    public function googleBooks(Request $request): JsonResponse
    {
        $title = $request->query('title');
        $author = $request->query('author', '');
        if (!$title) {
            return response()->json(['error' => 'Title is required.'], 400);
        }

        $limit = min((int) $request->query('limit', 10), 40);

        try {
            $authorQuery = !empty($author) ? " inauthor:\"{$author}\"" : '';
            $query = trim("intitle:\"{$title}\"" . $authorQuery);
            $results = $this->googleBooksApiService->searchBooks($query, ['limit' => $limit]);
            return response()->json($results);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Google Books search failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Search Audible for books matching the given criteria.
     * @deprecated Use searchBooks with source=audible instead
     */
    public function audible(Request $request): JsonResponse
    {
        $title = $request->query('title');
        $author = $request->query('author', '');
        $apiId = $request->query('api_id', '');

        if (!$title && !$apiId) {
            return response()->json(['error' => 'Title or ASIN is required.'], 400);
        }

        $limit = min((int) $request->query('limit', 10), 40);

        try {
            if ($apiId) {
                $bookDetails = $this->audibleService->getBookDetails($apiId);
                return $bookDetails ? response()->json($bookDetails) : response()->json(null, 404);
            } else {
                $results = $this->audibleService->searchBooksWithFiltering($title, $author, [
                    'limit' => $limit,
                ]);
                return response()->json($results);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Audible search failed: ' . $e->getMessage()], 500);
        }
    }
}
