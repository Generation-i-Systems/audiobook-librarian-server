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
        ]);

        /** @var User $user */
        $user = Auth::user();

        /** @var UserBookStatus|null $currentStatus */
        $currentStatus = $user->bookStatuses()->where('book_id', $book->id)->first();
        $previousStatus = $currentStatus?->status;

        $statusModel = UserBookStatus::firstOrNew(
            [
                'user_id' => $user->id,
                'book_id' => $book->id,
            ]
        );

        $statusModel->status = $data['status'];
        $statusModel->order = $data['order'] ?? 0;
        $statusModel->status_detail = $data['status_detail'] ?? null;
        $statusModel->save();

        // Dispatch event for Badge/Stats integration (Phase 3)
        if ($data['status'] !== $previousStatus) {
            BookStatusUpdated::dispatch($user, $book, $data['status'], $previousStatus);
        }

        return response()->json([
            'message' => "Book status updated to {$data['status']}.",
            'status' => $statusModel,
        ], 200);
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
