<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookTag;
use App\Models\Genre;
use App\Services\Embeddings\EmbeddingPipeline;
use App\Services\Search\SemanticBookSearchService;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class BookSearchTokenAndSemanticTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('books');
        $this->withoutMiddleware();
    }

    #[Test]
    public function genreNameTokenFiltersBooksByGenre(): void
    {
        $fantasyGenre = Genre::factory()->create(['name' => 'Fantasy']);
        $matching = Book::factory()->create(['title' => 'Mistborn']);
        $matching->genres()->attach($fantasyGenre->id);
        Book::factory()->create(['title' => 'Other Book']);

        $response = $this->getJson('/api/v1/books?search=' . urlencode('genre:Fantasy'));

        $response->assertOk();
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('Mistborn', $titles);
        $this->assertNotContains('Other Book', $titles);
    }

    #[Test]
    public function quotedMultiWordAuthorTokenFiltersBooks(): void
    {
        $book = Book::factory()->create(['title' => 'The Way of Kings']);
        $book->authors()->create(['name' => 'Brandon Sanderson']);
        Book::factory()->create(['title' => 'Unrelated Book']);

        $response = $this->getJson('/api/v1/books?search=' . urlencode('author:"Brandon Sanderson"'));

        $response->assertOk();
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('The Way of Kings', $titles);
        $this->assertNotContains('Unrelated Book', $titles);
    }

    #[Test]
    public function tagParamStillFiltersBooksAfterTokenWiring(): void
    {
        $tagged = Book::factory()->create(['title' => 'Tagged Book']);
        Book::factory()->create(['title' => 'Untagged Book']);

        BookTag::create([
            'book_id' => $tagged->id,
            'scope' => 'system',
            'owner_key' => 'system',
            'tags' => ['funny'],
        ]);

        $response = $this->getJson('/api/v1/books?tag=funny');

        $response->assertOk();
        $titles = array_column($response->json('data'), 'title');
        $this->assertSame(['Tagged Book'], $titles);
    }

    #[Test]
    public function semanticFlagRanksResultsUsingMockedEmbeddingPipeline(): void
    {
        $first = Book::factory()->create(['title' => 'Alpha Result']);
        $second = Book::factory()->create(['title' => 'Beta Result']);
        Book::factory()->create(['title' => 'Not Ranked']);

        $semanticService = Mockery::mock(SemanticBookSearchService::class);
        $semanticService->shouldReceive('rankedBookIds')
            ->once()
            ->with('space adventure')
            ->andReturn([$second->id, $first->id]);
        $this->app->instance(SemanticBookSearchService::class, $semanticService);

        $response = $this->getJson('/api/v1/books?search=' . urlencode('space adventure') . '&semantic=true');

        $response->assertOk();
        $ids = array_map('intval', array_column($response->json('data'), 'id'));
        $this->assertSame([$second->id, $first->id], $ids);
    }

    #[Test]
    public function semanticFlagFallsBackToNormalSearchWhenPipelineUnavailable(): void
    {
        $book = Book::factory()->create(['title' => 'Findable By Text']);

        $semanticService = new SemanticBookSearchService(new EmbeddingPipeline());
        $this->app->instance(SemanticBookSearchService::class, $semanticService);
        config(['neuron.embedding.default' => null]);

        $response = $this->getJson('/api/v1/books?search=' . urlencode('Findable By Text') . '&semantic=true');

        $response->assertOk();
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('Findable By Text', $titles);
    }

    #[Test]
    public function searchEndpointAlsoSupportsNameTokensAndSemanticFlag(): void
    {
        $book = Book::factory()->create(['title' => 'Tokened Series Book']);
        $series = \App\Models\Series::factory()->create(['name' => 'Great Series']);
        $book->series()->attach($series->id, ['series_number' => '1']);
        Book::factory()->create(['title' => 'Unrelated']);

        $response = $this->getJson('/api/v1/books/search?search=' . urlencode('series:"Great Series"'));

        $response->assertOk();
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('Tokened Series Book', $titles);
        $this->assertNotContains('Unrelated', $titles);
    }
}
