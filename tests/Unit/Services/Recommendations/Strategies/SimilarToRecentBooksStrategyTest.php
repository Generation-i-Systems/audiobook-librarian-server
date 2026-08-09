<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recommendations\Strategies;

use App\Contracts\AI\AIResponse;
use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Services\Embeddings\BookEmbeddingTextBuilder;
use App\Services\Embeddings\EmbeddingPipeline;
use App\Services\Recommendations\Strategies\SimilarToRecentBooksStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Tests\TestCase;

class SimilarToRecentBooksStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function testGeneratesNoShelvesWithoutFinishedBooks(): void
    {
        $user = User::factory()->create();
        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldNotReceive('isAvailable');

        $strategy = new SimilarToRecentBooksStrategy($pipeline, new BookEmbeddingTextBuilder());

        $this->assertSame([], $strategy->generate($user));
    }

    public function testUsesVectorSimilarityWhenPipelineAvailable(): void
    {
        $user = User::factory()->create();
        $seed = Book::factory()->create(['title' => 'The Seed Book']);
        UserBookStatus::factory()->create([
            'user_id' => $user->id,
            'book_id' => $seed->id,
            'status' => 'completed',
            'finished_at' => now(),
        ]);
        $neighbor = Book::factory()->create();

        $embeddingProvider = Mockery::mock(EmbeddingsProviderInterface::class);
        $embeddingProvider->shouldReceive('embedText')->once()->andReturn([0.1, 0.2]);

        $document = new Document('neighbor text');
        $document->addMetadata('book_id', $neighbor->id);
        $document->setScore(0.9);

        $store = Mockery::mock(VectorStoreInterface::class);
        $store->shouldReceive('similaritySearch')->once()->andReturn([$document]);

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->once()->andReturn(true);
        $pipeline->shouldReceive('resolveEmbeddingProvider')->once()->andReturn($embeddingProvider);
        $pipeline->shouldReceive('resolveVectorStore')->once()->andReturn($store);

        $strategy = new SimilarToRecentBooksStrategy($pipeline, new BookEmbeddingTextBuilder());
        $shelves = $strategy->generate($user);

        $this->assertCount(1, $shelves);
        $this->assertSame('similar_to_book:' . $seed->id, $shelves[0]->shelfKey);
        $this->assertSame('Because you finished The Seed Book', $shelves[0]->title);
        $this->assertSame([['book_id' => $neighbor->id, 'score' => 0.9]], $shelves[0]->books);
    }

    public function testFallsBackToSqlCandidatesWhenPipelineUnavailable(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $seed = Book::factory()->create(['title' => 'The Seed Book']);
        $seed->genres()->attach($genre->id);
        UserBookStatus::factory()->create([
            'user_id' => $user->id,
            'book_id' => $seed->id,
            'status' => 'completed',
            'finished_at' => now(),
        ]);

        $candidate = Book::factory()->create();
        $candidate->genres()->attach($genre->id);

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->once()->andReturn(false);

        $aiProvider = Mockery::mock(\App\Contracts\AI\AIProviderInterface::class);
        $aiProvider->shouldReceive('completeStructured')->andReturn(AIResponse::failure('no key configured'));

        // Partial mock so the AI-ranking step returns a graceful failure instead of
        // making a real network call — SQL order (created_at desc) is used as-is.
        $strategy = Mockery::mock(
            SimilarToRecentBooksStrategy::class,
            [$pipeline, new BookEmbeddingTextBuilder()]
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $strategy->shouldReceive('aiProvider')->andReturn($aiProvider);

        $shelves = $strategy->generate($user);

        $this->assertCount(1, $shelves);
        $this->assertSame([['book_id' => $candidate->id, 'score' => null]], $shelves[0]->books);
    }
}
