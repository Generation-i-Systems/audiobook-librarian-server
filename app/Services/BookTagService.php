<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\EmbedBookJob;
use App\Models\Book;
use App\Models\BookTag;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

class BookTagService
{
    /**
     * @return array{system: array<int, string>, groups: array<int, array{groupId: int, groupName: ?string, tags: array<int, string>}>, user: array<int, string>}
     */
    public function visibleTagsForBook(User $user, Book $book): array
    {
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

        return $this->formatVisibleTags($rows, $user->id);
    }

    /**
     * @param Collection<int, BookTag> $rows
     * @return array{system: array<int, string>, groups: array<int, array{groupId: int, groupName: ?string, tags: array<int, string>}>, user: array<int, string>}
     */
    private function formatVisibleTags(Collection $rows, int $userId): array
    {
        $systemTags = $rows->firstWhere('scope', 'system')->tags ?? [];
        $userTags = $rows->firstWhere('owner_key', "user:{$userId}")->tags ?? [];

        $groupRows = $rows->where('scope', 'group');
        $groupNames = Group::query()->whereIn('id', $groupRows->pluck('group_id'))->pluck('name', 'id');
        $groups = $groupRows->map(fn (BookTag $row): array => [
            'groupId' => $row->group_id,
            'groupName' => $groupNames->get($row->group_id),
            'tags' => array_values($row->tags),
        ])->values()->all();

        return [
            'system' => array_values($systemTags),
            'groups' => $groups,
            'user' => array_values($userTags),
        ];
    }

    /**
     * @param array<int, string> $tags
     * @return array{scope: string, groupId: ?int, tags: array<int, string>}
     */
    public function updateTags(User $user, Book $book, string $scope, ?int $groupId, array $tags): array
    {
        if ($scope === 'system') {
            abort_unless($user->isAdmin(), 403, 'Only admins can set system tags.');
        } elseif ($scope === 'group') {
            abort_unless(
                $user->groups()->where('groups.id', $groupId)->exists(),
                403,
                'You are not a member of this group.'
            );
        }

        $normalizedTags = $this->normalizeTags($tags);
        $ownerKey = BookTag::ownerKeyFor($scope, $groupId, $user->id);

        BookTag::query()->updateOrCreate(
            ['book_id' => $book->id, 'owner_key' => $ownerKey],
            [
                'user_id' => $user->id,
                'scope' => $scope,
                'group_id' => $scope === 'group' ? $groupId : null,
                'tags' => $normalizedTags,
            ]
        );

        // Only system-scope tags feed the recommendation-engine embedding text
        // (EmbedBookJob::buildMetadataText) — group/user tags are private.
        if ($scope === 'system') {
            EmbedBookJob::dispatch($book->id);
        }

        return ['scope' => $scope, 'groupId' => $groupId, 'tags' => $normalizedTags];
    }

    /**
     * Only system-scope tags are aggregated: group/user tag names must never
     * leak into a suggestion list visible to everyone.
     *
     * @return Collection<int, string>
     */
    public function popularTags(int $limit = 20): Collection
    {
        $limit = min(50, max(1, $limit));

        /** @var array<string, array{name: string, count: int}> $counts */
        $counts = [];
        BookTag::query()->where('scope', 'system')->pluck('tags')->each(function (array $tags) use (&$counts): void {
            foreach ($tags as $tag) {
                $key = mb_strtolower($tag);
                $counts[$key] ??= ['name' => $tag, 'count' => 0];
                $counts[$key]['count']++;
            }
        });

        return collect($counts)
            ->sortByDesc('count')
            ->take($limit)
            ->map(fn (array $entry): string => $entry['name'])
            ->values();
    }

    /**
     * All system-scope tag names, deduplicated and sorted alphabetically.
     *
     * @return Collection<int, string>
     */
    public function allTags(): Collection
    {
        /** @var array<string, string> $seen */
        $seen = [];
        BookTag::query()->where('scope', 'system')->pluck('tags')->each(function (array $tags) use (&$seen): void {
            foreach ($tags as $tag) {
                $key = mb_strtolower($tag);
                $seen[$key] ??= $tag;
            }
        });

        return collect($seen)->sort()->values();
    }

