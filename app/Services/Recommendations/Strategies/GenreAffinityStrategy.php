<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
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
    private const BOOKS_PER_SHELF = 10;

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
        // Filtered/ordered in PHP rather than a SQL HAVING clause on the withCount alias,
        // since referencing a computed-column alias in HAVING isn't portable between
        // MySQL (prod) and SQLite (tests).
        $topGenres = Genre::query()
            ->withCount(['books as completed_count' => function ($query) use ($user): void {
                $query->whereHas('statuses', fn ($q) => $q->where('user_id', $user->id)->where('status', 'completed'));
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
