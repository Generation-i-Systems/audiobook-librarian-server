<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookProgress;
use App\Models\Book;
use App\Models\ListeningEvent;
use App\Models\ListeningStatistic;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ProgressController extends Controller
{
    /**
     * Get progress for a specific book (OpenAPI spec version)
     */
    public function getBookProgress(Request $request, int $bookId): JsonResponse
    {
        /** @var Book|null $book */
        $book = Book::find($bookId);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found'
            ], 404);
        }

        $deviceId = $request->header('X-Device-ID', 'unknown');

        $query = BookProgress::where('book_id', $bookId);
        if (Auth::id()) {
            $query->where('user_id', Auth::id());
        } else {
            $query->where('device_id', $deviceId);
        }

        /** @var BookProgress|null $progress */
        $progress = $query->orderBy('last_listened_at', 'desc')->first();

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
            'current_chapter' => 'nullable|integer|min:1',
            'current_chapter_name' => 'nullable|string|max:255',
        ]);

        /** @var Book|null $book */
        $book = Book::find($bookId);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found'
            ], 404);
        }

        $deviceId = $request->header('X-Device-ID', 'unknown');

        if (Auth::id()) {
            $attributes = [
                'book_id' => $bookId,
                'user_id' => Auth::id(),
            ];
            $values = ['device_id' => $deviceId];
        } else {
            $attributes = [
                'book_id' => $bookId,
                'device_id' => $deviceId,
            ];
            $values = [];
        }

        /** @var BookProgress $progress */
        $progress = BookProgress::updateOrCreate($attributes, $values);

        $progress->updateProgress(
            (int) ($validated['position_ms'] / 1000),
            null
        );

        if (isset($validated['current_chapter'])) {
            $progress->current_chapter = $validated['current_chapter'];
        }
        if (isset($validated['current_chapter_name'])) {
            $progress->current_chapter_name = $validated['current_chapter_name'];
        }

        $progress->progress_percentage = $validated['progress_percentage'];
        $progress->completed = $validated['progress_percentage'] >= 100;
        if ($progress->completed && !$progress->completed_at) {
            $progress->completed_at = now();
        }

        $progress->save();

        // Record event in new system
        $this->recordListeningEvent($progress, $progress->completed ? 'BOOK_FINISH' : 'SESSION_END');
        if ($progress->completed) {
            $this->recordCompletedStatistic($progress);
            $this->evaluateBadges($progress);
        }

        return response()->json([
            'book_id' => $progress->book_id,
            'position_ms' => $progress->current_position_seconds * 1000,
            'progress_percentage' => (int) round($progress->progress_percentage),
            'last_updated' => $progress->updated_at->toISOString(),
            'is_finished' => $progress->completed,
            'current_chapter' => $progress->current_chapter,
            'current_chapter_name' => $progress->current_chapter_name,
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

        $query = BookProgress::with('book')
            ->orderBy('last_listened_at', 'desc');

        if (Auth::id()) {
            $query->where('user_id', Auth::id());
        } else {
            $deviceId = $request->header('X-Device-ID', 'unknown');
            $query->where('device_id', $deviceId);
        }

        if ($validated['active_only'] ?? false) {
            $query->where('completed', false);
        }

        if (Auth::id()) {
            $progressList = $query->get()->unique('book_id')->values();
        } else {
            $progressList = $query->get();
        }

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

        $query = BookProgress::where('book_id', $bookId);
        if (Auth::id()) {
            $query->where('user_id', Auth::id());
        } else {
            $query->where('device_id', $validated['device_id']);
        }
        $progress = $query->orderBy('last_listened_at', 'desc')->first();

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

        $newUserId = Auth::id() ?? $validated['user_id'] ?? null;

        if ($newUserId) {
            $attributes = [
                'book_id' => $bookId,
                'user_id' => $newUserId,
            ];
            $values = [
                'device_id' => $validated['device_id'],
                'current_chapter' => $validated['current_chapter'] ?? null,
                'current_chapter_name' => $validated['current_chapter_name'] ?? null,
            ];
        } else {
            $attributes = [
                'book_id' => $bookId,
                'device_id' => $validated['device_id'],
            ];
            $values = [
                'current_chapter' => $validated['current_chapter'] ?? null,
                'current_chapter_name' => $validated['current_chapter_name'] ?? null,
            ];
        }

        $progress = BookProgress::updateOrCreate($attributes, $values);

        $progress->updateProgress(
            $validated['current_position_seconds'],
            $validated['total_duration_seconds'] ?? null
        );

        $progress->save();

        // Record event in new system
        $this->recordListeningEvent($progress, 'SESSION_END');

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
            ->orderBy('last_listened_at', 'desc');

        if (Auth::id()) {
            $query->where('user_id', Auth::id());
        } else {
            $query->where('device_id', $validated['device_id']);
        }

        if (isset($validated['completed'])) {
            $query->where('completed', $validated['completed']);
        }

        $limit = $validated['limit'] ?? 20;

        if (Auth::id()) {
            $progressList = $query->get()->unique('book_id')->take($limit)->values();
        } else {
            $progressList = $query->limit($limit)->get();
        }

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

        $query = BookProgress::where('book_id', $bookId);
        if (Auth::id()) {
            $query->where('user_id', Auth::id());
        } else {
            $query->where('device_id', $validated['device_id']);
        }
        $progress = $query->orderBy('last_listened_at', 'desc')->first();

        if (!$progress) {
            return response()->json([
                'error' => 'Progress not found',
                'message' => 'No progress record found for this book and device'
            ], 404);
        }

        $progress->completed = true;
        $progress->completed_at = now();
        $progress->progress_percentage = 100;
        $progress->current_position_seconds = $progress->total_duration_seconds ?? $progress->current_position_seconds ?? 0;
        $progress->last_listened_at = now();
        $progress->save();

        // Record event in new system
        $this->recordListeningEvent($progress, 'BOOK_FINISH');
        $this->recordCompletedStatistic($progress);
        $this->evaluateBadges($progress);

        return response()->json([
            'success' => true,
            'message' => 'Book marked as completed',
            'data' => [
                'book_id' => $progress->book_id,
                'device_id' => $progress->device_id,
                'completed' => $progress->completed,
                'completed_at' => $progress->completed_at->toISOString(),
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

        $query = BookProgress::where('book_id', $bookId);
        if (Auth::id()) {
            $query->where('user_id', Auth::id());
        } else {
            $query->where('device_id', $validated['device_id']);
        }
        $progress = $query->orderBy('last_listened_at', 'desc')->first();

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

    /**
     * Mark a book as completed (Alias for client compatibility)
     */
    public function markCompletedByPath(Request $request, int $bookId): JsonResponse
    {
        // Inject device_id if missing, using authenticated user ID
        if (!$request->has('device_id')) {
            $request->merge(['device_id' => (string) (Auth::id() ?? 'unknown')]);
        }

        return $this->markCompleted($request, $bookId);
    }

    /**
     * Get device progress by path parameter (Alias for client compatibility)
     */
    public function getDeviceProgressByPath(Request $request, string $deviceId): JsonResponse
    {
        // Inject device_id from path into request
        $request->merge(['device_id' => $deviceId]);

        return $this->getDeviceProgress($request);
    }

    /**
     * Record a listening event for the new system
     */
    private function recordListeningEvent(BookProgress $progress, string $eventType): void
    {
        try {
            if (!$progress->user_id) {
                return;
            }

            ListeningEvent::create([
                'id' => (string) Str::uuid(),
                'user_id' => $progress->user_id,
                'book_id' => $progress->book_id,
                'event_type' => $eventType,
                'timestamp_ms' => now()->timestamp * 1000,
                'position_ms' => ($progress->current_position_seconds ?? 0) * 1000,
                'metadata' => [
                    'source' => 'legacy_api',
                    'progress_percentage' => $progress->progress_percentage
                ],
                'device_id' => $progress->device_id ?? 'unknown',
                'timezone' => 'UTC',
                'sync_status' => 'SYNCED',
                'created_at' => now()->timestamp * 1000,
                'synced_at' => now()->timestamp * 1000,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record listening event: ' . $e->getMessage());
        }
    }

    private function recordCompletedStatistic(BookProgress $progress): void
    {
        try {
            $userId = $progress->getAttribute('user_id');
            $deviceId = $progress->device_id ?? 'unknown';

            $existingCompletion = ListeningStatistic::query()
                ->where('book_id', $progress->book_id)
                ->where('session_type', 'completed')
                ->where(function ($query) use ($userId, $deviceId) {
                    if ($userId) {
                        $query->where('user_id', $userId);
                    } else {
                        $query->where('device_id', $deviceId);
                    }
                })
                ->exists();

            if ($existingCompletion) {
                return;
            }

            ListeningStatistic::createSession(
                $progress->book_id,
                $deviceId,
                1,
                $progress->current_position_seconds,
                $progress->current_position_seconds,
                'completed',
                [
                    'source' => 'progress_controller',
                    'progress_percentage' => $progress->progress_percentage,
                ],
                $userId ? (string) $userId : null
            );
        } catch (\Exception $e) {
            Log::error('Failed to record completed statistic: ' . $e->getMessage());
        }
    }

    private function evaluateBadges(BookProgress $progress): void
    {
        try {
            $userId = $progress->getAttribute('user_id');
            $deviceId = $progress->device_id ?? 'unknown';
            $badgeUserId = $userId ? (string) $userId : $deviceId;

            app(\App\Services\BadgeService::class)->evaluateUserBadges(
                $badgeUserId,
                $deviceId
            );
        } catch (\Exception $e) {
            Log::warning('Badge evaluation failed after progress completion', [
                'user_id' => $progress->user_id,
                'book_id' => $progress->book_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
