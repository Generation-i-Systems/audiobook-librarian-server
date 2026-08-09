<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Services\Embeddings\BookEmbeddingTextBuilder;
use App\Services\Embeddings\EmbeddingPipeline;
use App\Services\Recommendations\Strategies\NewForYouStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Tests\TestCase;

class NewForYouStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function testGeneratesNoShelfWithoutFinishedBooks(): void
    {
        $user = User::factory()->create();
        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldNotReceive('isAvailable');

        $strategy = new NewForYouStrategy($pipeline, new BookEmbeddingTextBuilder());

        $this->assertSame([], $strategy->generate($user));
    }

    public function testBlendsSimilarityAndRecencyViaVectorPath(): void
    {
        $user = User::factory()->create();
        $seed = Book::factory()->create();
        UserBookStatus::factory()->create([
            'user_id' => $user->id,
            'book_id' => $seed->id,
            'status' => 'completed',
            'finished_at' => now(),
        ]);

        $candidate = Book::factory()->create(['created_at' => now()]);

        $embeddingProvider = Mockery::mock(EmbeddingsProviderInterface::class);
        $embeddingProvider->shouldReceive('embedText')->once()->andReturn([0.1, 0.2]);

        $document = new Document('candidate text');
        $document->addMetadata('book_id', $candidate->id);
        $document->setScore(0.8);

        $store = Mockery::mock(VectorStoreInterface::class);
        $store->shouldReceive('similaritySearch')->once()->andReturn([$document]);

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->once()->andReturn(true);
        $pipeline->shouldReceive('resolveEmbeddingProvider')->once()->andReturn($embeddingProvider);
        $pipeline->shouldReceive('resolveVectorStore')->once()->andReturn($store);

        $strategy = new NewForYouStrategy($pipeline, new BookEmbeddingTextBuilder());
        $shelves = $strategy->generate($user);

        $this->assertCount(1, $shelves);
        $this->assertSame('new_for_you', $shelves[0]->shelfKey);
        $this->assertSame('New for You', $shelves[0]->title);
        $this->assertCount(1, $shelves[0]->books);
        $this->assertSame($candidate->id, $shelves[0]->books[0]['book_id']);
        // Blended score = similarity(0.8)*0.6 + recency(~1.0 for brand-new)*0.4, so > raw similarity alone.
        $this->assertGreaterThan(0.48, $shelves[0]->books[0]['score']);
    }

    public function testFallsBackToRecentGenreOverlapWhenPipelineUnavailable(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $seed = Book::factory()->create();
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

        $strategy = new NewForYouStrategy($pipeline, new BookEmbeddingTextBuilder());
        $shelves = $strategy->generate($user);

        $this->assertCount(1, $shelves);
        $this->assertSame([['book_id' => $candidate->id, 'score' => null]], $shelves[0]->books);
    }
}
