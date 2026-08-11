<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\User;
use App\Services\BookCompletionService;
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
    // Larger than the discovery-shelf preview size (see DiscoveryController::DEFAULT_SHELF_PREVIEW_SIZE)
    // so "Show more" has real additional books to page into instead of just re-showing the preview.
    private const SHELF_SIZE = 30;
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
        $completedDates = app(BookCompletionService::class)->getCompletedBookDatesForUser($user->id);
        $recentBookIds = $completedDates->sortDesc()->keys()->take(self::SEED_COUNT)->all();

        $seeds = Book::query()
            ->whereIn('id', $recentBookIds ?: [0])
            ->with(['authors', 'genres'])
            ->get()
            ->sortBy(fn (Book $book): int => array_search($book->id, $recentBookIds, true))
            ->values();

        if ($seeds->isEmpty()) {
            return [];
        }

        $excluded = $this->excludedBookIds($user);
        $excludedTitles = app(BookCompletionService::class)->getEngagedNormalizedTitles($user->id);
        foreach ($seeds as $seed) {
            $excludedTitles[] = BookCompletionService::normalizeTitle($seed->title);
        }
        $excludedTitles = array_values(array_unique(array_filter($excludedTitles, fn (string $t): bool => $t !== '')));

        $books = $this->pipeline->isAvailable()
            ? ($this->viaVector($seeds, $excluded, $excludedTitles) ?? $this->viaSql($seeds, $excluded, $excludedTitles))
            : $this->viaSql($seeds, $excluded, $excludedTitles);

        return empty($books) ? [] : [new ShelfResult('new_for_you', 'New for You', $books)];
    }

    /**
     * @param Collection<int, Book> $seeds
     * @param array<int, int> $excluded
     * @param array<int, string> $excludedTitles books to treat as duplicates of something the
     *   user already engaged with — see BookCompletionService::normalizeTitle()
     * @return array<int, array{book_id: int, score: float|null}>|null null falls back to SQL
     */
    private function viaVector(Collection $seeds, array $excluded, array $excludedTitles): ?array
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

        // Vector metadata only carries book_id, so title-based dedup needs a bulk lookup here.
        $titles = Book::query()->whereIn('id', array_keys($best))->pluck('title', 'id');

        $results = [];
        foreach ($best as $bookId => $score) {
            $normalized = BookCompletionService::normalizeTitle((string) ($titles[$bookId] ?? ''));
            if ($normalized !== '' && in_array($normalized, $excludedTitles, true)) {
                continue;
            }

            $results[] = ['book_id' => $bookId, 'score' => $score];
            if (count($results) >= self::SHELF_SIZE) {
                break;
            }
        }

        return $results;
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
     * @param array<int, string> $excludedTitles
     * @return array<int, array{book_id: int, score: float|null}>
     */
    private function viaSql(Collection $seeds, array $excluded, array $excludedTitles = []): array
    {
        $genreIds = $seeds->flatMap(fn (Book $b) => $b->genres->pluck('id'))->unique();
        $authorIds = $seeds->flatMap(fn (Book $b) => $b->authors->pluck('id'))->unique();

        if ($genreIds->isEmpty() && $authorIds->isEmpty()) {
            return [];
        }

        $excludeIds = array_merge($excluded, $seeds->pluck('id')->all());
        $fetchLimit = $excludedTitles === [] ? self::SHELF_SIZE : self::SHELF_SIZE * 3;

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
            ->limit($fetchLimit)
            ->get();

        if ($excludedTitles !== []) {
            $candidates = $candidates
                ->filter(fn (Book $b): bool => !in_array(BookCompletionService::normalizeTitle($b->title), $excludedTitles, true))
                ->values();
        }

        return $candidates->take(self::SHELF_SIZE)
            ->map(fn (Book $b): array => ['book_id' => $b->id, 'score' => null])
            ->values()
            ->all();
    }
}
