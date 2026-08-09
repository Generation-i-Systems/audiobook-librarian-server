<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\Series;
use App\Models\User;
use App\Services\Recommendations\RecommendationStrategyInterface;
use App\Services\Recommendations\ShelfResult;

/**
 * One shelf per series the user has started (a completed or in-progress book) but not
 * finished, surfacing the next unread book in sequence: "Continue: {series}". Pure SQL —
 * no embeddings/AI needed, so it's always available. Sequence order (`series_number`, a
 * nullable string column on the book_series pivot) is sorted in PHP rather than via a
 * DB-side numeric CAST, since CAST syntax/behavior isn't portable between MySQL (prod)
 * and SQLite (tests).
 */
class ContinueSeriesStrategy implements RecommendationStrategyInterface
{
    public function key(): string
    {
        return 'continue_series';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function generate(User $user): array
    {
        $engagedBookIds = $user->bookStatuses()->whereNotNull('book_id')->pluck('book_id')->all();
        $startedBookIds = $user->bookStatuses()
            ->whereIn('status', ['completed', 'in_progress'])
            ->whereNotNull('book_id')
            ->pluck('book_id')
            ->all();

        if (empty($startedBookIds)) {
            return [];
        }

        $seriesIds = Series::query()
            ->whereHas('books', fn ($q) => $q->whereIn('books.id', $startedBookIds))
            ->pluck('id');

        $engagedSet = array_flip($engagedBookIds);
        $shelves = [];

        foreach ($seriesIds as $seriesId) {
            $series = Series::find($seriesId);
            if (!$series) {
                continue;
            }

            $nextBook = $series->books()->get()
                ->sortBy(fn (Book $book) => (int) ($book->pivot->series_number ?? PHP_INT_MAX))
                ->first(fn (Book $book) => !isset($engagedSet[$book->id]));

            if (!$nextBook) {
                continue;
            }

            $shelves[] = new ShelfResult(
                shelfKey: 'continue_series:' . $series->id,
                title: "Continue: {$series->name}",
                books: [['book_id' => $nextBook->id, 'score' => null]],
            );
        }

        return $shelves;
    }
}
