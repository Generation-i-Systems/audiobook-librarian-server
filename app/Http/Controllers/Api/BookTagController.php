<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\User;
use App\Services\BookTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookTagController extends Controller
{
    public function __construct(private readonly BookTagService $bookTagService)
    {
    }

    /** GET /books/{book}/tags — tags visible to the caller across all scopes. */
    public function show(Book $book): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json(array_merge(
            ['bookId' => $book->id],
            $this->bookTagService->visibleTagsForBook($user, $book),
        ));
    }

    /** PUT /books/{book}/tags — replace the tag list for one scope (system/group/user). */
    public function update(Request $request, Book $book): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', 'in:system,group,user'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id', 'required_if:scope,group'],
            'tags' => ['required', 'array'],
            'tags.*' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $result = $this->bookTagService->updateTags(
            $user,
            $book,
            $validated['scope'],
            $validated['group_id'] ?? null,
            $validated['tags'],
        );

        return response()->json(array_merge(['bookId' => $book->id], $result));
    }

    /**
     * GET /tags/popular — most-used system tags, for autocomplete suggestions.
     */
    public function popular(Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 20);

        return response()->json(['tags' => $this->bookTagService->popularTags($limit)]);
    }

    /**
     * GET /tags/all — every tag visible to the caller (system tags plus the caller's
     * own tags and tags from groups they belong to), deduplicated and sorted, each
     * with the number of distinct books carrying that tag.
     */
    public function all(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['tags' => $this->bookTagService->visibleTags($user)]);
    }
}
