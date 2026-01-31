<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\BookStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\UserBookStatus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UserStatusController extends Controller
{
    /**
     * @var array<string> Valid book statuses
     */
    public const VALID_STATUSES = ['queue', 'wishlist', 'completed', 'in_progress', 'paused', 'dropped'];

    /**
     * Display a listing of book statuses for the current user.
     *
     * @param string $statusType The status type to filter by (e.g., 'queue', 'completed').
     */
    public function list(string $statusType): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!in_array($statusType, self::VALID_STATUSES)) {
            return response()->json(['message' => 'Invalid status type.'], 400);
        }

        $statuses = $user->bookStatuses()
            ->with('book')
            ->where('status', $statusType)
            ->orderBy('order')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json($statuses);
    }

    /**
     * Set or update a book's status for the authenticated user.
     *
     * @param Book $book The book to update.
     */
    public function set(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(self::VALID_STATUSES)],
            'order' => ['nullable', 'integer', 'min:1'],
            'status_detail' => ['nullable', 'array'],
            'target_date' => ['nullable', 'date'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        /** @var UserBookStatus|null $currentStatus */
        $currentStatus = UserBookStatus::where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->first();
        $previousStatus = $currentStatus?->status;

        $updateData = [
            'status' => $data['status'],
            'order' => $data['order'] ?? 0,
            'status_detail' => $data['status_detail'] ?? null,
            'target_date' => $data['target_date'] ?? null,
        ];

        // Automatic timestamp handling
        if ($data['status'] === 'in_progress' && $previousStatus !== 'in_progress') {
            $updateData['started_at'] = now();
        }

        if ($data['status'] === 'completed' && $previousStatus !== 'completed') {
            $updateData['finished_at'] = now();
            // Increment read count
            $updateData['read_count'] = ($currentStatus ? $currentStatus->read_count : 0) + 1;
        }

        if ($currentStatus) {
            UserBookStatus::where('user_id', $user->id)
                ->where('book_id', $book->id)
                ->update($updateData);
            $statusModel = UserBookStatus::where('user_id', $user->id)
                ->where('book_id', $book->id)
                ->first();
        } else {
            $updateData['user_id'] = $user->id;
            $updateData['book_id'] = $book->id;
            $statusModel = UserBookStatus::create($updateData);
        }

        // Dispatch event for Badge/Stats integration (Phase 3)
        if ($data['status'] !== $previousStatus) {
            BookStatusUpdated::dispatch($user, $book, $data['status'], $previousStatus);
        }

        return response()->json([
            'message' => "Book status updated to {$data['status']}.",
            'status' => $statusModel,
        ], 200);
    }

    public function setNonLibrary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'author' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(self::VALID_STATUSES)],
            'order' => ['nullable', 'integer', 'min:1'],
            'status_detail' => ['nullable', 'array'],
            'target_date' => ['nullable', 'date'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        /** @var UserBookStatus|null $currentStatus */
        $currentStatus = UserBookStatus::where('user_id', $user->id)
            ->where('title', $data['title'])
            ->where('author', $data['author'])
            ->whereNull('book_id')
            ->first();
        $previousStatus = $currentStatus?->status;

        $updateData = [
            'status' => $data['status'],
            'order' => $data['order'] ?? 0,
            'status_detail' => $data['status_detail'] ?? null,
            'target_date' => $data['target_date'] ?? null,
        ];

        if ($data['status'] === 'in_progress' && $previousStatus !== 'in_progress') {
            $updateData['started_at'] = now();
        }

        if ($data['status'] === 'completed' && $previousStatus !== 'completed') {
            $updateData['finished_at'] = now();
            $updateData['read_count'] = ($currentStatus ? $currentStatus->read_count : 0) + 1;
        }

        if ($currentStatus) {
            $currentStatus->update($updateData);
            $statusModel = $currentStatus;
        } else {
            $updateData['user_id'] = $user->id;
            $updateData['title'] = $data['title'];
            $updateData['author'] = $data['author'];
            $statusModel = UserBookStatus::create($updateData);
        }

        return response()->json([
            'message' => "Book status updated to {$data['status']}.",
            'status' => $statusModel,
        ], 200);
    }

    /**
     * Get the user's reading history (completed books ordered by finished_at).
     */
    public function history(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $history = $user->bookStatuses()
            ->with('book')
            ->where('status', 'completed')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->paginate($request->input('per_page', 20));

        return response()->json($history);
    }

    /**
     * Get the user's reading goals/queue with target dates.
     */
    public function goals(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $goals = $user->bookStatuses()
            ->with('book')
            ->whereIn('status', ['queue', 'in_progress'])
            ->whereNotNull('target_date')
            ->orderBy('target_date')
            ->get();

        return response()->json($goals);
    }

    /**
     * Reorder the queue for the authenticated user.
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'book_orders' => ['required', 'array'],
            'book_orders.*.book_id' => ['required', 'integer', 'exists:books,id'],
            'book_orders.*.order' => ['required', 'integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $bookOrders = $request->input('book_orders');

        foreach ($bookOrders as $item) {
            UserBookStatus::where('user_id', $user->id)
                ->where('book_id', $item['book_id'])
                ->where('status', 'queue')
                ->update(['order' => $item['order']]);
        }

        return response()->json(['message' => 'Queue reordered successfully.'], 200);
    }
}
