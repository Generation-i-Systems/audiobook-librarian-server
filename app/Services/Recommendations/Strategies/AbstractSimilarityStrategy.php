<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Strategies;

use App\Contracts\AI\AIProviderInterface;
use App\Models\Book;
use App\Services\AI\AIProviderFactory;
use App\Services\Embeddings\BookEmbeddingTextBuilder;
use App\Services\Embeddings\EmbeddingPipeline;
use App\Services\Recommendations\Concerns\ExcludesEngagedBooks;
use App\Services\Recommendations\RecommendationStrategyInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Shared "find books similar to a seed book" logic used by SimilarToRecentBooksStrategy
 * and NewForYouStrategy: vector similarity search when the embedding pipeline has usable
 * data, degrading to a SQL genre/author/series overlap query + one AI ranking call
 * (further degrading to plain recency order if that call fails) otherwise.
 */
abstract class AbstractSimilarityStrategy implements RecommendationStrategyInterface
{
    use ExcludesEngagedBooks;

    public function __construct(
        protected EmbeddingPipeline $pipeline,
        protected BookEmbeddingTextBuilder $textBuilder,
    ) {
    }

    /**
     * Extension point so tests can substitute a fake provider without a real network
     * call, rather than statically resolving AIProviderFactory::make() inline.
     */
    protected function aiProvider(): AIProviderInterface
    {
        return AIProviderFactory::make();
    }

    /**
     * @param array<int, int> $excludedBookIds
     * @return array<int, array{book_id: int, score: float|null}>
     */
    protected function candidatesSimilarTo(Book $seed, array $excludedBookIds, int $limit): array
    {
        if ($this->pipeline->isAvailable()) {
            $result = $this->similarViaVector($seed, $excludedBookIds, $limit);
            if ($result !== null) {
                return $result;
            }
        }

        return $this->similarViaSqlAndAi($seed, $excludedBookIds, $limit);
    }

    /**
     * @param array<int, int> $excludedBookIds
     * @return array<int, array{book_id: int, score: float|null}>|null null means "fall back to SQL"
     */
    private function similarViaVector(Book $seed, array $excludedBookIds, int $limit): ?array
    {
        try {
            $provider = $this->pipeline->resolveEmbeddingProvider();
            if ($provider === null) {
                return null;
            }

            $embedding = $provider->embedText($this->textBuilder->buildMetadataText($seed));
            $documents = $this->pipeline->resolveVectorStore()->similaritySearch($embedding);
        } catch (\Throwable $e) {
            Log::warning('Vector similarity search failed, falling back to SQL', [
                'seed_book_id' => $seed->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $excluded = array_flip($excludedBookIds);
        $excluded[$seed->id] = true;

        $results = [];
        foreach ($documents as $document) {
            $bookId = (int) ($document->metadata['book_id'] ?? 0);
            if ($bookId === 0 || isset($excluded[$bookId])) {
                continue;
            }

            $results[] = ['book_id' => $bookId, 'score' => $document->getScore()];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param array<int, int> $excludedBookIds
     * @return array<int, array{book_id: int, score: float|null}>
     */
    private function similarViaSqlAndAi(Book $seed, array $excludedBookIds, int $limit): array
    {
        $candidates = $this->sqlCandidates($seed, $excludedBookIds, 40);
        if ($candidates->isEmpty()) {
            return [];
        }

        $orderedIds = $this->rankWithAi($seed, $candidates) ?? $candidates->pluck('id')->all();

        return collect($orderedIds)
            ->take($limit)
            ->map(fn ($id): array => ['book_id' => (int) $id, 'score' => null])
            ->values()
            ->all();
    }

    private function sqlCandidates(Book $seed, array $excludedBookIds, int $cap): Collection
    {
        if (!$seed->relationLoaded('genres')) {
            $seed->load(['genres', 'authors', 'series']);
        }

        $genreIds = $seed->genres->pluck('id');
        $authorIds = $seed->authors->pluck('id');
        $seriesIds = $seed->series->pluck('id');

        if ($genreIds->isEmpty() && $authorIds->isEmpty() && $seriesIds->isEmpty()) {
            return collect();
        }

        return Book::query()
            ->where('id', '!=', $seed->id)
            ->whereNotIn('id', $excludedBookIds ?: [0])
            ->where(function ($query) use ($genreIds, $authorIds, $seriesIds): void {
                if ($genreIds->isNotEmpty()) {
                    $query->orWhereHas('genres', fn ($q) => $q->whereIn('genres.id', $genreIds));
                }
                if ($authorIds->isNotEmpty()) {
                    $query->orWhereHas('authors', fn ($q) => $q->whereIn('authors.id', $authorIds));
                }
                if ($seriesIds->isNotEmpty()) {
                    $query->orWhereHas('series', fn ($q) => $q->whereIn('series.id', $seriesIds));
                }
            })
            ->with(['authors', 'genres'])
            ->orderByDesc('created_at')
            ->limit($cap)
            ->get();
    }

    /**
     * @return array<int, int>|null null means "AI ranking unavailable, use SQL order as-is"
     */
    private function rankWithAi(Book $seed, Collection $candidates): ?array
    {
        try {
            $catalog = $candidates->map(fn (Book $b): array => [
                'id' => $b->id,
                'title' => $b->title,
                'authors' => $b->authors->pluck('name')->all(),
                'genres' => $b->genres->pluck('name')->all(),
            ])->values()->all();

            $prompt = sprintf(
                "A reader just finished \"%s\" by %s. From the following candidate books (JSON), " .
                    'pick and order the ones most similar in tone/genre/style, best match first. ' .
                    "Return only book ids that are genuinely good matches.\n\nCandidates: %s",
                $seed->title,
                $seed->authors->pluck('name')->join(', '),
                json_encode($catalog)
            );

            $response = $this->aiProvider()->completeStructured($prompt, ['required' => ['book_ids']]);
            if (!$response->isSuccess()) {
                return null;
            }

            $ids = $response->getData()['book_ids'] ?? null;
            if (!is_array($ids) || empty($ids)) {
                return null;
            }

            $validIds = $candidates->pluck('id')->all();
            $ranked = array_values(array_intersect(array_map('intval', $ids), $validIds));

            return $ranked === [] ? null : $ranked;
        } catch (\Throwable $e) {
            Log::warning('AI similarity ranking failed, using SQL order', [
                'seed_book_id' => $seed->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
