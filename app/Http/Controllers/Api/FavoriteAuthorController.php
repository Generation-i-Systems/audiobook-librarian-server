<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FavoriteAuthor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FavoriteAuthorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $favorites = FavoriteAuthor::where('user_id', $user->id)
            ->orderBy('author_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $favorites,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'author_name' => 'required|string|max:255',
            'notify_email' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $existing = FavoriteAuthor::where('user_id', $user->id)
            ->where('author_name', $request->author_name)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Author already favorited',
                'data' => $existing,
            ], 409);
        }

        $favorite = FavoriteAuthor::create([
            'user_id' => $user->id,
            'author_name' => $request->author_name,
            'notify_email' => $request->input('notify_email', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Author added to favorites',
            'data' => $favorite,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $favorite = FavoriteAuthor::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$favorite) {
            return response()->json([
                'success' => false,
                'message' => 'Favorite not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $favorite,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notify_email' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $favorite = FavoriteAuthor::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$favorite) {
            return response()->json([
                'success' => false,
                'message' => 'Favorite not found',
            ], 404);
        }

        $favorite->update([
            'notify_email' => $request->notify_email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Favorite updated',
            'data' => $favorite,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $favorite = FavoriteAuthor::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$favorite) {
            return response()->json([
                'success' => false,
                'message' => 'Favorite not found',
            ], 404);
        }

        $favorite->delete();

        return response()->json([
            'success' => true,
            'message' => 'Favorite removed',
        ]);
    }

    public function getNewBooks(Request $request): JsonResponse
    {
        $user = $request->user();

        $favoriteAuthors = FavoriteAuthor::where('user_id', $user->id)
            ->pluck('author_name')
            ->map(fn ($name) => strtolower(trim($name)))
            ->toArray();

        if (empty($favoriteAuthors)) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $newBooks = \App\Models\DiscoveredBook::notNotified()
            ->where('discovered_at', '>=', now()->subDays(7))
            ->get()
            ->filter(function ($book) use ($favoriteAuthors) {
                if (empty($book->author)) {
                    return false;
                }

                $bookAuthor = strtolower(trim($book->author));

                foreach ($favoriteAuthors as $favoriteAuthor) {
                    if (str_contains($bookAuthor, $favoriteAuthor) || str_contains($favoriteAuthor, $bookAuthor)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $newBooks,
        ]);
    }
}
