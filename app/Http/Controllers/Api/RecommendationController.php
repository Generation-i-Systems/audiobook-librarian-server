<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\UserRecommendation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class RecommendationController extends Controller
{
    /**
     * Send a book recommendation to another user.
     *
     * @param Book $book The book to recommend.
     */
    public function send(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'recipient_id' => [
                'required',
                'integer',
                'exists:users,id',
                // Cannot recommend to self
                Rule::notIn([Auth::id()]),
            ],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        // Prevent duplicate recommendations to the same recipient for the same book (if unacknowledged)
        if (UserRecommendation::where('sender_id', $user->id)
            ->where('recipient_id', $data['recipient_id'])
            ->where('book_id', $book->id)
            ->whereNull('acknowledged_at')
            ->exists()) {
            return response()->json(['message' => 'You have already sent this user an unacknowledged recommendation for this book.'], 409);
        }

        UserRecommendation::create([
            'sender_id' => $user->id,
            'recipient_id' => $data['recipient_id'],
            'book_id' => $book->id,
            'message' => $data['message'] ?? null,
        ]);

        return response()->json([
            'message' => 'Recommendation sent successfully.',
        ], 201);
    }

    /**
     * Display a listing of unacknowledged recommendations for the current user (the inbox).
     */
    public function inbox(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $recommendations = UserRecommendation::with(['sender:id,name', 'book:id,title,cover_image'])
            ->where('recipient_id', $user->id)
            ->whereNull('acknowledged_at')
            ->latest()
            ->get();

        return response()->json($recommendations);
    }

    /**
     * Mark a specific recommendation as acknowledged.
     *
     * @param UserRecommendation $recommendation The recommendation model instance.
     */
    public function acknowledge(UserRecommendation $recommendation): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($recommendation->recipient_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if (is_null($recommendation->acknowledged_at)) {
            $recommendation->acknowledged_at = now();
            $recommendation->save();
        }

        return response()->json([
            'message' => 'Recommendation acknowledged.',
            'recommendation' => $recommendation->load(['sender:id,name', 'book:id,title,cover_image']),
        ]);
    }

    // Unused API resource methods removed for clean API structure
    public function index(): void
    {
    }

    public function store(Request $request): void
    {
    }

    public function show(string $id): void
    {
    }

    public function update(Request $request, string $id): void
    {
    }

    public function destroy(string $id): void
    {
    }
}
