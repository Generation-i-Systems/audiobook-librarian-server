<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class BookmarkApiController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }


    /**
     * Get all bookmarks for a book (OpenAPI spec version)
     */
    public function getBookmarksOpenApi(Request $request, string $bookId)
    {
        // Verify the book exists
        $book = $this->documentStoreService->getBook($bookId);
        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        // Get user ID from authenticated user
        $userId = Auth::id();

        // Get bookmarks from the document store
        $bookmarks = $this->documentStoreService->getBookmarks($userId, $bookId);

        // Format response to match OpenAPI spec
        $formattedBookmarks = [];
        foreach ($bookmarks as $bookmark) {
            $formattedBookmarks[] = [
                'id' => (int) ($bookmark['id'] ?? $bookmark['_id']),
                'book_id' => (int) ($bookmark['bookId'] ?? $bookmark['book_id']),
                // @phpstan-ignore-next-line
                'position_ms' => ((int) ($bookmark['position'] ?? 0)) * 1000, // Convert to milliseconds
                'title' => $bookmark['title'] ?? null,
                'note' => $bookmark['notes'] ?? $bookmark['note'] ?? null,
                'is_auto' => (bool) ($bookmark['isAuto'] ?? $bookmark['is_auto'] ?? false),
                'created_at' => $bookmark['createdAt'] ?? $bookmark['created_at'] ?? now()->toISOString(),
            ];
        }

        return response()->json(['bookmarks' => $formattedBookmarks]);
    }

    /**
     * Create a new bookmark (OpenAPI spec version)
     */
    public function createBookmarkOpenApi(Request $request, string $bookId)
    {
        // Validate request (manual to ensure JSON 422 without relying on global handler)
        $validator = Validator::make($request->all(), [
            'position_ms' => 'required|integer|min:0',
            'title' => 'nullable|string|max:255',
            'note' => 'nullable|string',
            'is_auto' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verify the book exists
        $book = $this->documentStoreService->getBook($bookId);
        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        // Get user ID from authenticated user
        $userId = Auth::id();

        // Create bookmark data
        $bookmarkData = [
            'user_id' => $userId,
            'book_id' => (int) $bookId,
            'chapter' => '1', // Default chapter for compatibility
            'position' => (int) ($request->input('position_ms') / 1000), // Convert from milliseconds
            'title' => $request->input('title'),
            'notes' => $request->input('note'),
            'is_auto' => $request->input('is_auto', false),
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        // Insert bookmark into the document store
        $bookmarkId = $this->documentStoreService->createBookmark($bookmarkData);

        // Format response
        return response()->json([
            'id' => (int) $bookmarkId,
            'book_id' => (int) $bookId,
            'position_ms' => $request->input('position_ms'),
            'title' => $request->input('title'),
            'note' => $request->input('note'),
            'is_auto' => $request->input('is_auto', false),
            'created_at' => now()->toISOString(),
        ], 201);
    }

    /**
     * Get all bookmarks for a book (existing method for backward compatibility)
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
        $userId = Auth::id();

        // Get bookmarks from the document store
        $bookmarks = $this->documentStoreService->getBookmarks($userId, $bookId);

        // Format response
        $formattedBookmarks = [];
        foreach ($bookmarks as $bookmark) {
            $formattedBookmarks[] = [
                'id' => (string) ($bookmark['id'] ?? $bookmark['_id'] ?? ''),
                'book_id' => $bookmark['bookId'] ?? $bookmark['book_id'] ?? 0,
                'chapter' => $bookmark['chapter'] ?? 1,
                'position' => $bookmark['position'] ?? 0,
                'title' => $bookmark['title'] ?? '',
                'note' => $bookmark['notes'] ?? $bookmark['note'] ?? '',
                'created_at' => $bookmark['createdAt'] ?? $bookmark['created_at'] ?? now()->toISOString(),
                'updated_at' => $bookmark['updatedAt'] ?? $bookmark['updated_at'] ?? now()->toISOString(),
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
        // Validate request (manual to ensure JSON 422 without relying on global handler)
        $validator = Validator::make($request->all(), [
            'chapter' => 'required|integer|min:1',
            'position' => 'required|integer|min:0',
            'title' => 'nullable|string|max:255',
            'note' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verify the book exists
        $book = $this->documentStoreService->getBook($bookId);
        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        // Get user ID from authenticated user
        $userId = Auth::id();

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
        // @phpstan-ignore-next-line
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
        $userId = Auth::id();

        // Get bookmark from document store
        $bookmark = $this->documentStoreService->getBookmark($bookmarkId, $userId, $bookId);

        if (!$bookmark) {
            return response()->json(['error' => 'Bookmark not found'], 404);
        }

        // Format response
        $formattedBookmark = [
            'id' => (string) ($bookmark['id'] ?? $bookmark['_id'] ?? ''),
            'book_id' => $bookmark['bookId'] ?? $bookmark['book_id'] ?? 0,
            'chapter' => $bookmark['chapter'] ?? 1,
            'position' => $bookmark['position'] ?? 0,
            'title' => $bookmark['title'] ?? '',
            'note' => $bookmark['notes'] ?? $bookmark['note'] ?? '',
            'created_at' => $bookmark['createdAt'] ?? $bookmark['created_at'] ?? now()->toISOString(),
            'updated_at' => $bookmark['updatedAt'] ?? $bookmark['updated_at'] ?? now()->toISOString(),
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
        // Validate request (manual to ensure JSON 422 without relying on global handler)
        $validator = Validator::make($request->all(), [
            'chapter' => 'sometimes|integer|min:1',
            'position' => 'sometimes|integer|min:0',
            'title' => 'sometimes|nullable|string|max:255',
            'note' => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Get user ID from authenticated user
        $userId = Auth::id();

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
            'id' => (string) ($updatedBookmark['id'] ?? $updatedBookmark['_id'] ?? ''),
            'book_id' => $updatedBookmark['bookId'] ?? $updatedBookmark['book_id'] ?? 0,
            'chapter' => $updatedBookmark['chapter'] ?? 1,
            'position' => $updatedBookmark['position'] ?? 0,
            'title' => $updatedBookmark['title'] ?? '',
            'note' => $updatedBookmark['notes'] ?? $updatedBookmark['note'] ?? '',
            'created_at' => $updatedBookmark['createdAt'] ?? $updatedBookmark['created_at'] ?? now()->toISOString(),
            'updated_at' => $updatedBookmark['updatedAt'] ?? $updatedBookmark['updated_at'] ?? now()->toISOString(),
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
        $userId = Auth::id();

        // Delete bookmark from the document store
        $result = $this->documentStoreService->deleteBookmark($bookmarkId, $userId, $bookId);

        if (!$result) {
            return response()->json(['error' => 'Bookmark not found'], 404);
        }

        return response()->json(null, 204);
    }

    /**
     * Delete a bookmark by ID (without book context)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteBookmarkById(Request $request, string $bookmarkId)
    {
        $userId = Auth::id();

        $result = $this->documentStoreService->deleteBookmarkById($bookmarkId, $userId);

        if (!$result) {
            return response()->json(['error' => 'Bookmark not found'], 404);
        }

        return response()->json(null, 204);
    }
}
