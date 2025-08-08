<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BookmarkApiController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
        $this->middleware('auth:api');
    }


    /**
     * Get all bookmarks for a book
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBookmarks(Request $request, string $bookId)
    {
        // Verify the book exists
        $book = $this->documentStoreService->getBook($bookId);
        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        // Get user ID from authenticated user
        $userId = auth('api')->id();

        // Get bookmarks from the document store
        $bookmarks = $this->documentStoreService->getBookmarks($userId, $bookId);

        // Format response
        $formattedBookmarks = [];
        foreach ($bookmarks as $bookmark) {
            $formattedBookmarks[] = [
                'id' => (string) $bookmark['_id'],
                'book_id' => $bookmark['book_id'],
                'chapter' => $bookmark['chapter'] ?? 1,
                'position' => $bookmark['position'] ?? 0,
                'title' => $bookmark['title'] ?? '',
                'note' => $bookmark['note'] ?? '',
                'created_at' => $bookmark['created_at'] ?? now()->toISOString(),
                'updated_at' => $bookmark['updated_at'] ?? now()->toISOString(),
            ];
        }

        return response()->json(['data' => $formattedBookmarks]);
    }


    /**
     * Create a new bookmark
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function createBookmark(Request $request, string $bookId)
    {
        // Validate request
        $request->validate([
            'chapter' => 'required|integer|min:1',
            'position' => 'required|integer|min:0',
            'title' => 'nullable|string|max:255',
            'note' => 'nullable|string',
        ]);

        // Verify the book exists
        $book = $this->documentStoreService->getBook($bookId);
        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        // Get user ID from authenticated user
        $userId = auth('api')->id();

        // Create bookmark data
        $bookmarkData = [
            'user_id' => $userId,
            'book_id' => $bookId,
            'chapter' => (int) $request->input('chapter'),
            'position' => (int) $request->input('position'),
            'title' => $request->input('title', ''),
            'note' => $request->input('note', ''),
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        // Insert bookmark into the document store
        $bookmarkId = $this->documentStoreService->createBookmark($bookmarkData);

        // Format response
        $bookmarkData['id'] = $bookmarkId;
        unset($bookmarkData['_id']);

        return response()->json($bookmarkData, 201);
    }


    /**
     * Get a specific bookmark
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBookmark(Request $request, string $bookId, string $bookmarkId)
    {
        // Get user ID from authenticated user
        $userId = auth('api')->id();

        // Get bookmark from document store
        $bookmark = $this->documentStoreService->getBookmark($bookmarkId, $userId, $bookId);

        if (!$bookmark) {
            return response()->json(['error' => 'Bookmark not found'], 404);
        }

        // Format response
        $formattedBookmark = [
            'id' => (string) $bookmark['_id'],
            'book_id' => $bookmark['book_id'],
            'chapter' => $bookmark['chapter'] ?? 1,
            'position' => $bookmark['position'] ?? 0,
            'title' => $bookmark['title'] ?? '',
            'note' => $bookmark['note'] ?? '',
            'created_at' => $bookmark['created_at'] ?? now()->toISOString(),
            'updated_at' => $bookmark['updated_at'] ?? now()->toISOString(),
        ];

        return response()->json($formattedBookmark);
    }


    /**
     * Update a bookmark
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateBookmark(Request $request, string $bookId, string $bookmarkId)
    {
        // Validate request
        $request->validate([
            'chapter' => 'sometimes|integer|min:1',
            'position' => 'sometimes|integer|min:0',
            'title' => 'sometimes|nullable|string|max:255',
            'note' => 'sometimes|nullable|string',
        ]);

        // Get user ID from authenticated user
        $userId = auth('api')->id();

        // Get bookmark from document store
        $bookmark = $this->documentStoreService->getBookmark($bookmarkId, $userId, $bookId);

        if (!$bookmark) {
            return response()->json(['error' => 'Bookmark not found'], 404);
        }

        // Update fields
        $updateData = [
            'updated_at' => now()->toISOString(),
        ];

        if ($request->has('chapter')) {
            $updateData['chapter'] = (int) $request->input('chapter');
        }

        if ($request->has('position')) {
            $updateData['position'] = (int) $request->input('position');
        }

        if ($request->has('title')) {
            $updateData['title'] = $request->input('title');
        }

        if ($request->has('note')) {
            $updateData['note'] = $request->input('note');
        }

        // Update bookmark in the document store
        $this->documentStoreService->updateBookmark($bookmarkId, $updateData);

        // Get updated bookmark
        $updatedBookmark = $this->documentStoreService->getBookmark($bookmarkId, $userId, $bookId);

        // Format response
        $formattedBookmark = [
            'id' => (string) $updatedBookmark['_id'],
            'book_id' => $updatedBookmark['book_id'],
            'chapter' => $updatedBookmark['chapter'] ?? 1,
            'position' => $updatedBookmark['position'] ?? 0,
            'title' => $updatedBookmark['title'] ?? '',
            'note' => $updatedBookmark['note'] ?? '',
            'created_at' => $updatedBookmark['created_at'] ?? now()->toISOString(),
            'updated_at' => $updatedBookmark['updated_at'] ?? now()->toISOString(),
        ];

        return response()->json($formattedBookmark);
    }


    /**
     * Delete a bookmark
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteBookmark(Request $request, string $bookId, string $bookmarkId)
    {
        // Get user ID from authenticated user
        $userId = auth('api')->id();

        // Delete bookmark from the document store
        $result = $this->documentStoreService->deleteBookmark($bookmarkId, $userId, $bookId);

        if (!$result) {
            return response()->json(['error' => 'Bookmark not found'], 404);
        }

        return response()->json(null, 204);
    }
}
