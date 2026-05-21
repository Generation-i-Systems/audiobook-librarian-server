<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class BookTagController extends Controller
{
    public function show(Book $book): JsonResponse
    {
        if (!Schema::hasTable('book_tags')) {
            return response()->json([
                'bookId' => $book->id,
                'tags' => [],
            ]);
        }

        $userId = Auth::id();

        $tags = BookTag::query()
            ->where('user_id', $userId)
            ->where('book_id', $book->id)
            ->value('tags') ?? [];

        return response()->json([
            'bookId' => $book->id,
            'tags' => array_values($tags),
        ]);
    }

    public function update(Request $request, Book $book): JsonResponse
    {
        if (!Schema::hasTable('book_tags')) {
            return response()->json([
                'message' => 'Book tags are not available until the server migration has run.',
            ], 503);
        }

        $validated = $request->validate([
            'tags' => ['required', 'array'],
            'tags.*' => ['nullable', 'string', 'max:64'],
        ]);

        $userId = Auth::id();
        $tags = $this->normalizeTags($validated['tags']);

        BookTag::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'book_id' => $book->id,
            ],
            [
                'tags' => $tags,
            ]
        );

        return response()->json([
            'bookId' => $book->id,
            'tags' => $tags,
        ]);
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            $cleanTag = trim((string) $tag);

            if ($cleanTag === '') {
                continue;
            }

            $key = mb_strtolower($cleanTag);

            if (!array_key_exists($key, $normalized)) {
                $normalized[$key] = $cleanTag;
            }
        }

        return array_values($normalized);
    }
}
