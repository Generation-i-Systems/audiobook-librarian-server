<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Services\BookDirectoryMoveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookPathController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * Check if a directory path is already used by another book
     */
    public function checkDirectoryConflict(Request $request)
    {
        $validated = $request->validate([
            'directoryPath' => 'required|string',
            'currentBookId' => 'nullable|string',
        ]);

        $directoryPath = trim($validated['directoryPath']);
        $currentBookId = $validated['currentBookId'] ?? null;

        if (empty($directoryPath)) {
            return response()->json([
                'conflict' => false,
            ]);
        }

        try {
            // Search for books with this directory path
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
            if (!empty($results['books'])) {
                foreach ($results['books'] as $book) {
                    // Skip the current book being edited
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
                return response()->json([
                    'conflict' => false,
                ]);
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
    public function buildPathFromFields(Request $request)
    {
        $validated = $request->validate([
            'genre' => 'nullable|string',
            'author' => 'nullable|string',
            'series' => 'nullable|string',
            'seriesNumber' => 'nullable|string',
            'title' => 'nullable|string',
        ]);

        $parts = [];

        // Build path in order: genre/author/series/{seriesNumber title}
        // Note: seriesNumber and title are combined in the final segment
        if (!empty($validated['genre'])) {
            $parts[] = preg_replace('/[\\/]/', '-', trim($validated['genre']));
        }
        if (!empty($validated['author'])) {
            $parts[] = preg_replace('/[\\/]/', '-', trim($validated['author']));
        }
        if (!empty($validated['series'])) {
            $parts[] = preg_replace('/[\\/]/', '-', trim($validated['series']));
        }

        // Combine seriesNumber and title into final segment
        $finalSegment = [];
        if (!empty($validated['seriesNumber'])) {
            // Zero-pad series number to 2 digits
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
            $combined = implode(' ', $finalSegment);
            $parts[] = preg_replace('/[\\/]/', '-', $combined);
        }

        if (empty($parts)) {
            return response()->json([
                'success' => false,
                'message' => 'At least one field must be provided to build a path',
            ], 400);
        }

        $directoryPath = implode(DIRECTORY_SEPARATOR, $parts);

        return response()->json([
            'success' => true,
            'directoryPath' => $directoryPath,
        ]);
    }

    /**
     * Execute an immediate directory move (before form submission)
     */
    public function executeImmediateMove(Request $request, string $id)
    {
        $validated = $request->validate([
            'oldDirectoryPath' => 'required|string',
            'newDirectoryPath' => 'required|string',
        ]);

        $oldDirectoryPath = trim($validated['oldDirectoryPath']);
        $newDirectoryPath = trim($validated['newDirectoryPath']);

        if ($oldDirectoryPath === $newDirectoryPath) {
            return response()->json([
                'success' => false,
                'message' => 'Old and new paths are the same',
            ], 400);
        }

        try {
            $book = $this->documentStoreService->getBook($id);
            if (!$book) {
                return response()->json([
                    'success' => false,
                    'message' => 'Book not found',
                ], 404);
            }

            $oldCoverBasename = !empty($book['coverImage']) ? basename((string) $book['coverImage']) : null;
            $moveService = app(BookDirectoryMoveService::class);
            $moveResult = $moveService->moveBookDirectoryContents(
                $oldDirectoryPath,
                $newDirectoryPath,
                $oldCoverBasename
            );

            if (!$moveResult['moved']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to move directory contents',
                ], 500);
            }

            $actualNewPath = $moveResult['directoryPath'] ?? $newDirectoryPath;
            $newCoverImage = $moveResult['coverImage'] ?? $oldCoverBasename;

            // Update the book in the database
            $book['directoryPath'] = $actualNewPath;
            if ($newCoverImage) {
                $book['coverImage'] = $newCoverImage;
            }

            $this->documentStoreService->updateBook($id, $book);

            Log::info('Immediate directory move executed', [
                'bookId' => $id,
                'oldPath' => $oldDirectoryPath,
                'newPath' => $actualNewPath,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Directory moved successfully',
                'directoryPath' => $actualNewPath,
                'coverImage' => $newCoverImage,
            ]);
        } catch (\Exception $e) {
            Log::error('Error executing immediate move: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error moving directory: ' . $e->getMessage(),
            ], 500);
        }
    }
}
