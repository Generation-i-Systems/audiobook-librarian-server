<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\UserBookStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlaylistController extends Controller
{
    /** GET /playlists — list all playlists for the user */
    public function index(): JsonResponse
    {
        $playlists = Playlist::where('user_id', Auth::id())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->withCount('items')
            ->get()
            ->map(fn ($p) => $this->formatPlaylist($p));

        return response()->json(['playlists' => $playlists]);
    }

    /** POST /playlists — create a new playlist */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'sort_order'  => 'nullable|integer',
        ]);

        $playlist = Playlist::create([
            'user_id'     => Auth::id(),
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order'  => $validated['sort_order'] ?? 0,
        ]);

        return response()->json(['playlist' => $this->formatPlaylist($playlist)], 201);
    }

    /** GET /playlists/{playlist} — get playlist with its book items */
    public function show(Playlist $playlist): JsonResponse
    {
        $this->authorizeOwner($playlist);

        $playlist->load(['items.book.authors', 'items.book.series']);

        return response()->json([
            'playlist' => $this->formatPlaylist($playlist),
            'items'    => $playlist->items->map(fn ($item) => $this->formatItem($item)),
        ]);
    }

    /** PUT /playlists/{playlist} — update name/description/sort_order */
    public function update(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorizeOwner($playlist);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:500',
            'sort_order'  => 'nullable|integer',
        ]);

        $playlist->update($validated);

        return response()->json(['playlist' => $this->formatPlaylist($playlist)]);
    }

    /** DELETE /playlists/{playlist} — delete playlist; items move back to default queue */
    public function destroy(Playlist $playlist): JsonResponse
    {
        $this->authorizeOwner($playlist);

        UserBookStatus::where('user_id', Auth::id())
            ->where('playlist_id', $playlist->id)
            ->update(['playlist_id' => null]);

        $playlist->delete();

        return response()->json(['message' => 'Playlist deleted']);
    }

    /** POST /playlists/{playlist}/books — add a book to a playlist */
    public function addBook(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorizeOwner($playlist);

        $validated = $request->validate([
            'book_id' => 'required|integer|exists:books,id',
        ]);

        $maxOrder = UserBookStatus::where('user_id', Auth::id())
            ->where('playlist_id', $playlist->id)
            ->max('order') ?? 0;

        $status = UserBookStatus::firstOrCreate(
            ['user_id' => Auth::id(), 'book_id' => $validated['book_id']],
            ['status' => 'queue', 'order' => $maxOrder + 1, 'playlist_id' => $playlist->id]
        );

        if ($status->wasRecentlyCreated === false) {
            $status->update(['playlist_id' => $playlist->id, 'status' => 'queue', 'order' => $maxOrder + 1]);
        }

        return response()->json(['message' => 'Book added to playlist'], 201);
    }

    /** DELETE /playlists/{playlist}/books/{bookId} — remove a book from a playlist (moves to default) */
    public function removeBook(Playlist $playlist, int $bookId): JsonResponse
    {
        $this->authorizeOwner($playlist);

        UserBookStatus::where('user_id', Auth::id())
            ->where('book_id', $bookId)
            ->where('playlist_id', $playlist->id)
            ->update(['playlist_id' => null]);

        return response()->json(['message' => 'Book removed from playlist']);
    }

    /** POST /playlists/{playlist}/reorder — reorder books in a playlist */
    public function reorder(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorizeOwner($playlist);

        $validated = $request->validate([
            'items'           => 'required|array',
            'items.*.book_id' => 'required|integer',
            'items.*.order'   => 'required|integer',
        ]);

        foreach ($validated['items'] as $item) {
            UserBookStatus::where('user_id', Auth::id())
                ->where('book_id', $item['book_id'])
                ->where('playlist_id', $playlist->id)
                ->update(['order' => $item['order']]);
        }

        return response()->json(['message' => 'Reordered']);
    }

    private function authorizeOwner(Playlist $playlist): void
    {
        abort_if($playlist->user_id !== Auth::id(), 403, 'Forbidden');
    }

    private function formatPlaylist(Playlist $playlist): array
    {
        return [
            'id'          => $playlist->id,
            'name'        => $playlist->name,
            'description' => $playlist->description,
            'sort_order'  => $playlist->sort_order,
            'items_count' => $playlist->items_count ?? $playlist->items()->count(),
            'created_at'  => $playlist->created_at?->toIso8601String(),
            'updated_at'  => $playlist->updated_at?->toIso8601String(),
        ];
    }

    private function formatItem(UserBookStatus $item): array
    {
        $book = $item->book;
        return [
            'book_id'   => $item->book_id,
            'order'     => $item->order,
            'status'    => $item->status,
            'added_at'  => $item->created_at?->toIso8601String(),
            'title'     => $book !== null ? $book->title : $item->title,
            'author'    => $book !== null ? $book->authors->pluck('name')->join(', ') : $item->author,
            'cover_url' => null, // cover served separately via book details endpoint
        ];
    }
}