    /**
     * All tag names visible to the caller — system tags plus the caller's own
     * tags and tags from any group they belong to — deduplicated and sorted,
     * each with the number of distinct books carrying that tag.
     *
     * Unlike allTags()/popularTags() (system-scope only, because they feed
     * shared suggestion lists), this is caller-scoped: only the caller's own
     * scopes are aggregated, so no other user's tags can leak through.
     *
     * @return Collection<int, array{name: string, count: int}>
     */
    public function visibleTags(User $user): Collection
    {
        $groupIds = $user->groups()->pluck('groups.id');

        $ownerKeys = array_merge(
            ['system'],
            $groupIds->map(fn (int $id): string => "group:{$id}")->all(),
            ["user:{$user->id}"],
        );

        /** @var array<string, string> $labels */
        $labels = [];
        /** @var array<string, array<int, true>> $bookIdsByTag */
        $bookIdsByTag = [];
        BookTag::query()
            ->whereIn('owner_key', $ownerKeys)
            ->get(['book_id', 'tags'])
            ->each(function (BookTag $bookTag) use (&$labels, &$bookIdsByTag): void {
                foreach ($bookTag->tags as $tag) {
                    $key = mb_strtolower($tag);
                    $labels[$key] ??= $tag;
                    $bookIdsByTag[$key][$bookTag->book_id] = true;
                }
            });

        $tags = [];
        foreach ($labels as $key => $label) {
            $tags[] = ['name' => $label, 'count' => count($bookIdsByTag[$key] ?? [])];
        }
        usort($tags, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return collect($tags);
    }

    /**
     * The caller's own tags plus tags for any group they belong to, grouped by tag
     * name with the books carrying each, for the "My Tags" user page.
     *
     * @return array{user: array<int, array{tag: string, books: Collection<int, Book>}>, groups: array<int, array{groupId: int, groupName: string, tags: array<int, array{tag: string, books: Collection<int, Book>}>}>}
     */
    public function myTagsOverview(User $user): array
    {
        $userRows = BookTag::query()
            ->where('owner_key', "user:{$user->id}")
            ->with('book')
            ->get();

        $groups = $user->groups()->get(['groups.id', 'groups.name']);
        $groupRows = BookTag::query()
            ->where('scope', 'group')
            ->whereIn('group_id', $groups->pluck('id'))
            ->with('book')
            ->get()
            ->groupBy('group_id');

        return [
            'user' => $this->groupRowsByTag($userRows)->all(),
            'groups' => $groups->map(fn (Group $group): array => [
                'groupId' => $group->id,
                'groupName' => $group->name,
                'tags' => $this->groupRowsByTag($groupRows->get($group->id, collect()))->all(),
            ])->all(),
        ];
    }

    /**
     * @param Collection<int, BookTag> $rows
     * @return Collection<int, array{tag: string, books: Collection<int, Book>}>
     */
    private function groupRowsByTag(Collection $rows): Collection
    {
        /** @var array<string, array{tag: string, books: Collection<int, Book>}> $byTag */
        $byTag = [];

        foreach ($rows as $row) {
            if (!$row->book) {
                continue;
            }

            foreach ($row->tags as $tag) {
                $key = mb_strtolower($tag);
                $byTag[$key] ??= ['tag' => $tag, 'books' => collect()];
                $byTag[$key]['books']->push($row->book);
            }
        }

        return collect($byTag)->sortBy('tag')->values();
    }

    /**
     * Distinct system-scope tag names with usage counts, for the admin Tags index page.
     *
     * @return Collection<int, array{name: string, count: int}>
     */
    public function systemTagsOverview(): Collection
    {
        /** @var array<string, array{name: string, count: int}> $counts */
        $counts = [];
        BookTag::query()->where('scope', 'system')->pluck('tags')->each(function (array $tags) use (&$counts): void {
            foreach ($tags as $tag) {
                $tag = (string) $tag;
                $key = mb_strtolower($tag);
                $counts[$key] ??= ['name' => $tag, 'count' => 0];
                $counts[$key]['count']++;
            }
        });

        return collect($counts)->sortBy('name')->values();
    }

    /**
     * @return Collection<int, Book>
     */
    public function booksForSystemTag(string $tag): Collection
    {
        $bookIds = BookTag::query()
            ->where('scope', 'system')
            ->whereJsonContains('tags', $tag)
            ->pluck('book_id');

        return Book::query()->whereIn('id', $bookIds)->get();
    }

    public function renameSystemTag(string $oldName, string $newName): int
    {
        $rows = BookTag::query()->where('scope', 'system')->whereJsonContains('tags', $oldName)->get();

        foreach ($rows as $row) {
            $row->tags = $this->normalizeTags(array_map(
                fn (string $tag): string => mb_strtolower($tag) === mb_strtolower($oldName) ? $newName : $tag,
                $row->tags,
            ));
            $row->save();
        }

        return $rows->count();
    }

    public function deleteSystemTag(string $tag): int
    {
        $rows = BookTag::query()->where('scope', 'system')->whereJsonContains('tags', $tag)->get();

        foreach ($rows as $row) {
            $row->tags = array_values(array_filter(
                $row->tags,
                fn (string $existingTag): bool => mb_strtolower($existingTag) !== mb_strtolower($tag),
            ));
            $row->save();
        }

        return $rows->count();
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    public function normalizeTags(array $tags): array
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
