<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Embeddings\EmbeddingPipeline;
use App\Services\Search\SemanticBookSearchService;
use Mockery;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SemanticBookSearchServiceTest extends TestCase
{
    #[Test]
    public function returnsEmptyWhenPipelineUnavailable(): void
    {
        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->once()->andReturn(false);
        $pipeline->shouldNotReceive('resolveEmbeddingProvider');
        $pipeline->shouldNotReceive('resolveVectorStore');

        $service = new SemanticBookSearchService($pipeline);

        $this->assertSame([], $service->rankedBookIds('dragons and magic'));
    }

    #[Test]
    public function returnsEmptyForBlankQuery(): void
    {
        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldNotReceive('isAvailable');
        $pipeline->shouldNotReceive('resolveEmbeddingProvider');
        $pipeline->shouldNotReceive('resolveVectorStore');

        $service = new SemanticBookSearchService($pipeline);

        $this->assertSame([], $service->rankedBookIds('   '));
    }

    #[Test]
    public function returnsRankedBookIdsFromVectorStore(): void
    {
        $docA = new Document('a');
        $docA->addMetadata('book_id', 7);
        $docA->setScore(0.95);

        $docB = new Document('b');
        $docB->addMetadata('book_id', 3);
        $docB->setScore(0.8);

        $embeddingProvider = Mockery::mock(EmbeddingsProviderInterface::class);
        $embeddingProvider->shouldReceive('embedText')->once()->with('dragons')->andReturn([0.1, 0.2]);

        $store = Mockery::mock(VectorStoreInterface::class);
        $store->shouldReceive('similaritySearch')->once()->andReturn([$docA, $docB]);

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->once()->andReturn(true);
        $pipeline->shouldReceive('resolveEmbeddingProvider')->once()->andReturn($embeddingProvider);
        $pipeline->shouldReceive('resolveVectorStore')->once()->with(50)->andReturn($store);

        $service = new SemanticBookSearchService($pipeline);

        $this->assertSame([7, 3], $service->rankedBookIds('dragons'));
    }

    #[Test]
    public function deduplicatesBookIds(): void
    {
        $docA = new Document('a');
        $docA->addMetadata('book_id', 7);

        $docB = new Document('b');
        $docB->addMetadata('book_id', 7);

        $embeddingProvider = Mockery::mock(EmbeddingsProviderInterface::class);
        $embeddingProvider->shouldReceive('embedText')->once()->andReturn([0.1]);

        $store = Mockery::mock(VectorStoreInterface::class);
        $store->shouldReceive('similaritySearch')->once()->andReturn([$docA, $docB]);

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->once()->andReturn(true);
        $pipeline->shouldReceive('resolveEmbeddingProvider')->once()->andReturn($embeddingProvider);
        $pipeline->shouldReceive('resolveVectorStore')->once()->andReturn($store);

        $service = new SemanticBookSearchService($pipeline);

        $this->assertSame([7], $service->rankedBookIds('dragons'));
    }

    #[Test]
    public function skipsZeroOrMissingBookIdMetadata(): void
    {
        $docZero = new Document('a');
        $docZero->addMetadata('book_id', 0);

        $docMissing = new Document('b');

        $docValid = new Document('c');
        $docValid->addMetadata('book_id', 4);

        $embeddingProvider = Mockery::mock(EmbeddingsProviderInterface::class);
        $embeddingProvider->shouldReceive('embedText')->once()->andReturn([0.1]);

        $store = Mockery::mock(VectorStoreInterface::class);
        $store->shouldReceive('similaritySearch')->once()->andReturn([$docZero, $docMissing, $docValid]);

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->once()->andReturn(true);
        $pipeline->shouldReceive('resolveEmbeddingProvider')->once()->andReturn($embeddingProvider);
        $pipeline->shouldReceive('resolveVectorStore')->once()->andReturn($store);

        $service = new SemanticBookSearchService($pipeline);

        $this->assertSame([4], $service->rankedBookIds('dragons'));
    }

    #[Test]
    public function fallsBackToEmptyOnThrowable(): void
    {
        $embeddingProvider = Mockery::mock(EmbeddingsProviderInterface::class);
        $embeddingProvider->shouldReceive('embedText')->once()->andThrow(new \RuntimeException('provider down'));

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->once()->andReturn(true);
        $pipeline->shouldReceive('resolveEmbeddingProvider')->once()->andReturn($embeddingProvider);
        $pipeline->shouldNotReceive('resolveVectorStore');

        $service = new SemanticBookSearchService($pipeline);

        $this->assertSame([], $service->rankedBookIds('dragons'));
    }

    #[Test]
    public function respectsCustomLimitByPassingTopKToVectorStore(): void
    {
        $embeddingProvider = Mockery::mock(EmbeddingsProviderInterface::class);
        $embeddingProvider->shouldReceive('embedText')->once()->andReturn([0.1]);

        $store = Mockery::mock(VectorStoreInterface::class);
        $store->shouldReceive('similaritySearch')->once()->andReturn([]);

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->once()->andReturn(true);
        $pipeline->shouldReceive('resolveEmbeddingProvider')->once()->andReturn($embeddingProvider);
        $pipeline->shouldReceive('resolveVectorStore')->once()->with(10)->andReturn($store);

        $service = new SemanticBookSearchService($pipeline);

        $service->rankedBookIds('dragons', 10);
    }
}
