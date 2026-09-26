<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserTagFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for reading/writing tag filters, and for applying them to a
 * book query. Two scopes exist:
 *   - user   — a personal filter the user themselves added; only that user (or an
 *     admin) may change or remove it.
 *   - system — an account-wide filter set by an admin or a designated account manager
 *     (see User::canManageSystemFiltersFor()); ordinary account members cannot change
 *     or remove it. Stored once per account (owner_key "account:{rootId}"), so it
 *     applies to every member of that account regardless of which member's id the
 *     manager acted through.
 */
class UserTagFilterService
{
    public function ownerKeyForScope(string $scope, User $target): string
    {
        return $scope === UserTagFilter::SCOPE_SYSTEM
            ? 'account:' . $target->accountRootId()
            : 'user:' . $target->id;
    }

    public function setFilter(User $actor, User $target, string $tag, string $mode, string $scope): UserTagFilter
    {
        $tag = trim($tag);
        $this->authorize($actor, $target, $scope);

        $ownerKey = $this->ownerKeyForScope($scope, $target);
        $ownerUserId = $scope === UserTagFilter::SCOPE_SYSTEM ? $target->accountRootId() : $target->id;

        return UserTagFilter::updateOrCreate(
            ['owner_key' => $ownerKey, 'tag' => $tag],
            ['user_id' => $ownerUserId, 'mode' => $mode, 'scope' => $scope]
        );
    }

    public function removeFilter(User $actor, User $target, int $filterId, string $scope): void
    {
        $ownerKey = $this->ownerKeyForScope($scope, $target);
        $filter = UserTagFilter::where('owner_key', $ownerKey)->where('id', $filterId)->first();

        if ($filter) {
            $this->authorize($actor, $target, $scope);
            $filter->delete();

            return;
        }

        // Not found under the requested scope. If the id belongs to the other scope for
        // this same target, surface a clear 403/404 instead of a misleading "not found".
        $otherScope = $scope === UserTagFilter::SCOPE_SYSTEM ? UserTagFilter::SCOPE_USER : UserTagFilter::SCOPE_SYSTEM;
        $otherOwnerKey = $this->ownerKeyForScope($otherScope, $target);
        $existsUnderOtherScope = UserTagFilter::where('owner_key', $otherOwnerKey)->where('id', $filterId)->exists();

        if ($existsUnderOtherScope && $otherScope === UserTagFilter::SCOPE_SYSTEM) {
            abort(403, 'This is a system tag filter and cannot be removed here.');
        }

        abort(404, 'Tag filter not found.');
    }

    private function authorize(User $actor, User $target, string $scope): void
    {
        if ($scope === UserTagFilter::SCOPE_SYSTEM) {
            if (!$actor->canManageSystemFiltersFor($target)) {
                abort(403, 'Only an admin or a designated account manager can change a system tag filter.');
            }

            return;
        }

        if ($actor->id !== $target->id && !$actor->isAdmin()) {
            abort(403, 'Cannot change another user\'s personal tag filter.');
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, UserTagFilter>
     */
    public function userFiltersFor(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return UserTagFilter::where('owner_key', $this->ownerKeyForScope(UserTagFilter::SCOPE_USER, $user))->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, UserTagFilter>
     */
    public function systemFiltersForAccount(User $target): \Illuminate\Database\Eloquent\Collection
    {
        return UserTagFilter::where('owner_key', $this->ownerKeyForScope(UserTagFilter::SCOPE_SYSTEM, $target))->get();
    }

    /**
     * Replace an account's system-scope filters without disturbing any member's own
     * personal filters.
     *
     * @param array<int, string> $requiredTags
     * @param array<int, string> $bannedTags
     */
    public function replaceSystemFilters(User $actor, User $target, array $requiredTags, array $bannedTags): void
    {
        if (!$actor->canManageSystemFiltersFor($target)) {
            abort(403, 'Only an admin or a designated account manager can change a system tag filter.');
        }

        $filters = [];
        foreach ($requiredTags as $tag) {
            $filters[trim($tag)] = UserTagFilter::MODE_REQUIRE;
        }
        foreach ($bannedTags as $tag) {
            $filters[trim($tag)] = UserTagFilter::MODE_BAN;
        }
        unset($filters['']);

        $ownerKey = $this->ownerKeyForScope(UserTagFilter::SCOPE_SYSTEM, $target);
        $ownerUserId = $target->accountRootId();

        DB::transaction(function () use ($ownerKey, $ownerUserId, $filters): void {
            $existingFilters = UserTagFilter::where('owner_key', $ownerKey)
                ->lockForUpdate()
                ->get()
                ->keyBy('tag');

            UserTagFilter::where('owner_key', $ownerKey)
                ->when(
                    $filters !== [],
                    fn (Builder $query) => $query->whereNotIn('tag', array_keys($filters))
                )
                ->delete();

            foreach ($filters as $tag => $mode) {
                $filter = $existingFilters->get($tag) ?? new UserTagFilter([
                    'user_id' => $ownerUserId,
                    'owner_key' => $ownerKey,
                    'scope' => UserTagFilter::SCOPE_SYSTEM,
                    'tag' => $tag,
                ]);
                $filter->mode = $mode;
                $filter->save();
            }
        });
    }

    /**
     * Restricts a Book query builder to books satisfying every one of the user's active
     * require/ban filters — their own personal filters, plus their account's system
     * filters. Checks tags visible to the user: system-scope (public), their groups'
     * scope, and their own private scope — matching BookTagService's visibility rules,
     * since a "require the mature tag" rule is typically a system-scope book tag, not
     * something in the user's own private tag list.
     */
    public function applyToBookQuery(Builder $query, int $userId): void
    {
        if (!Schema::hasTable('user_tag_filters') || !Schema::hasTable('book_tags')) {
            return;
        }

        $user = User::find($userId);
        if (!$user) {
            return;
        }

        $ownerKeys = [
            $this->ownerKeyForScope(UserTagFilter::SCOPE_USER, $user),
            $this->ownerKeyForScope(UserTagFilter::SCOPE_SYSTEM, $user),
        ];

        $filters = UserTagFilter::whereIn('owner_key', $ownerKeys)->get();
        if ($filters->isEmpty()) {
            return;
        }

        $groupIds = $user->groups()->pluck('groups.id')->all();

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
     * @return array<int, string>
     */
    public static function parseTagList(?string $rawTags): array
    {
        if ($rawTags === null || trim($rawTags) === '') {
            return [];
        }

        $tags = array_map('trim', explode(',', $rawTags));

        return array_values(array_unique(array_filter($tags, fn (string $tag): bool => $tag !== '')));
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
