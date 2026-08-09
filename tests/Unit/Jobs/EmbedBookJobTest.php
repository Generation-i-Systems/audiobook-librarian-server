<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\EmbedBookJob;
use App\Models\Book;
use App\Models\BookEmbedding;
use App\Models\Genre;
use App\Services\Embeddings\EmbeddingPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use Tests\TestCase;

class EmbedBookJobTest extends TestCase
{
    use RefreshDatabase;

    private string $storeDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeDirectory = sys_get_temp_dir() . '/neuron-embed-job-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storeDirectory)) {
            array_map('unlink', glob($this->storeDirectory . '/*'));
            rmdir($this->storeDirectory);
        }
        parent::tearDown();
    }

    private function fakeVectorStore(): FileVectorStore
    {
        return new FileVectorStore($this->storeDirectory, 5, 'neuron', '.store');
    }

    private function storedDocumentCount(): int
    {
        $path = $this->storeDirectory . '/neuron.store';
        if (!file_exists($path)) {
            return 0;
        }

        return count(array_filter(explode("\n", trim(file_get_contents($path)))));
    }

    public function testHandleSkipsEmbeddingWhenNoEmbeddingProviderConfigured(): void
    {
        $book = Book::factory()->create(['title' => 'A Book With No Provider']);

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->once()->andReturn(false);
        $pipeline->shouldNotReceive('resolveEmbeddingProvider');
        $pipeline->shouldNotReceive('resolveVectorStore');

        (new EmbedBookJob($book->id))->handle($pipeline);

        $this->assertDatabaseCount('book_embeddings', 0);
    }

    public function testHandleDoesNothingWhenBookDoesNotExist(): void
    {
        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldNotReceive('isAvailable');

        (new EmbedBookJob(999999))->handle($pipeline);

        $this->assertDatabaseCount('book_embeddings', 0);
    }

    public function testHandleEmbedsNewBookAndCreatesEmbeddingRecord(): void
    {
        $book = Book::factory()->create(['title' => 'The Test Voyage', 'description' => 'A voyage into testing.']);
        $genre = Genre::factory()->create(['name' => 'Adventure']);
        $book->genres()->attach($genre->id);

        $embeddingProvider = Mockery::mock(EmbeddingsProviderInterface::class);
        $embeddingProvider->shouldReceive('embedText')
            ->once()
            ->with(Mockery::on(fn (string $text) => str_contains($text, 'The Test Voyage') && str_contains($text, 'Adventure')))
            ->andReturn([0.1, 0.2, 0.3]);

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->once()->andReturn(true);
        $pipeline->shouldReceive('resolveEmbeddingProvider')->once()->andReturn($embeddingProvider);
        $pipeline->shouldReceive('resolveVectorStore')->once()->andReturn($this->fakeVectorStore());

        (new EmbedBookJob($book->id))->handle($pipeline);

        $this->assertEquals(1, $this->storedDocumentCount());

        $record = BookEmbedding::where('book_id', $book->id)->first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->embedded_at);
        $this->assertNull($record->cover_hash);
    }

    public function testHandleSkipsReEmbeddingWhenContentHashUnchanged(): void
    {
        $book = Book::factory()->create(['title' => 'Stable Title', 'description' => 'Stable description.']);

        $embeddingProvider = Mockery::mock(EmbeddingsProviderInterface::class);
        $embeddingProvider->shouldReceive('embedText')->once()->andReturn([0.1, 0.2]);

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->twice()->andReturn(true);
        $pipeline->shouldReceive('resolveEmbeddingProvider')->once()->andReturn($embeddingProvider);
        $pipeline->shouldReceive('resolveVectorStore')->once()->andReturn($this->fakeVectorStore());

        (new EmbedBookJob($book->id))->handle($pipeline);
        $this->assertEquals(1, $this->storedDocumentCount());

        // Second run: nothing about the book changed, so the mocked provider/store
        // expectations above (called "once") would fail if handle() re-embedded.
        (new EmbedBookJob($book->id))->handle($pipeline);
        $this->assertEquals(1, $this->storedDocumentCount());
        $this->assertDatabaseCount('book_embeddings', 1);
    }

    public function testHandleForceReEmbedsEvenWhenContentHashUnchanged(): void
    {
        $book = Book::factory()->create(['title' => 'Stable Title', 'description' => 'Stable description.']);

        $embeddingProvider = Mockery::mock(EmbeddingsProviderInterface::class);
        $embeddingProvider->shouldReceive('embedText')->twice()->andReturn([0.1, 0.2]);

        $pipeline = Mockery::mock(EmbeddingPipeline::class);
        $pipeline->shouldReceive('isAvailable')->twice()->andReturn(true);
        $pipeline->shouldReceive('resolveEmbeddingProvider')->twice()->andReturn($embeddingProvider);
        $pipeline->shouldReceive('resolveVectorStore')->twice()->andReturn($this->fakeVectorStore());

        (new EmbedBookJob($book->id))->handle($pipeline);
        (new EmbedBookJob($book->id, force: true))->handle($pipeline);

        $this->assertDatabaseCount('book_embeddings', 1);
    }
}
