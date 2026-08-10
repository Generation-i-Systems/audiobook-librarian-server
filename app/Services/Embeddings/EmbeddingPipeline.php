<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\MistralEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/**
 * Resolves the configured embedding provider and vector store (config/neuron.php)
 * for the book-recommendation similarity pipeline. Deliberately bypasses neuron-ai's
 * Agent/RAG abstraction, which is built for a chat-agent workflow, in favor of direct
 * embed/search calls suited to a batch job + a similarity query.
 */
class EmbeddingPipeline
{
    /**
     * True when an embedding provider is configured with credentials. When false,
     * callers should use the SQL + LLM fallback path instead of the vector store.
     */
    public function isAvailable(): bool
    {
        return $this->resolveEmbeddingProvider() !== null;
    }

    public function resolveEmbeddingProvider(): ?EmbeddingsProviderInterface
    {
        $name = config('neuron.embedding.default');
        if (empty($name)) {
            return null;
        }

        $config = config("neuron.embedding.{$name}", []);

        return match ($name) {
            'openai' => empty($config['key']) ? null : new OpenAIEmbeddingsProvider(
                $config['key'],
                $config['model'],
                $config['dimensions'] ?? null
            ),
            'gemini' => empty($config['key']) ? null : new GeminiEmbeddingsProvider(
                $config['key'],
                $config['model'],
                $config['config'] ?? []
            ),
            'voyage' => empty($config['key']) ? null : new VoyageEmbeddingsProvider(
                $config['key'],
                $config['model']
            ),
            'mistral' => empty($config['key']) ? null : new MistralEmbeddingsProvider(
                $config['key'],
                $config['model'],
                $config['dimensions'] ?? null
            ),
            'ollama' => new OllamaEmbeddingsProvider($config['model'], $config['url'] ?? 'http://localhost:11434/api'),
            default => null,
        };
    }

    public function resolveVectorStore(): VectorStoreInterface
    {
        $name = config('neuron.store.default', 'file');

        if (!in_array($name, ['file', 'qdrant'], true)) {
            throw new \RuntimeException("Vector store '{$name}' is not supported; implemented stores are 'file' and 'qdrant'.");
        }

        $config = config("neuron.store.{$name}", []);

        return match ($name) {
            'file' => new FileVectorStore(
                directory: $config['directory'] ?? storage_path('neuron'),
                topK: (int) ($config['topK'] ?? 5),
                name: $config['name'] ?? 'neuron',
                ext: $config['ext'] ?? '.store'
            ),
            'qdrant' => new QdrantVectorStore(
                collectionUrl: $config['collectionUrl'] ?? 'http://localhost:6333/collections/audiobook-librarian/',
                key: $config['key'] ?? null,
                topK: (int) ($config['topK'] ?? 5),
                dimension: (int) ($config['dimension'] ?? 3072)
            ),
        };
    }
}
