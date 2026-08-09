<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Strategies;

use App\Models\User;
use App\Services\Recommendations\ShelfResult;

/**
 * One shelf per each of the user's last 5 finished books: "Because you finished X".
 */
class SimilarToRecentBooksStrategy extends AbstractSimilarityStrategy
{
    private const SEED_COUNT = 5;
    private const BOOKS_PER_SHELF = 10;

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
        $seeds = $user->bookStatuses()
            ->where('status', 'completed')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->limit(self::SEED_COUNT)
            ->with(['book.authors', 'book.genres', 'book.series'])
            ->get()
            ->pluck('book')
            ->filter();

        if ($seeds->isEmpty()) {
            return [];
        }

        $excluded = $this->excludedBookIds($user);
        $shelves = [];

        foreach ($seeds as $seed) {
            $books = $this->candidatesSimilarTo($seed, $excluded, self::BOOKS_PER_SHELF);
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
