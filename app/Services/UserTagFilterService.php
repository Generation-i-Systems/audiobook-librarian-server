<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserTagFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for reading/writing per-user require/ban tag filters, and for
 * applying them to a book query. A filter row set by the user themselves can be changed
 * or removed by that same user; a row an admin locked (locked_by_admin) can only be
 * changed or removed by an admin.
 */
class UserTagFilterService
{
    public function setFilter(User $target, string $tag, string $mode, bool $lockedByAdmin, bool $actingAsAdmin): UserTagFilter
    {
        $tag = trim($tag);
        $existing = UserTagFilter::where('user_id', $target->id)->where('tag', $tag)->first();

        if ($existing && $existing->locked_by_admin && !$actingAsAdmin) {
            abort(403, 'This tag filter was set by an admin and cannot be changed.');
        }

        return UserTagFilter::updateOrCreate(
            ['user_id' => $target->id, 'tag' => $tag],
            ['mode' => $mode, 'locked_by_admin' => $lockedByAdmin]
        );
    }

    public function removeFilter(User $target, int $filterId, bool $actingAsAdmin): void
    {
        $filter = UserTagFilter::where('user_id', $target->id)->where('id', $filterId)->first();

        if (!$filter) {
            abort(404, 'Tag filter not found.');
        }

        if ($filter->locked_by_admin && !$actingAsAdmin) {
            abort(403, 'This tag filter was set by an admin and cannot be removed.');
        }

        $filter->delete();
    }

    /**
     * Restricts a Book query builder to books satisfying every one of the user's active
     * require/ban filters. Checks tags visible to the user: system-scope (public),
     * their groups' scope, and their own private scope — matching BookTagService's
     * visibility rules, since a "require the mature tag" rule set by an admin is
     * typically a system-scope tag, not something in the user's own private tag list.
     */
    public function applyToBookQuery(Builder $query, int $userId): void
    {
        if (!Schema::hasTable('user_tag_filters') || !Schema::hasTable('book_tags')) {
            return;
        }

        $filters = UserTagFilter::where('user_id', $userId)->get();
        if ($filters->isEmpty()) {
            return;
        }

        $groupIds = User::find($userId)?->groups()->pluck('groups.id')->all() ?? [];

        foreach ($filters as $filter) {
            $scopeMatcher = function ($q) use ($userId, $groupIds, $filter): void {
                $q->where(function ($qq) use ($userId, $groupIds): void {
                    $qq->where('owner_key', 'system')->orWhere('owner_key', 'user:' . $userId);
                    foreach ($groupIds as $groupId) {
                        $qq->orWhere('owner_key', 'group:' . $groupId);
                    }
                })->whereJsonContains('tags', $filter->tag);
            };

            if ($filter->mode === UserTagFilter::MODE_REQUIRE) {
                $query->whereHas('userTags', $scopeMatcher);
            } else {
                $query->whereDoesntHave('userTags', $scopeMatcher);
            }
        }
    }

    /**
     * Parse tag specifications from a string or array into required and banned tag lists.
     * Supports:
     * - "fantasy,-sci-fi"
     * - ["fantasy", "-sci-fi"]
     * - "tag1,tag2,-tag3"
     * - [null, ["fantasy", "-sci-fi"]] (the shape produced by
     *   array_filter([$request->input('tag'), $request->input('tags')]) when the client
     *   sends repeated ?tags[]= parameters)
     *
     * @param mixed $rawTags
     * @return array{required: string[], banned: string[]}
     */
    public static function parseTagSpecs(mixed $rawTags): array
    {
        $required = [];
        $banned = [];

        if ($rawTags === null || $rawTags === '' || $rawTags === []) {
            return ['required' => [], 'banned' => []];
        }

        $items = [];
        $collect = function (mixed $value) use (&$collect, &$items): void {
            if (is_array($value)) {
                foreach ($value as $nested) {
                    $collect($nested);
                }

                return;
            }
            if (!is_string($value)) {
                return;
            }
            foreach (explode(',', $value) as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $items[] = $trimmed;
                }
            }
        };
        $collect($rawTags);

        foreach ($items as $item) {
            if (str_starts_with($item, '-')) {
                $tag = ltrim($item, '-');
                if ($tag !== '') {
                    $banned[] = $tag;
                }
            } else {
                $required[] = $item;
            }
        }

        return [
            'required' => array_values(array_unique($required)),
            'banned' => array_values(array_unique($banned)),
        ];
    }

    /**
     * Applies request-specified required and banned tag filters to a Book query builder.
     *
     * @param Builder $query
     * @param mixed $tagSpec
     * @param int|null $userId
     */
    public function applyRequestTagFilters(Builder $query, mixed $tagSpec, ?int $userId = null): void
    {
        if (!Schema::hasTable('book_tags')) {
            return;
        }

        $parsed = self::parseTagSpecs($tagSpec);
        $requiredTags = $parsed['required'];
        $bannedTags = $parsed['banned'];

        if (empty($requiredTags) && empty($bannedTags)) {
            return;
        }

        $groupIds = [];
        if ($userId) {
            $groupIds = User::find($userId)?->groups()->pluck('groups.id')->all() ?? [];
        }

        $scopeMatcher = function ($q, string $tag) use ($userId, $groupIds): void {
            $q->where(function ($qq) use ($userId, $groupIds): void {
                $qq->where('owner_key', 'system');
                if ($userId) {
                    $qq->orWhere('owner_key', 'user:' . $userId);
                    foreach ($groupIds as $groupId) {
                        $qq->orWhere('owner_key', 'group:' . $groupId);
                    }
                } else {
                    $qq->orWhere('owner_key', 'LIKE', 'user:%')
                       ->orWhere('owner_key', 'LIKE', 'group:%');
                }
            })->whereJsonContains('tags', $tag);
        };

        foreach ($requiredTags as $reqTag) {
            $query->whereHas('userTags', fn ($q) => $scopeMatcher($q, $reqTag));
        }

        foreach ($bannedTags as $banTag) {
            $query->whereDoesntHave('userTags', fn ($q) => $scopeMatcher($q, $banTag));
        }
    }
}
