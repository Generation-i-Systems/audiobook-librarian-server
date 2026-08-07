<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookTag;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookTagController extends Controller
{
    /** GET /books/{book}/tags — tags visible to the caller across all scopes. */
    public function show(Book $book): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $groupIds = $user->groups()->pluck('groups.id');

        $ownerKeys = array_merge(
            ['system'],
            $groupIds->map(fn (int $id): string => "group:{$id}")->all(),
            ["user:{$user->id}"],
        );

        $rows = BookTag::query()
            ->where('book_id', $book->id)
            ->whereIn('owner_key', $ownerKeys)
            ->get();

        $systemTags = $rows->firstWhere('scope', 'system')->tags ?? [];
        $userTags = $rows->firstWhere('owner_key', "user:{$user->id}")->tags ?? [];

        $groupRows = $rows->where('scope', 'group');
        $groupNames = Group::query()->whereIn('id', $groupRows->pluck('group_id'))->pluck('name', 'id');
        $groups = $groupRows->map(fn (BookTag $row): array => [
            'groupId' => $row->group_id,
            'groupName' => $groupNames->get($row->group_id),
            'tags' => array_values($row->tags),
        ])->values();

        return response()->json([
            'bookId' => $book->id,
            'system' => array_values($systemTags),
            'groups' => $groups,
            'user' => array_values($userTags),
        ]);
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
        $scope = $validated['scope'];
        $groupId = $validated['group_id'] ?? null;

        if ($scope === 'system') {
            abort_unless($user->isAdmin(), 403, 'Only admins can set system tags.');
        } elseif ($scope === 'group') {
            abort_unless(
                $user->groups()->where('groups.id', $groupId)->exists(),
                403,
                'You are not a member of this group.'
            );
        }

        $tags = $this->normalizeTags($validated['tags']);
        $ownerKey = BookTag::ownerKeyFor($scope, $groupId, $user->id);

        BookTag::query()->updateOrCreate(
            ['book_id' => $book->id, 'owner_key' => $ownerKey],
            [
                'user_id' => $user->id,
                'scope' => $scope,
                'group_id' => $scope === 'group' ? $groupId : null,
                'tags' => $tags,
            ]
        );

        return response()->json([
            'bookId' => $book->id,
            'scope' => $scope,
            'groupId' => $groupId,
            'tags' => $tags,
        ]);
    }

    /**
     * GET /tags/popular — most-used system tags, for autocomplete suggestions.
     * Only system-scope tags are aggregated: group/user tag names must never
     * leak into a suggestion list visible to everyone.
     */
    public function popular(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->input('limit', 20)));

        /** @var array<string, array{name: string, count: int}> $counts */
        $counts = [];
        BookTag::query()->where('scope', 'system')->pluck('tags')->each(function (array $tags) use (&$counts): void {
            foreach ($tags as $tag) {
                $key = mb_strtolower($tag);
                $counts[$key] ??= ['name' => $tag, 'count' => 0];
                $counts[$key]['count']++;
            }
        });

        $popular = collect($counts)
            ->sortByDesc('count')
            ->take($limit)
            ->map(fn (array $entry): string => $entry['name'])
            ->values();

        return response()->json(['tags' => $popular]);
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
