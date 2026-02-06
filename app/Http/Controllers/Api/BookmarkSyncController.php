<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bookmark;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BookmarkSyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = Bookmark::where('user_id', $user->id);

        if ($request->boolean('include_deleted', true)) {
            $query->withTrashed();
        }

        if ($request->has('since')) {
            $query->where(function ($q) use ($request) {
                $q->where('created_at', '>', $request->input('since'))
                    ->orWhere('updated_at', '>', $request->input('since'))
                    ->orWhere('deleted_at', '>', $request->input('since'));
            });
        }

        if ($request->has('book_id')) {
            $query->where('book_id', $request->input('book_id'));
        }

        $bookmarks = $query->orderBy('created_at', 'desc')->get();

        $formattedBookmarks = $bookmarks->map(function ($bookmark) {
            return [
                'string_id' => $bookmark->string_id,
                'book_id' => $bookmark->book_id,
                'device_id' => $bookmark->device_id,
                'device_name' => $bookmark->device_name,
                'position_ms' => $bookmark->position_ms,
                'title' => $bookmark->title,
                'note' => $bookmark->notes,
                'is_auto' => $bookmark->is_auto ?? false,
                'chapter_number' => $bookmark->chapter_number,
                'chapter_title' => $bookmark->chapter_title,
                'created_at' => $bookmark->created_at?->toIso8601String(),
                'updated_at' => $bookmark->updated_at?->toIso8601String(),
                'deleted_at' => $bookmark->deleted_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'server_timestamp' => now()->toIso8601String(),
            'bookmarks' => $formattedBookmarks,
        ]);
    }

    public function show(Request $request, int $bookId): JsonResponse
    {
        $user = auth()->user();

        $query = Bookmark::where('user_id', $user->id)
            ->where('book_id', $bookId);

        if ($request->boolean('include_deleted', true)) {
            $query->withTrashed();
        }

        $bookmarks = $query->orderBy('created_at', 'desc')->get();

        $formattedBookmarks = $bookmarks->map(function ($bookmark) {
            return [
                'string_id' => $bookmark->string_id,
                'book_id' => $bookmark->book_id,
                'device_id' => $bookmark->device_id,
                'device_name' => $bookmark->device_name,
                'position_ms' => $bookmark->position_ms,
                'title' => $bookmark->title,
                'note' => $bookmark->notes,
                'is_auto' => $bookmark->is_auto ?? false,
                'chapter_number' => $bookmark->chapter_number,
                'chapter_title' => $bookmark->chapter_title,
                'created_at' => $bookmark->created_at?->toIso8601String(),
                'updated_at' => $bookmark->updated_at?->toIso8601String(),
                'deleted_at' => $bookmark->deleted_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'server_timestamp' => now()->toIso8601String(),
            'bookmarks' => $formattedBookmarks,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        $deviceId = $request->header('X-Device-ID');
        $deviceName = $request->header('X-Device-Name');

        if (!$deviceId || !$deviceName) {
            return response()->json([
                'error' => 'X-Device-ID and X-Device-Name headers are required',
            ], 400);
        }

        $validated = $request->validate([
            'client_timestamp' => 'required|date',
            'bookmarks' => 'required|array|min:1',
            'bookmarks.*.string_id' => 'required|uuid',
            'bookmarks.*.book_id' => 'required|integer',
            'bookmarks.*.position_ms' => 'required|integer|min:0',
            'bookmarks.*.title' => 'nullable|string|max:255',
            'bookmarks.*.note' => 'nullable|string',
            'bookmarks.*.is_auto' => 'nullable|boolean',
            'bookmarks.*.chapter_number' => 'nullable|integer',
            'bookmarks.*.chapter_title' => 'nullable|string|max:255',
            'bookmarks.*.created_at' => 'required|date',
        ]);

        $accepted = 0;
        $duplicatesSkipped = 0;

        foreach ($validated['bookmarks'] as $bm) {
            $existing = Bookmark::withTrashed()
                ->where('user_id', $user->id)
                ->where('string_id', $bm['string_id'])
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                $existing->update([
                    'book_id' => $bm['book_id'],
                    'device_id' => $deviceId,
                    'device_name' => $deviceName,
                    'position_ms' => $bm['position_ms'],
                    'title' => $bm['title'] ?? null,
                    'notes' => $bm['note'] ?? null,
                    'is_auto' => $bm['is_auto'] ?? false,
                    'chapter_number' => $bm['chapter_number'] ?? null,
                    'chapter_title' => $bm['chapter_title'] ?? null,
                ]);

                $duplicatesSkipped++;
            } else {
                Bookmark::create([
                    'user_id' => $user->id,
                    'book_id' => $bm['book_id'],
                    'string_id' => $bm['string_id'],
                    'device_id' => $deviceId,
                    'device_name' => $deviceName,
                    'position_ms' => $bm['position_ms'],
                    'position' => (int) ($bm['position_ms'] / 1000),
                    'title' => $bm['title'] ?? null,
                    'notes' => $bm['note'] ?? null,
                    'is_auto' => $bm['is_auto'] ?? false,
                    'chapter_number' => $bm['chapter_number'] ?? null,
                    'chapter_title' => $bm['chapter_title'] ?? null,
                    'chapter' => $bm['chapter_title'] ?? null,
                ]);

                $accepted++;
            }
        }

        return response()->json([
            'server_timestamp' => now()->toIso8601String(),
            'accepted' => $accepted,
            'duplicates_skipped' => $duplicatesSkipped,
        ]);
    }

    public function destroy(Request $request, string $stringId): JsonResponse
    {
        $user = auth()->user();

        $bookmark = Bookmark::where('user_id', $user->id)
            ->where('string_id', $stringId)
            ->first();

        if (!$bookmark) {
            return response()->json([
                'error' => 'Bookmark not found',
            ], 404);
        }

        $bookmark->delete();

        return response()->json([
            'string_id' => $stringId,
            'deleted_at' => $bookmark->deleted_at?->toIso8601String(),
        ]);
    }
}
