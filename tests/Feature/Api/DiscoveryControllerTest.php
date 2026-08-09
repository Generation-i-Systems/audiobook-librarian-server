<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\RecommendationShelf;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiscoveryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now(), 'role' => 'library-user']);
        Sanctum::actingAs($this->user);
    }

    public function testShelvesReturnsEmptyListWhenUserHasNoCachedShelves(): void
    {
        $response = $this->getJson('/api/v1/discovery/shelves');

        $response->assertOk()->assertJson(['data' => []]);
    }

    public function testShelvesReturnsCachedShelvesInSortOrderWithBooks(): void
    {
        $book = Book::factory()->create();
        $shelf = RecommendationShelf::create([
            'user_id' => $this->user->id,
            'shelf_key' => 'genre_affinity:1',
            'title' => 'More in Fantasy',
            'sort_order' => 0,
            'computed_at' => now(),
        ]);
        $shelf->shelfBooks()->create(['book_id' => $book->id, 'rank' => 0, 'score' => null]);

        $response = $this->getJson('/api/v1/discovery/shelves');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('genre_affinity:1', $data[0]['shelf_key']);
        $this->assertSame('More in Fantasy', $data[0]['title']);
        $this->assertFalse($data[0]['has_more']);
        $this->assertCount(1, $data[0]['books']);
        $this->assertSame($book->id, $data[0]['books'][0]['id']);
    }

    public function testShelvesOnlyReturnsCurrentUsersShelves(): void
    {
        $otherUser = User::factory()->create();
        RecommendationShelf::create([
            'user_id' => $otherUser->id,
            'shelf_key' => 'new_for_you',
            'title' => 'New for You',
            'sort_order' => 0,
            'computed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/discovery/shelves');

        $response->assertOk()->assertJson(['data' => []]);
    }

    public function testShelfBooksPaginatesASingleShelf(): void
    {
        $shelf = RecommendationShelf::create([
            'user_id' => $this->user->id,
            'shelf_key' => 'new_for_you',
            'title' => 'New for You',
            'sort_order' => 0,
            'computed_at' => now(),
        ]);

        $books = Book::factory()->count(3)->create();
        foreach ($books as $rank => $book) {
            $shelf->shelfBooks()->create(['book_id' => $book->id, 'rank' => $rank, 'score' => null]);
        }

        $response = $this->getJson('/api/v1/discovery/shelves/new_for_you/books?page=1&per_page=2');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function testShelfBooksReturnsEmptyForUnknownShelfKey(): void
    {
        $response = $this->getJson('/api/v1/discovery/shelves/does_not_exist/books');

        $response->assertOk()->assertJson(['data' => [], 'meta' => ['total' => 0]]);
    }
}
