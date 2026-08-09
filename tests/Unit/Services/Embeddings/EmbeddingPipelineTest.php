<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Embeddings;

use App\Services\Embeddings\EmbeddingPipeline;
use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use Tests\TestCase;

class EmbeddingPipelineTest extends TestCase
{
    public function testIsAvailableReturnsFalseWhenNoProviderConfigured(): void
    {
        config(['neuron.embedding.default' => null]);

        $pipeline = new EmbeddingPipeline();

        $this->assertFalse($pipeline->isAvailable());
        $this->assertNull($pipeline->resolveEmbeddingProvider());
    }

    public function testIsAvailableReturnsFalseWhenProviderConfiguredWithoutKey(): void
    {
        config([
            'neuron.embedding.default' => 'gemini',
            'neuron.embedding.gemini' => ['key' => null, 'model' => 'gemini-pro-embed-v1', 'config' => []],
        ]);

        $pipeline = new EmbeddingPipeline();

        $this->assertFalse($pipeline->isAvailable());
    }

    public function testResolveEmbeddingProviderReturnsGeminiProviderWhenConfigured(): void
    {
        config([
            'neuron.embedding.default' => 'gemini',
            'neuron.embedding.gemini' => ['key' => 'test-key', 'model' => 'gemini-pro-embed-v1', 'config' => []],
        ]);

        $pipeline = new EmbeddingPipeline();

        $this->assertTrue($pipeline->isAvailable());
        $this->assertInstanceOf(GeminiEmbeddingsProvider::class, $pipeline->resolveEmbeddingProvider());
    }

    public function testResolveEmbeddingProviderReturnsOpenAiProviderWhenConfigured(): void
    {
        config([
            'neuron.embedding.default' => 'openai',
            'neuron.embedding.openai' => ['key' => 'test-key', 'model' => 'text-embedding-ada-002', 'dimensions' => 1024],
        ]);

        $pipeline = new EmbeddingPipeline();

        $this->assertInstanceOf(OpenAIEmbeddingsProvider::class, $pipeline->resolveEmbeddingProvider());
    }

    public function testResolveVectorStoreReturnsFileVectorStoreByDefault(): void
    {
        config([
            'neuron.store.default' => 'file',
            'neuron.store.file' => [
                'directory' => sys_get_temp_dir() . '/neuron-test-' . uniqid(),
                'topK' => 5,
                'name' => 'neuron',
                'ext' => '.store',
            ],
        ]);

        $pipeline = new EmbeddingPipeline();

        $this->assertInstanceOf(FileVectorStore::class, $pipeline->resolveVectorStore());
    }

    public function testResolveVectorStoreThrowsForUnsupportedStore(): void
    {
        config(['neuron.store.default' => 'qdrant']);

        $pipeline = new EmbeddingPipeline();

        $this->expectException(\RuntimeException::class);
        $pipeline->resolveVectorStore();
    }
}
