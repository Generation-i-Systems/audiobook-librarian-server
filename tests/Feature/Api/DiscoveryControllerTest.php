<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BlockedEntity;
use App\Models\BookTag;
use App\Models\Genre;
use App\Models\RecommendationShelf;
use App\Models\Series;
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

    public function testUserCanDismissARecommendationShelf(): void
    {
        $shelf = RecommendationShelf::create([
            'user_id' => $this->user->id,
            'shelf_key' => 'genre_affinity:1',
            'title' => 'More in Fantasy',
            'sort_order' => 0,
            'computed_at' => now(),
        ]);

        $this->deleteJson('/api/v1/discovery/shelves/' . $shelf->shelf_key)
            ->assertOk()
            ->assertJson(['message' => 'Recommendation dismissed']);

        $this->assertDatabaseHas('recommendation_shelf_dismissals', [
            'user_id' => $this->user->id,
            'shelf_key' => $shelf->shelf_key,
        ]);
        $this->assertDatabaseMissing('recommendation_shelves', ['id' => $shelf->id]);
    }

    public function testDiscoveryAndGenreBooksExcludeAllOfTheUsersBlockedBookMatches(): void
    {
        $genre = Genre::factory()->create();
        $visible = Book::factory()->create();
        $blockedByBook = Book::factory()->create();
        $blockedByAuthor = Book::factory()->create();
        $blockedBySeries = Book::factory()->create();
        $blockedByTag = Book::factory()->create();
        foreach ([$visible, $blockedByBook, $blockedByAuthor, $blockedBySeries, $blockedByTag] as $book) {
            $book->genres()->attach($genre);
        }

        $author = \App\Models\Author::factory()->create();
        $blockedByAuthor->authors()->attach($author);
        $series = Series::factory()->create();
        $blockedBySeries->series()->attach($series, ['series_number' => 1]);
        BookTag::create([
            'book_id' => $blockedByTag->id,
            'user_id' => $this->user->id,
            'scope' => 'user',
            'owner_key' => 'user:' . $this->user->id,
            'tags' => ['not-for-me'],
        ]);

        $this->block(BlockedEntity::TYPE_BOOK, $blockedByBook->id, (string) $blockedByBook->id);
        $this->block(BlockedEntity::TYPE_AUTHOR, $author->id, strtolower($author->name));
        $this->block(BlockedEntity::TYPE_SERIES, $series->id, strtolower($series->name));
        $this->block(BlockedEntity::TYPE_TAG, null, 'not-for-me');

        $shelf = RecommendationShelf::create([
            'user_id' => $this->user->id,
            'shelf_key' => 'new_for_you',
            'title' => 'New for You',
            'sort_order' => 0,
            'computed_at' => now(),
        ]);
        foreach ([$visible, $blockedByBook, $blockedByAuthor, $blockedBySeries, $blockedByTag] as $rank => $book) {
            $shelf->shelfBooks()->create(['book_id' => $book->id, 'rank' => $rank, 'score' => null]);
        }

        $shelves = $this->getJson('/api/v1/discovery/shelves')->assertOk()->json('data.0');
        $this->assertSame([$visible->id], array_column($shelves['books'], 'id'));
        $this->assertFalse($shelves['has_more']);

        $shelfBooks = $this->getJson('/api/v1/discovery/shelves/new_for_you/books?per_page=10')->assertOk();
        $this->assertSame([$visible->id], array_column($shelfBooks->json('data'), 'id'));
        $this->assertSame(1, $shelfBooks->json('meta.total'));

        $genreBooks = $this->getJson('/api/v1/genres/' . $genre->id . '/books?per_page=10')->assertOk();
        $this->assertSame([$visible->id], array_column($genreBooks->json('data'), 'id'));
        $this->assertSame(1, $genreBooks->json('meta.total'));
    }

    private function block(string $type, ?int $refId, string $value): void
    {
        BlockedEntity::create([
            'user_id' => $this->user->id,
            'string_id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => $type,
            'entity_ref_id' => $refId,
            'entity_value' => $value,
            'entity_label' => $value,
            'created_at' => now()->getTimestampMs(),
        ]);
    }
}
