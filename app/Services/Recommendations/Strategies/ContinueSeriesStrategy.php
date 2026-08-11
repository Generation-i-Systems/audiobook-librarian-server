<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\Series;
use App\Models\User;
use App\Services\BookCompletionService;
use App\Services\Recommendations\RecommendationStrategyInterface;
use App\Services\Recommendations\ShelfResult;

/**
 * A single "Continue Your Series" shelf: the next unread book from every series the user
 * has started (a completed or in-progress book) but not finished, one book per series. Pure
 * SQL — no embeddings/AI needed, so it's always available. Sequence order (`series_number`,
 * a nullable string column on the book_series pivot) is sorted in PHP rather than via a
 * DB-side numeric CAST, since CAST syntax/behavior isn't portable between MySQL (prod)
 * and SQLite (tests).
 */
class ContinueSeriesStrategy implements RecommendationStrategyInterface
{
    private const MAX_BOOKS = 20;

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
        $completionService = app(BookCompletionService::class);
        $engagedBookIds = $completionService->getEngagedBookIdsForUser($user->id);
        $startedBookIds = array_values(array_unique(array_merge(
            $completionService->getCompletedBookIdsForUser($user->id),
            $completionService->getInProgressBookIdsForUser($user->id),
        )));

        if (empty($startedBookIds)) {
            return [];
        }

        $seriesIds = Series::query()
            ->whereHas('books', fn ($q) => $q->whereIn('books.id', $startedBookIds))
            ->pluck('id');

        $engagedSet = array_flip($engagedBookIds);
        $books = [];

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

            $books[] = ['book_id' => $nextBook->id, 'score' => null];
        }

        if (empty($books)) {
            return [];
        }

        return [
            new ShelfResult(
                shelfKey: 'continue_series',
                title: 'Continue Your Series',
                books: array_slice($books, 0, self::MAX_BOOKS),
            ),
        ];
    }
}
