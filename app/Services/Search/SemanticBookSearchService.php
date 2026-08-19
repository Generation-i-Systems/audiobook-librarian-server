<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\Embeddings\EmbeddingPipeline;
use Illuminate\Support\Facades\Log;

/**
 * Opt-in "smart search" over the same per-book embeddings used by the recommendation
 * engine (see EmbeddingPipeline, AbstractSimilarityStrategy). Ranks books by semantic
 * similarity to a free-text query instead of exact/partial text match.
 */
class SemanticBookSearchService
{
    public function __construct(private EmbeddingPipeline $pipeline)
    {
    }

    /**
     * @return array<int, int> ranked book ids, most relevant first. Empty means the
     *   pipeline is unavailable or the search failed — callers should fall back to
     *   normal SQL search rather than surfacing an error.
     */
    public function rankedBookIds(string $query, int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '' || !$this->pipeline->isAvailable()) {
            return [];
        }

        try {
            $provider = $this->pipeline->resolveEmbeddingProvider();
            if ($provider === null) {
                return [];
            }

            $embedding = $provider->embedText($query);
            $documents = $this->pipeline->resolveVectorStore($limit)->similaritySearch($embedding);
        } catch (\Throwable $e) {
            Log::warning('Semantic book search failed, falling back to SQL search', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $ids = [];
        foreach ($documents as $document) {
            $bookId = (int) ($document->metadata['book_id'] ?? 0);
            if ($bookId !== 0) {
                $ids[] = $bookId;
            }
        }

        return array_values(array_unique($ids));
    }
}
