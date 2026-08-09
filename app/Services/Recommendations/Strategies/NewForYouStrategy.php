<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\User;
use App\Services\Embeddings\BookEmbeddingTextBuilder;
use App\Services\Embeddings\EmbeddingPipeline;
use App\Services\Recommendations\Concerns\ExcludesEngagedBooks;
use App\Services\Recommendations\RecommendationStrategyInterface;
use App\Services\Recommendations\ShelfResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * A single "New for You" shelf: similar to the user's recent reads, but re-ranked to
 * weight newly-added books higher, so new catalog additions surface even when only
 * moderately similar. Falls back to a plain "recent + genre/author overlap" SQL query
 * when the vector store isn't available — which is itself already recency-first, so no
 * separate AI ranking call is needed in that path (unlike AbstractSimilarityStrategy).
 */
class NewForYouStrategy implements RecommendationStrategyInterface
{
    use ExcludesEngagedBooks;

    private const SEED_COUNT = 5;
    private const SHELF_SIZE = 10;
    private const SIMILARITY_WEIGHT = 0.6;
    private const RECENCY_WEIGHT = 0.4;

    public function __construct(
        protected EmbeddingPipeline $pipeline,
        protected BookEmbeddingTextBuilder $textBuilder,
    ) {
    }

    public function key(): string
    {
        return 'new_for_you';
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
            ->with(['book.authors', 'book.genres'])
            ->get()
            ->pluck('book')
            ->filter();

        if ($seeds->isEmpty()) {
            return [];
        }

        $excluded = $this->excludedBookIds($user);
        $books = $this->pipeline->isAvailable()
            ? ($this->viaVector($seeds, $excluded) ?? $this->viaSql($seeds, $excluded))
            : $this->viaSql($seeds, $excluded);

        return empty($books) ? [] : [new ShelfResult('new_for_you', 'New for You', $books)];
    }

    /**
     * @param Collection<int, Book> $seeds
     * @param array<int, int> $excluded
     * @return array<int, array{book_id: int, score: float|null}>|null null falls back to SQL
     */
    private function viaVector(Collection $seeds, array $excluded): ?array
    {
        try {
            $provider = $this->pipeline->resolveEmbeddingProvider();
            if ($provider === null) {
                return null;
            }

            $excludedSet = array_flip($excluded);
            foreach ($seeds as $seed) {
                $excludedSet[$seed->id] = true;
            }

            $store = $this->pipeline->resolveVectorStore();
            $best = [];

            foreach ($seeds as $seed) {
                $embedding = $provider->embedText($this->textBuilder->buildMetadataText($seed));
                foreach ($store->similaritySearch($embedding) as $document) {
                    $bookId = (int) ($document->metadata['book_id'] ?? 0);
                    if ($bookId === 0 || isset($excludedSet[$bookId])) {
                        continue;
                    }

                    $blended = $document->getScore() * self::SIMILARITY_WEIGHT
                        + $this->recencyScore($bookId) * self::RECENCY_WEIGHT;

                    if (!isset($best[$bookId]) || $blended > $best[$bookId]) {
                        $best[$bookId] = $blended;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('New-for-you vector search failed, falling back to SQL', ['error' => $e->getMessage()]);
            return null;
        }

        if (empty($best)) {
            return null;
        }

        arsort($best);

        return collect($best)
            ->take(self::SHELF_SIZE)
            ->map(fn (float $score, int $bookId): array => ['book_id' => $bookId, 'score' => $score])
            ->values()
            ->all();
    }

    private function recencyScore(int $bookId): float
    {
        $createdAt = Book::query()->whereKey($bookId)->value('created_at');
        if (!$createdAt) {
            return 0.0;
        }

        $daysOld = max(0, now()->diffInDays($createdAt));

        return 1 / (1 + $daysOld / 30);
    }

    /**
     * @param Collection<int, Book> $seeds
     * @param array<int, int> $excluded
     * @return array<int, array{book_id: int, score: float|null}>
     */
    private function viaSql(Collection $seeds, array $excluded): array
    {
        $genreIds = $seeds->flatMap(fn (Book $b) => $b->genres->pluck('id'))->unique();
        $authorIds = $seeds->flatMap(fn (Book $b) => $b->authors->pluck('id'))->unique();

        if ($genreIds->isEmpty() && $authorIds->isEmpty()) {
            return [];
        }

        $excludeIds = array_merge($excluded, $seeds->pluck('id')->all());

        $candidates = Book::query()
            ->whereNotIn('id', $excludeIds ?: [0])
            ->where(function ($query) use ($genreIds, $authorIds): void {
                if ($genreIds->isNotEmpty()) {
                    $query->orWhereHas('genres', fn ($q) => $q->whereIn('genres.id', $genreIds));
                }
                if ($authorIds->isNotEmpty()) {
                    $query->orWhereHas('authors', fn ($q) => $q->whereIn('authors.id', $authorIds));
                }
            })
            ->orderByDesc('created_at')
            ->limit(self::SHELF_SIZE)
            ->get();

        return $candidates->map(fn (Book $b): array => ['book_id' => $b->id, 'score' => null])->values()->all();
    }
}
