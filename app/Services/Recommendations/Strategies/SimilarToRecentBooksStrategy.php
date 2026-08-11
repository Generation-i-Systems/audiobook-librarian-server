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

        if (empty($recentBookIds)) {
            return [];
        }

        // Reorder by $recentBookIds (most recently finished first) rather than sortBy()+
        // array_search(), which silently misorders if id types ever mismatch (strict
        // comparison failing to match falls back to position 0).
        $booksById = Book::query()
            ->whereIn('id', $recentBookIds)
            ->with(['authors', 'genres', 'series'])
            ->get()
            ->keyBy('id');

        $seeds = collect($recentBookIds)
            ->map(fn (int $id) => $booksById->get($id))
            ->filter()
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
