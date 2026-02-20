<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Services\BookDirectoryParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookAjaxController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * AJAX endpoint for Tom Select: returns series matching query string, or all if no query.
     */
    public function seriesAjax(Request $request): JsonResponse
    {
        $q = $request->input('q', '');
        $series = $this->documentStoreService->listSeries();
        if ($q) {
            $series = array_filter($series, function ($item) use ($q) {
                return stripos($item['name'], $q) !== false;
            });
        }
        usort($series, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        $series = array_slice($series, 0, 20);

        return response()->json(['data' => $series]);
    }

    /**
     * Rename a series across all books
     */
    public function renameSeries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'oldName' => 'required|string',
            'newName' => 'required|string',
            'merge' => 'boolean',
        ]);

        $oldName = $validated['oldName'];
        $newName = $validated['newName'];
        $merge = $validated['merge'] ?? false;

        if ($oldName === $newName) {
            return response()->json([
                'success' => false,
                'message' => 'New name must be different from current name.',
            ], 400);
        }

        try {
            $newSeriesExists = $this->documentStoreService->getSeriesByName($newName) !== null;

            if ($newSeriesExists && !$merge) {
                $oldSeriesBooks = $this->documentStoreService->listBooks(
                    1,
                    1,
                    ['series' => $oldName, 'include_needs_review' => true],
                    false,
                    'title',
                    'asc',
                    true
                );
                $bookCount = (int) ($oldSeriesBooks['total'] ?? 0);

                return response()->json([
                    'success' => false,
                    'warning' => "A series named '{$newName}' already exists with other books.",
                    'old_name' => $oldName,
                    'new_name' => $newName,
                    'book_count' => $bookCount,
                ]);
            }

            $count = $this->documentStoreService->renameSeries($oldName, $newName);

            return response()->json([
                'success' => true,
                'merged' => $newSeriesExists,
                'count' => $count,
                'message' => $newSeriesExists
                    ? "Successfully merged series '{$oldName}' into '{$newName}' for {$count} book(s)."
                    : "Successfully renamed series from '{$oldName}' to '{$newName}' for {$count} book(s).",
            ]);
        } catch (\Exception $e) {
            Log::error('Error renaming series: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while renaming the series: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if a directory path is already used by another book
     */
    public function checkDirectoryConflict(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'directoryPath' => 'required|string',
            'currentBookId' => 'nullable|string',
        ]);

        $directoryPath = trim($validated['directoryPath']);
        $currentBookId = $validated['currentBookId'] ?? null;

        if (empty($directoryPath)) {
            return response()->json(['conflict' => false]);
        }

        try {
            $results = $this->documentStoreService->listBooks(
                1,
                100,
                ['directoryPath' => $directoryPath],
                false,
                'title',
                'asc',
                true
            );

            $conflictingBooks = [];
            if (!empty($results['data'])) {
                foreach ($results['data'] as $book) {
                    if ($currentBookId && $book['id'] === $currentBookId) {
                        continue;
                    }

                    $authorDisplay = $book['author'] ?? 'Unknown';
                    if (is_array($authorDisplay)) {
                        $authorDisplay = implode(', ', $authorDisplay);
                    }
                    $conflictingBooks[] = [
                        'id' => $book['id'],
                        'title' => $book['title'] ?? 'Unknown',
                        'author' => $authorDisplay,
                    ];
                }
            }

            if (empty($conflictingBooks)) {
                return response()->json(['conflict' => false]);
            }

            return response()->json([
                'conflict' => true,
                'books' => $conflictingBooks,
                'count' => count($conflictingBooks),
            ]);
        } catch (\Exception $e) {
            Log::error('Error checking directory conflict: ' . $e->getMessage());
            return response()->json([
                'conflict' => false,
                'error' => 'Failed to check for conflicts',
            ], 500);
        }
    }

    /**
     * Build directory path from form fields (genre, author, series, title)
     */
    public function buildPathFromFields(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'genre' => 'nullable|string',
            'author' => 'nullable|string',
            'series' => 'nullable|string',
            'seriesNumber' => 'nullable|string',
            'title' => 'nullable|string',
        ]);

        $parts = [];
        if (!empty($validated['genre'])) {
            $parts[] = preg_replace('/[\\/]/', '-', trim($validated['genre']));
        }
        if (!empty($validated['author'])) {
            $parts[] = preg_replace('/[\\/]/', '-', trim($validated['author']));
        }
        if (!empty($validated['series'])) {
            $parts[] = preg_replace('/[\\/]/', '-', trim($validated['series']));
        }

        $finalSegment = [];
        if (!empty($validated['seriesNumber'])) {
            $seriesNum = trim($validated['seriesNumber']);
            if (is_numeric($seriesNum)) {
                $seriesNum = str_pad($seriesNum, 2, '0', STR_PAD_LEFT);
            }
            $finalSegment[] = $seriesNum;
        }
        if (!empty($validated['title'])) {
            $finalSegment[] = trim($validated['title']);
        }
        if (!empty($finalSegment)) {
            $parts[] = preg_replace('/[\\/]/', '-', implode(' ', $finalSegment));
        }

        if (empty($parts)) {
            return response()->json([
                'success' => false,
                'message' => 'At least one field must be provided to build a path',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'directoryPath' => implode(DIRECTORY_SEPARATOR, $parts),
        ]);
    }

    /**
     * AJAX: Resync title, author, and series from a directory path.
     */
    public function resyncFromPath(Request $request): JsonResponse
    {
        $request->validate(['directoryPath' => 'required|string']);
        $directoryPath = $request->input('directoryPath');
        try {
            $parser = new BookDirectoryParser();
            $absPath = $parser->resolveStoragePath($directoryPath);
            if (!is_dir($absPath)) {
                return response()->json(['success' => false, 'message' => 'Directory does not exist . '], 404);
            }
            $parsed = $parser->parseDirectory($absPath);
            $book = count($parsed) > 0 ? $parsed[0] : null;
            if (!$book) {
                return response()->json(['success' => false, 'message' => 'Could not parse directory . ']);
            }
            $authors = [];
            if (!empty($book['author'])) {
                $authors = is_array($book['author']) ? $book['author'] : [$book['author']];
            }
            $series = [];
            if (!empty($book['series']) && is_array($book['series'])) {
                foreach ($book['series'] as $name => $number) {
                    $series[] = ['name' => $name, 'number' => $number];
                }
            } elseif (!empty($book['series'])) {
                $series[] = ['name' => $book['series'], 'number' => $book['seriesNumber'] ?? ''];
            }

            return response()->json([
                'success' => true,
                'title' => $book['title'] ?? '',
                'authors' => $authors,
                'series' => $series,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function autocompleteAuthors(Request $request): JsonResponse
    {
        $term = $request->input('term', '');
        return response()->json(empty($term) ? [] : $this->documentStoreService->searchAuthorsByName($term));
    }

    public function autocompleteSeries(Request $request): JsonResponse
    {
        $term = $request->input('query', $request->input('term', ''));
        $limit = (int) $request->input('limit', 10);

        if (empty($term) || strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $series = $this->documentStoreService->searchSeriesByName($term);
        if (count($series) > $limit) {
            $series = array_slice($series, 0, $limit);
        }

        if ($request->has('query')) {
            $seriesNames = collect($series)->pluck('seriesName')->unique()->values()->all();
            return response()->json(['data' => $seriesNames]);
        }
        return response()->json($series);
    }

    public function autocompleteNarrators(Request $request): JsonResponse
    {
        $term = $request->input('term', '');
        return response()->json(empty($term) ? [] : $this->documentStoreService->searchNarratorsByName($term));
    }

    public function autocompleteGenres(Request $request): JsonResponse
    {
        $term = $request->input('query', $request->input('term', ''));
        return response()->json(empty($term) ? [] : $this->documentStoreService->searchGenresByName($term));
    }
}
