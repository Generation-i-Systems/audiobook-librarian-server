<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BlockedEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applies a user's explicit book, author, series, and tag blocks to book queries.
 *
 * This is deliberately separate from UserTagFilterService: blocks are a user's
 * "do not recommend" choices, while tag filters can include administrator-locked
 * parental/targeting rules and must remain invisible and uneditable to the user.
 */
class UserBlockFilterService
{
    /** @param Builder<\App\Models\Book> $query */
    public function applyToBookQuery(Builder $query, ?int $userId): void
    {
        if (!$userId || !Schema::hasTable('blocked_entities')) {
            return;
        }

        $blocks = BlockedEntity::query()
            ->where('user_id', $userId)
            ->get(['entity_type', 'entity_ref_id', 'entity_value']);

        if ($blocks->isEmpty()) {
            return;
        }

        $bookIds = $blocks->where('entity_type', BlockedEntity::TYPE_BOOK)
            ->flatMap(fn (BlockedEntity $block): array => [$block->entity_ref_id, $block->entity_value])
            ->filter(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($bookIds !== []) {
            $query->whereNotIn('books.id', $bookIds);
        }

        $this->excludeNamedRelation(
            $query,
            'authors',
            'authors.id',
            'authors.name',
            $blocks->where('entity_type', BlockedEntity::TYPE_AUTHOR),
        );
        $this->excludeNamedRelation(
            $query,
            'series',
            'series.id',
            'series.name',
            $blocks->where('entity_type', BlockedEntity::TYPE_SERIES),
        );

        $tagValues = $blocks->where('entity_type', BlockedEntity::TYPE_TAG)
            ->pluck('entity_value')
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($tagValues !== [] && Schema::hasTable('book_tags')) {
            $groupIds = User::find($userId)?->groups()->pluck('groups.id')->all() ?? [];
            $query->whereDoesntHave('userTags', function (Builder $tagQuery) use ($userId, $groupIds, $tagValues): void {
                $tagQuery->where(function (Builder $visibleScopes) use ($userId, $groupIds): void {
                    $visibleScopes->where('owner_key', 'system')
                        ->orWhere('owner_key', 'user:' . $userId);
                    foreach ($groupIds as $groupId) {
                        $visibleScopes->orWhere('owner_key', 'group:' . $groupId);
                    }
                })->where(function (Builder $matchingTag) use ($tagValues): void {
                    foreach ($tagValues as $tag) {
                        $matchingTag->orWhereJsonContains('tags', $tag);
                    }
                });
            });
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, BlockedEntity> $blocks
     * @param Builder<\App\Models\Book> $query
     */
    private function excludeNamedRelation(Builder $query, string $relation, string $idColumn, string $nameColumn, \Illuminate\Support\Collection $blocks): void
    {
        $ids = $blocks->pluck('entity_ref_id')->filter()->unique()->values()->all();
        $names = $blocks->pluck('entity_value')
            ->map(fn (string $value): string => trim(mb_strtolower($value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === [] && $names === []) {
            return;
        }

        $query->whereDoesntHave($relation, function (Builder $relationQuery) use ($ids, $names, $idColumn, $nameColumn): void {
            $relationQuery->where(function (Builder $matchingRelation) use ($ids, $names, $idColumn, $nameColumn): void {
                if ($ids !== []) {
                    $matchingRelation->whereIn($idColumn, $ids);
                }
                if ($names !== []) {
                    $method = $ids === [] ? 'whereIn' : 'orWhereIn';
                    $matchingRelation->{$method}(DB::raw('LOWER(' . $nameColumn . ')'), $names);
                }
            });
        });
    }
}
