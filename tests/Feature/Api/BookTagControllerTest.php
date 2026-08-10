<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use App\Models\BookTag;
use App\Models\Group;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class BookTagControllerTest extends ApiTestCase
{
    public function test_it_stores_and_returns_user_scope_tags_for_a_book(): void
    {
        $book = Book::factory()->create([
            'directory_exists' => true,
            'needs_review' => false,
        ]);

        $response = $this->putJson('/api/v1/books/' . $book->id . '/tags', [
            'scope' => 'user',
            'tags' => ['Series', '  sci-fi ', 'Series', ''],
        ]);

        $response->assertOk();
        $response->assertJson([
            'bookId' => $book->id,
            'scope' => 'user',
            'tags' => ['Series', 'sci-fi'],
        ]);

        $this->assertDatabaseHas('book_tags', [
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'scope' => 'user',
            'owner_key' => 'user:' . $this->user->id,
        ]);

        $showResponse = $this->getJson('/api/v1/books/' . $book->id . '/tags');

        $showResponse->assertOk();
        $showResponse->assertJson([
            'bookId' => $book->id,
            'system' => [],
            'groups' => [],
            'user' => ['Series', 'sci-fi'],
        ]);
    }

    public function test_user_scope_tags_are_returned_when_fetching_the_book(): void
    {
        $book = Book::factory()->create([
            'directory_exists' => true,
            'needs_review' => false,
        ]);

        $this->putJson('/api/v1/books/' . $book->id . '/tags', [
            'scope' => 'user',
            'tags' => ['Favorites'],
        ])->assertOk();

        $response = $this->getJson('/api/v1/books/' . $book->id);

        $response->assertOk();
        $response->assertJsonPath('user_data.tags', ['Favorites']);
    }

    public function test_non_admin_cannot_set_system_tags(): void
    {
        $book = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        $response = $this->putJson('/api/v1/books/' . $book->id . '/tags', [
            'scope' => 'system',
            'tags' => ['staff-pick'],
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_set_system_tags_visible_to_everyone(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $book = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        $this->putJson('/api/v1/books/' . $book->id . '/tags', [
            'scope' => 'system',
            'tags' => ['staff-pick'],
        ])->assertOk();

        // A different, non-admin user can see the system tag.
        Sanctum::actingAs($this->user);
        $showResponse = $this->getJson('/api/v1/books/' . $book->id . '/tags');
        $showResponse->assertOk();
        $showResponse->assertJsonPath('system', ['staff-pick']);
    }

    public function test_non_member_cannot_set_group_tags(): void
    {
        $group = Group::query()->create(['name' => 'Book Club']);
        $book = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        $response = $this->putJson('/api/v1/books/' . $book->id . '/tags', [
            'scope' => 'group',
            'group_id' => $group->id,
            'tags' => ['book-club-pick'],
        ]);

        $response->assertStatus(403);
    }

    public function test_group_member_can_set_group_tags_invisible_to_non_members(): void
    {
        $group = Group::query()->create(['name' => 'Book Club']);
        $group->members()->attach($this->user->id);
        $book = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        $this->putJson('/api/v1/books/' . $book->id . '/tags', [
            'scope' => 'group',
            'group_id' => $group->id,
            'tags' => ['book-club-pick'],
        ])->assertOk();

        $showResponse = $this->getJson('/api/v1/books/' . $book->id . '/tags');
        $showResponse->assertOk();
        $showResponse->assertJson([
            'groups' => [
                ['groupId' => $group->id, 'groupName' => 'Book Club', 'tags' => ['book-club-pick']],
            ],
        ]);

        // A non-member sees nothing from this group.
        $outsider = User::factory()->create(['role' => 'library-user']);
        Sanctum::actingAs($outsider);
        $outsiderResponse = $this->getJson('/api/v1/books/' . $book->id . '/tags');
        $outsiderResponse->assertOk();
        $outsiderResponse->assertJson(['groups' => []]);
    }

    public function test_popular_tags_only_aggregates_system_scope(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $bookA = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);
        $bookB = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        $this->putJson('/api/v1/books/' . $bookA->id . '/tags', [
            'scope' => 'system',
            'tags' => ['staff-pick'],
        ])->assertOk();
        $this->putJson('/api/v1/books/' . $bookB->id . '/tags', [
            'scope' => 'system',
            'tags' => ['staff-pick', 'award-winner'],
        ])->assertOk();

        // A private user tag must never leak into the popular list.
        Sanctum::actingAs($this->user);
        $this->putJson('/api/v1/books/' . $bookA->id . '/tags', [
            'scope' => 'user',
            'tags' => ['my-secret-tag'],
        ])->assertOk();

        $response = $this->getJson('/api/v1/tags/popular');

        $response->assertOk();
        $response->assertJsonPath('tags.0', 'staff-pick');
        $response->assertJsonCount(2, 'tags');
        $response->assertJsonMissing(['tags' => ['my-secret-tag']]);
    }

    public function test_it_filters_books_by_tag_for_the_authenticated_user(): void
    {
        $matchingBook = Book::factory()->create([
            'directory_exists' => true,
            'needs_review' => false,
            'title' => 'Matching Book',
        ]);
        $nonMatchingBook = Book::factory()->create([
            'directory_exists' => true,
            'needs_review' => false,
            'title' => 'Other Book',
        ]);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $matchingBook->id,
            'tags' => ['Queue', 'Favorites'],
        ]);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $nonMatchingBook->id,
            'tags' => ['Later'],
        ]);

        /** @var DocumentStoreServiceInterface $documentStore */
        $documentStore = app(DocumentStoreServiceInterface::class);
        $result = $documentStore->listBooks(
            1,
            24,
            ['tag' => 'Queue'],
            true,
            'title',
            'asc',
            false,
            $this->user->id
        );

        $this->assertCount(1, $result['data']);
        $this->assertSame($matchingBook->id, $result['data'][0]['id']);
        $this->assertSame(['Queue', 'Favorites'], $result['data'][0]['userTags']);
    }
}
