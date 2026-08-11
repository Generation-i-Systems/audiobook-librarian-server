<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\User;
use App\Services\Recommendations\Concerns\ExcludesEngagedBooks;
use App\Services\Recommendations\FavoredGenreResolver;
use App\Services\Recommendations\RecommendationStrategyInterface;
use App\Services\Recommendations\ShelfResult;
use Illuminate\Support\Collection;

/**
 * "Quick Listens" (< 5 hours) and "Epic Listens" (> 20 hours) shelves, restricted to
 * genres the user has completed at least one book in. Pure SQL — no embeddings/AI
 * needed, so it's always available.
 */
class DurationBasedStrategy implements RecommendationStrategyInterface
{
    use ExcludesEngagedBooks;

    private const QUICK_MAX_SECONDS = 18000; // 5 hours
    private const EPIC_MIN_SECONDS = 72000; // 20 hours
    // Larger than the discovery-shelf preview size (see DiscoveryController::DEFAULT_SHELF_PREVIEW_SIZE)
    // so "Show more" has real additional books to page into instead of just re-showing the preview.
    private const BOOKS_PER_SHELF = 30;

    public function __construct(private readonly FavoredGenreResolver $favoredGenres)
    {
    }

    public function key(): string
    {
        return 'duration_based';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function generate(User $user): array
    {
        $favoredGenreIds = $this->favoredGenres->forUser($user);

        if ($favoredGenreIds->isEmpty()) {
            return [];
        }

        $excluded = $this->excludedBookIds($user);
        $shelves = [];

        $quick = $this->booksInDurationRange($favoredGenreIds, $excluded, null, self::QUICK_MAX_SECONDS);
        if ($quick->isNotEmpty()) {
            $shelves[] = new ShelfResult('quick_listens', 'Quick Listens', $this->toBookRefs($quick));
        }

        $epic = $this->booksInDurationRange($favoredGenreIds, $excluded, self::EPIC_MIN_SECONDS, null);
        if ($epic->isNotEmpty()) {
            $shelves[] = new ShelfResult('epic_listens', 'Epic Listens', $this->toBookRefs($epic));
        }

        return $shelves;
    }

    /**
     * @param Collection<int, int> $genreIds
     * @param array<int, int> $excludedBookIds
     */
    private function booksInDurationRange(Collection $genreIds, array $excludedBookIds, ?int $min, ?int $max): Collection
    {
        return Book::query()
            ->whereNotIn('id', $excludedBookIds ?: [0])
            ->whereHas('genres', fn ($q) => $q->whereIn('genres.id', $genreIds))
            ->whereNotNull('duration')
            ->when($min !== null, fn ($q) => $q->where('duration', '>=', $min))
            ->when($max !== null, fn ($q) => $q->where('duration', '<=', $max))
            ->orderByDesc('created_at')
            ->limit(self::BOOKS_PER_SHELF)
            ->get();
    }

    /**
     * @return array<int, array{book_id: int, score: float|null}>
     */
    private function toBookRefs(Collection $books): array
    {
        return $books->map(fn (Book $book): array => ['book_id' => $book->id, 'score' => null])->values()->all();
    }
}
