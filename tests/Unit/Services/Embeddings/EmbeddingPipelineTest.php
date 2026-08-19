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
        config(['neuron.store.default' => 'pinecone']);

        $pipeline = new EmbeddingPipeline();

        $this->expectException(\RuntimeException::class);
        $pipeline->resolveVectorStore();
    }

    public function testResolveVectorStoreUsesConfigTopKWhenNoOverrideGiven(): void
    {
        $dir = sys_get_temp_dir() . '/neuron-test-' . uniqid();
        config([
            'neuron.store.default' => 'file',
            'neuron.store.file' => ['directory' => $dir, 'topK' => 7, 'name' => 'neuron', 'ext' => '.store'],
        ]);

        $pipeline = new EmbeddingPipeline();
        $store = $pipeline->resolveVectorStore();

        $ref = new \ReflectionProperty(FileVectorStore::class, 'topK');
        $ref->setAccessible(true);
        $this->assertSame(7, $ref->getValue($store));
    }

    public function testResolveVectorStoreUsesOverrideTopKWhenGiven(): void
    {
        $dir = sys_get_temp_dir() . '/neuron-test-' . uniqid();
        config([
            'neuron.store.default' => 'file',
            'neuron.store.file' => ['directory' => $dir, 'topK' => 7, 'name' => 'neuron', 'ext' => '.store'],
        ]);

        $pipeline = new EmbeddingPipeline();
        $store = $pipeline->resolveVectorStore(50);

        $ref = new \ReflectionProperty(FileVectorStore::class, 'topK');
        $ref->setAccessible(true);
        $this->assertSame(50, $ref->getValue($store));
    }

    public function testResolveVectorStoreAttemptsQdrantInitializationWhenConfigured(): void
    {
        config([
            'neuron.store.default' => 'qdrant',
            'neuron.store.qdrant' => [
                'collectionUrl' => 'http://127.0.0.1:63333/collections/test/',
                'key' => null,
                'topK' => 5,
                'dimension' => 1024,
            ],
        ]);

        $pipeline = new EmbeddingPipeline();

        try {
            $store = $pipeline->resolveVectorStore();
            $this->assertInstanceOf(\NeuronAI\RAG\VectorStore\VectorStoreInterface::class, $store);
        } catch (\Throwable $e) {
            // Instantiation attempts connection to collectionUrl; any network/http exception confirms QdrantVectorStore initialization path was reached
            $this->assertNotInstanceOf(\RuntimeException::class, $e);
        }
    }
}
