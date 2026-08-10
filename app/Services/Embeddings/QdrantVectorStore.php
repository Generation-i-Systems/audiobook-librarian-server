<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\QdrantVectorStore as BaseQdrantVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/**
 * Custom Qdrant vector store adapter that ensures numeric point IDs (e.g. integer book IDs)
 * are formatted as integers rather than stringified numbers, satisfying Qdrant's point ID schema.
 */
class QdrantVectorStore extends BaseQdrantVectorStore
{
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $points = array_map(fn (Document $document): array => [
            'id' => is_numeric($document->getId()) ? (int) $document->getId() : (string) $document->getId(),
            'payload' => [
                'content' => $document->getContent(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...$document->metadata,
            ],
            'vector' => $document->getEmbedding(),
        ], $documents);

        $chunks = array_chunk($points, 100);

        foreach ($chunks as $chunk) {
            $this->httpClient->request(
                HttpRequest::put(uri: 'points?wait=true', body: ['points' => $chunk])
            );
        }

        return $this;
    }
}
