<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserList;
use App\Models\UserListItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * User-created book lists (e.g. the client's default "Read Queue" plus arbitrary custom
 * lists). Distinct from Playlists: a list has no book ordering and is purely a named
 * collection of book memberships. `{list}` route params bind on `UserList.string_id` (the
 * client-generated uuid) rather than the internal auto-increment id - see
 * UserList::getRouteKeyName().
 */
class UserListController extends Controller
{
    /** GET /lists — all lists for the user */
    public function index(): JsonResponse
    {
        $lists = UserList::where('user_id', Auth::id())
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn ($list) => $this->formatList($list));

        return response()->json(['lists' => $lists]);
    }

    /** POST /lists — create a list, or update it if string_id already exists (idempotent retry) */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'string_id'  => 'required|uuid',
            'name'       => 'required|string|max:255',
            'is_default' => 'nullable|boolean',
            'created_at' => 'required|integer',
            'updated_at' => 'required|integer',
        ]);

        $list = UserList::where('user_id', Auth::id())->where('string_id', $validated['string_id'])->first();

        if ($list) {
            $list->update([
                'name'       => $validated['name'],
                'is_default' => $validated['is_default'] ?? false,
                'updated_at' => $validated['updated_at'],
            ]);
        } else {
            $list = UserList::create([
                'user_id'    => Auth::id(),
                'string_id'  => $validated['string_id'],
                'name'       => $validated['name'],
                'is_default' => $validated['is_default'] ?? false,
                'created_at' => $validated['created_at'],
                'updated_at' => $validated['updated_at'],
            ]);
        }

        return response()->json(['list' => $this->formatList($list)], 201);
    }

    /** PATCH /lists/{list} — rename a list */
    public function update(Request $request, UserList $list): JsonResponse
    {
        $this->authorizeOwner($list);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $list->update(['name' => $validated['name'], 'updated_at' => self::nowMs()]);

        return response()->json(['list' => $this->formatList($list)]);
    }

    /** DELETE /lists/{list} */
    public function destroy(UserList $list): JsonResponse
    {
        $this->authorizeOwner($list);
        $list->delete();

        return response()->json(['message' => 'List deleted']);
    }

    /** GET /lists/{list}/items */
    public function items(UserList $list): JsonResponse
    {
        $this->authorizeOwner($list);

        $items = $list->items()->orderByDesc('added_at')->get()->map(fn ($item) => $this->formatItem($item));

        return response()->json(['items' => $items]);
    }

    /** POST /lists/{list}/items — add a book, or refresh added_at if already present (idempotent retry) */
    public function addItem(Request $request, UserList $list): JsonResponse
    {
        $this->authorizeOwner($list);

        $validated = $request->validate([
            'book_id'   => 'required|integer|exists:books,id',
            'added_at'  => 'required|integer',
        ]);

        $item = UserListItem::where('user_list_id', $list->id)->where('book_id', $validated['book_id'])->first();

        if ($item) {
            $item->update(['added_at' => $validated['added_at']]);
        } else {
            $item = UserListItem::create([
                'user_list_id' => $list->id,
                'book_id'      => $validated['book_id'],
                'added_at'     => $validated['added_at'],
            ]);
        }

        return response()->json(['item' => $this->formatItem($item)], 201);
    }

    /** DELETE /lists/{list}/items/{bookId} */
    public function removeItem(UserList $list, int $bookId): JsonResponse
    {
        $this->authorizeOwner($list);

        UserListItem::where('user_list_id', $list->id)->where('book_id', $bookId)->delete();

        return response()->json(['message' => 'Item removed']);
    }

    private function authorizeOwner(UserList $list): void
    {
        abort_if($list->user_id !== Auth::id(), 403, 'Forbidden');
    }

    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function formatList(UserList $list): array
    {
        return [
            'id'         => $list->string_id,
            'name'       => $list->name,
            'is_default' => (bool) $list->is_default,
            'created_at' => $list->created_at,
            'updated_at' => $list->updated_at,
        ];
    }

    private function formatItem(UserListItem $item): array
    {
        return [
            'book_id'  => $item->book_id,
            'added_at' => $item->added_at,
        ];
    }
}
