<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use App\Services\BookCompletionService;
use App\Services\Recommendations\Concerns\ExcludesEngagedBooks;
use App\Services\Recommendations\RecommendationStrategyInterface;
use App\Services\Recommendations\ShelfResult;

/**
 * One shelf per the user's top genres by completed-book count: "More in {genre}".
 * Pure SQL — no embeddings/AI needed, so it's always available.
 */
class GenreAffinityStrategy implements RecommendationStrategyInterface
{
    use ExcludesEngagedBooks;

    private const TOP_GENRES = 2;
    // Larger than the discovery-shelf preview size (see DiscoveryController::DEFAULT_SHELF_PREVIEW_SIZE)
    // so "Show more" has real additional books to page into instead of just re-showing the preview.
    private const BOOKS_PER_SHELF = 30;

    public function key(): string
    {
        return 'genre_affinity';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function generate(User $user): array
    {
        $completedBookIds = app(BookCompletionService::class)->getCompletedBookIdsForUser($user->id);

        // Filtered/ordered in PHP rather than a SQL HAVING clause on the withCount alias,
        // since referencing a computed-column alias in HAVING isn't portable between
        // MySQL (prod) and SQLite (tests).
        $topGenres = Genre::query()
            ->withCount(['books as completed_count' => function ($query) use ($completedBookIds): void {
                $query->whereIn('books.id', $completedBookIds ?: [0]);
            }])
            ->get()
            ->filter(fn (Genre $genre) => $genre->getAttribute('completed_count') > 0)
            ->sortByDesc('completed_count')
            ->take(self::TOP_GENRES);

        if ($topGenres->isEmpty()) {
            return [];
        }

        $excluded = $this->excludedBookIds($user);
        $shelves = [];

        foreach ($topGenres as $genre) {
            $books = $genre->books()
                ->whereNotIn('books.id', $excluded ?: [0])
                ->orderByDesc('books.created_at')
                ->limit(self::BOOKS_PER_SHELF)
                ->get();

            if ($books->isEmpty()) {
                continue;
            }

            $shelves[] = new ShelfResult(
                shelfKey: 'genre_affinity:' . $genre->id,
                title: "More in {$genre->name}",
                books: $books->map(fn (Book $book): array => ['book_id' => $book->id, 'score' => null])->values()->all(),
            );
        }

        return $shelves;
    }
}
