<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Strategies;

use App\Models\Author;
use App\Models\Book;
use App\Models\User;
use App\Services\BookCompletionService;
use App\Services\Recommendations\Concerns\ExcludesEngagedBooks;
use App\Services\Recommendations\RecommendationStrategyInterface;
use App\Services\Recommendations\ShelfResult;

/**
 * One shelf per an author the user has completed 2+ books from: "More by {author}".
 * Pure SQL — no embeddings/AI needed, so it's always available.
 */
class AuthorAffinityStrategy implements RecommendationStrategyInterface
{
    use ExcludesEngagedBooks;

    private const MIN_COMPLETED_BOOKS = 2;
    private const TOP_AUTHORS = 2;
    // Larger than the discovery-shelf preview size (see DiscoveryController::DEFAULT_SHELF_PREVIEW_SIZE)
    // so "Show more" has real additional books to page into instead of just re-showing the preview.
    private const BOOKS_PER_SHELF = 30;

    public function key(): string
    {
        return 'author_affinity';
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
        $topAuthors = Author::query()
            ->withCount(['books as completed_count' => function ($query) use ($completedBookIds): void {
                $query->whereIn('books.id', $completedBookIds ?: [0]);
            }])
            ->get()
            ->filter(fn (Author $author) => $author->getAttribute('completed_count') >= self::MIN_COMPLETED_BOOKS)
            ->sortByDesc('completed_count')
            ->take(self::TOP_AUTHORS);

        if ($topAuthors->isEmpty()) {
            return [];
        }

        $excluded = $this->excludedBookIds($user);
        $shelves = [];

        foreach ($topAuthors as $author) {
            $books = $author->books()
                ->whereNotIn('books.id', $excluded ?: [0])
                ->orderByDesc('books.created_at')
                ->limit(self::BOOKS_PER_SHELF)
                ->get();

            if ($books->isEmpty()) {
                continue;
            }

            $shelves[] = new ShelfResult(
                shelfKey: 'author_affinity:' . $author->id,
                title: "More by {$author->name}",
                books: $books->map(fn (Book $book): array => ['book_id' => $book->id, 'score' => null])->values()->all(),
            );
        }

        return $shelves;
    }
}
