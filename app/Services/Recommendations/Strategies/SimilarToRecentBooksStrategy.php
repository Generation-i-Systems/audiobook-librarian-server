<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\User;
use App\Services\BookCompletionService;
use App\Services\Recommendations\ShelfResult;

/**
 * One shelf per each of the user's last 3 finished books: "Because you finished X".
 */
class SimilarToRecentBooksStrategy extends AbstractSimilarityStrategy
{
    private const SEED_COUNT = 3;
    // Larger than the discovery-shelf preview size (see DiscoveryController::DEFAULT_SHELF_PREVIEW_SIZE)
    // so "Show more" has real additional books to page into instead of just re-showing the preview.
    private const BOOKS_PER_SHELF = 30;

    public function key(): string
    {
        return 'similar_to_book';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function generate(User $user): array
    {
        $completedDates = app(BookCompletionService::class)->getCompletedBookDatesForUser($user->id);
        $recentBookIds = $completedDates->sortDesc()->keys()->take(self::SEED_COUNT)->all();

        $seeds = Book::query()
            ->whereIn('id', $recentBookIds ?: [0])
            ->with(['authors', 'genres', 'series'])
            ->get()
            ->sortBy(fn (Book $book): int => array_search($book->id, $recentBookIds, true))
            ->values();

        if ($seeds->isEmpty()) {
            return [];
        }

        $excluded = $this->excludedBookIds($user);
        $excludedTitles = app(BookCompletionService::class)->getEngagedNormalizedTitles($user->id);
        $shelves = [];

        foreach ($seeds as $seed) {
            $books = $this->candidatesSimilarTo($seed, $excluded, self::BOOKS_PER_SHELF, $excludedTitles);
            if (empty($books)) {
                continue;
            }

            $shelves[] = new ShelfResult(
                shelfKey: 'similar_to_book:' . $seed->id,
                title: "Because you finished {$seed->title}",
                books: $books,
            );
        }

        return $shelves;
    }
}
