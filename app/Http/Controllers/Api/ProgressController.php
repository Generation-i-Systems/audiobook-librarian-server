<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookProgress;
use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ProgressController extends Controller
{
    /**
     * Get progress for a specific book (OpenAPI spec version)
     */
    public function getBookProgress(Request $request, int $bookId): JsonResponse
    {
        $book = Book::find($bookId);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found'
            ], 404);
        }

        // Use authenticated user's device/session instead of requiring device_id
        $userId = auth('api')->id() ?? $request->header('X-Device-ID', 'unknown');
        
        $progress = BookProgress::where('book_id', $bookId)
            ->where('device_id', $userId)
            ->first();

        if (!$progress) {
            return response()->json([
                'book_id' => $bookId,
                'position_ms' => 0,
                'progress_percentage' => 0,
                'last_updated' => now()->toISOString(),
                'is_finished' => false,
            ]);
        }

        return response()->json([
            'book_id' => $progress->book_id,
            'position_ms' => $progress->current_position_seconds * 1000,
            'progress_percentage' => (int) round($progress->progress_percentage),
            'last_updated' => $progress->updated_at->toISOString(),
            'is_finished' => $progress->completed,
        ]);
    }

    /**
     * Update progress for a specific book (OpenAPI spec version)
     */
    public function updateBookProgress(Request $request, int $bookId): JsonResponse
    {
        $validated = $request->validate([
            'position_ms' => 'required|integer|min:0',
            'progress_percentage' => 'required|integer|min:0|max:100',
            'session_duration_ms' => 'nullable|integer|min:0',
            'playback_speed' => 'nullable|numeric|min:0.1|max:5.0',
        ]);

        $book = Book::find($bookId);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found'
            ], 404);
        }

        $userId = auth('api')->id() ?? $request->header('X-Device-ID', 'unknown');
        
        $progress = BookProgress::updateOrCreate(
            [
                'book_id' => $bookId,
                'device_id' => $userId,
            ],
            [
                'user_id' => auth('api')->id(),
            ]
        );

        $progress->updateProgress(
            (int) ($validated['position_ms'] / 1000),
            null
        );

        $progress->progress_percentage = $validated['progress_percentage'];
        $progress->completed = $validated['progress_percentage'] >= 100;
        if ($progress->completed && !$progress->completed_at) {
            $progress->completed_at = now();
        }
        $progress->save();

        return response()->json([
            'book_id' => $progress->book_id,
            'position_ms' => $progress->current_position_seconds * 1000,
            'progress_percentage' => (int) round($progress->progress_percentage),
            'last_updated' => $progress->updated_at->toISOString(),
            'is_finished' => $progress->completed,
        ]);
    }

    /**
     * Get all progress (OpenAPI spec version)
     */
    public function getAllProgress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'active_only' => 'nullable|boolean',
        ]);

        $userId = auth('api')->id() ?? $request->header('X-Device-ID', 'unknown');
        
        $query = BookProgress::with('book')
            ->where('device_id', $userId)
            ->orderBy('last_listened_at', 'desc');

        if ($validated['active_only'] ?? false) {
            $query->where('completed', false);
        }

        $progressList = $query->get();

        $progress = $progressList->map(function ($progress) {
            return [
                'book_id' => $progress->book_id,
                'position_ms' => $progress->current_position_seconds * 1000,
                'progress_percentage' => (int) round($progress->progress_percentage),
                'last_updated' => $progress->updated_at->toISOString(),
                'is_finished' => $progress->completed,
            ];
        });

        return response()->json([
            'progress' => $progress
        ]);
    }

    /**
     * Get progress for a specific book and device (existing method for backward compatibility)
     */
    public function getProgress(Request $request, int $bookId): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
        ]);

        $book = Book::find($bookId);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found'
            ], 404);
        }

        $progress = BookProgress::where('book_id', $bookId)
            ->where('device_id', $validated['device_id'])
            ->first();

        if (!$progress) {
            return response()->json([
                'book_id' => $bookId,
                'device_id' => $validated['device_id'],
                'current_position_seconds' => 0,
                'total_duration_seconds' => null,
                'progress_percentage' => 0.00,
                'current_chapter' => null,
                'current_chapter_name' => null,
                'last_listened_at' => null,
                'completed' => false,
                'completed_at' => null,
                'formatted_progress' => '0:00',
                'formatted_duration' => '0:00',
            ]);
        }

        return response()->json([
            'book_id' => $progress->book_id,
            'device_id' => $progress->device_id,
            'current_position_seconds' => $progress->current_position_seconds,
            'total_duration_seconds' => $progress->total_duration_seconds,
            'progress_percentage' => $progress->progress_percentage,
            'current_chapter' => $progress->current_chapter,
            'current_chapter_name' => $progress->current_chapter_name,
            'last_listened_at' => $progress->last_listened_at?->toISOString(),
            'completed' => $progress->completed,
            'completed_at' => $progress->completed_at?->toISOString(),
            'formatted_progress' => $progress->formatted_progress,
            'formatted_duration' => $progress->formatted_duration,
        ]);
    }

    /**
     * Update progress for a specific book and device
     */
    public function updateProgress(Request $request, int $bookId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'device_id' => 'required|string|max:255',
                'current_position_seconds' => 'required|integer|min:0',
                'total_duration_seconds' => 'nullable|integer|min:1',
                'current_chapter' => 'nullable|integer|min:1',
                'current_chapter_name' => 'nullable|string|max:255',
                'user_id' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => 'Invalid input data',
                'errors' => $e->errors()
            ], 422);
        }

        $book = Book::find($bookId);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found'
            ], 404);
        }

        $progress = BookProgress::updateOrCreate(
            [
                'book_id' => $bookId,
                'device_id' => $validated['device_id'],
            ],
            [
                'user_id' => $validated['user_id'] ?? null,
                'current_chapter' => $validated['current_chapter'] ?? null,
                'current_chapter_name' => $validated['current_chapter_name'] ?? null,
            ]
        );

        $progress->updateProgress(
            $validated['current_position_seconds'],
            $validated['total_duration_seconds'] ?? null
        );

        $progress->save();

        return response()->json([
            'success' => true,
            'message' => 'Progress updated successfully',
            'data' => [
                'book_id' => $progress->book_id,
                'device_id' => $progress->device_id,
                'current_position_seconds' => $progress->current_position_seconds,
                'total_duration_seconds' => $progress->total_duration_seconds,
                'progress_percentage' => $progress->progress_percentage,
                'current_chapter' => $progress->current_chapter,
                'current_chapter_name' => $progress->current_chapter_name,
                'last_listened_at' => $progress->last_listened_at?->toISOString(),
                'completed' => $progress->completed,
                'completed_at' => $progress->completed_at?->toISOString(),
                'formatted_progress' => $progress->formatted_progress,
                'formatted_duration' => $progress->formatted_duration,
            ]
        ]);
    }

    /**
     * Get all progress for a device (recently listened books)
     */
    public function getDeviceProgress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
            'limit' => 'nullable|integer|min:1|max:100',
            'completed' => 'nullable|boolean',
        ]);

        $query = BookProgress::with('book')
            ->where('device_id', $validated['device_id'])
            ->orderBy('last_listened_at', 'desc');

        if (isset($validated['completed'])) {
            $query->where('completed', $validated['completed']);
        }

        $limit = $validated['limit'] ?? 20;
        $progressList = $query->limit($limit)->get();

        $data = $progressList->map(function ($progress) {
            return [
                'book_id' => $progress->book_id,
                'book' => [
                    'id' => $progress->book->id,
                    'title' => $progress->book->title,
                    'cover_image' => $progress->book->cover_image,
                ],
                'current_position_seconds' => $progress->current_position_seconds,
                'total_duration_seconds' => $progress->total_duration_seconds,
                'progress_percentage' => $progress->progress_percentage,
                'current_chapter' => $progress->current_chapter,
                'current_chapter_name' => $progress->current_chapter_name,
                'last_listened_at' => $progress->last_listened_at?->toISOString(),
                'completed' => $progress->completed,
                'completed_at' => $progress->completed_at?->toISOString(),
                'formatted_progress' => $progress->formatted_progress,
                'formatted_duration' => $progress->formatted_duration,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'count' => $data->count(),
        ]);
    }

    /**
     * Mark a book as completed
     */
    public function markCompleted(Request $request, int $bookId): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
        ]);

        $book = Book::find($bookId);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found'
            ], 404);
        }

        $progress = BookProgress::where('book_id', $bookId)
            ->where('device_id', $validated['device_id'])
            ->first();

        if (!$progress) {
            return response()->json([
                'error' => 'Progress not found',
                'message' => 'No progress record found for this book and device'
            ], 404);
        }

        $progress->completed = true;
        $progress->completed_at = now();
        $progress->progress_percentage = 100.00;
        $progress->save();

        return response()->json([
            'success' => true,
            'message' => 'Book marked as completed',
            'data' => [
                'book_id' => $progress->book_id,
                'device_id' => $progress->device_id,
                'completed' => $progress->completed,
                'completed_at' => $progress->completed_at?->toISOString(),
                'progress_percentage' => $progress->progress_percentage,
            ]
        ]);
    }

    /**
     * Reset progress for a book
     */
    public function resetProgress(Request $request, int $bookId): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
        ]);

        $book = Book::find($bookId);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found'
            ], 404);
        }

        $progress = BookProgress::where('book_id', $bookId)
            ->where('device_id', $validated['device_id'])
            ->first();

        if (!$progress) {
            return response()->json([
                'success' => true,
                'message' => 'No progress to reset'
            ]);
        }

        $progress->delete();

        return response()->json([
            'success' => true,
            'message' => 'Progress reset successfully'
        ]);
    }
}