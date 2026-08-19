<?php

declare(strict_types=1);

namespace Tests\Web\Feature;

use App\Models\Book;
use App\Models\BookTag;
use App\Models\Genre;
use App\Models\User;
use App\Models\UserTagFilter;
use App\Services\Search\SemanticBookSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookControllerJsonIndexTagAndSemanticTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->create(['role' => 'library-user']);
        $this->actingAs($this->user);
    }

    #[Test]
    public function jsonIndexSupportsTagFilterParam(): void
    {
        $tagged = Book::factory()->create(['title' => 'Tagged Book']);
        Book::factory()->create(['title' => 'Untagged Book']);

        BookTag::create([
            'book_id' => $tagged->id,
            'scope' => 'system',
            'owner_key' => 'system',
            'tags' => ['funny'],
        ]);

        $response = $this->getJson('http://localhost/api/books/json?tag=funny');

        $response->assertOk();
        $titles = array_column($response->json('books'), 'title');
        $this->assertSame(['Tagged Book'], $titles);
    }

    #[Test]
    public function jsonIndexAppliesPerUserTagBanRule(): void
    {
        $banned = Book::factory()->create(['title' => 'Banned Book']);
        $allowed = Book::factory()->create(['title' => 'Allowed Book']);

        BookTag::create([
            'book_id' => $banned->id,
            'scope' => 'system',
            'owner_key' => 'system',
            'tags' => ['spicy'],
        ]);

        UserTagFilter::create([
            'user_id' => $this->user->id,
            'tag' => 'spicy',
            'mode' => 'ban',
        ]);

        $response = $this->getJson('http://localhost/api/books/json');

        $response->assertOk();
        $titles = array_column($response->json('books'), 'title');
        $this->assertNotContains('Banned Book', $titles);
        $this->assertContains('Allowed Book', $titles);
    }

    #[Test]
    public function jsonIndexSupportsNameTokens(): void
    {
        $genre = Genre::factory()->create(['name' => 'Fantasy']);
        $matching = Book::factory()->create(['title' => 'Mistborn']);
        $matching->genres()->attach($genre->id);
        Book::factory()->create(['title' => 'Other Book']);

        $response = $this->getJson('http://localhost/api/books/json?search=' . urlencode('genre:Fantasy'));

        $response->assertOk();
        $titles = array_column($response->json('books'), 'title');
        $this->assertContains('Mistborn', $titles);
        $this->assertNotContains('Other Book', $titles);
    }

    #[Test]
    public function jsonIndexSupportsSemanticFlag(): void
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

        $response = $this->getJson(
            'http://localhost/api/books/json?search=' . urlencode('space adventure') . '&semantic=true'
        );

        $response->assertOk();
        $ids = array_map('intval', array_column($response->json('books'), 'id'));
        $this->assertSame([$second->id, $first->id], $ids);
    }
}
