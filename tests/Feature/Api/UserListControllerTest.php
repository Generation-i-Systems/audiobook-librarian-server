<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\User;
use App\Models\UserList;
use App\Models\UserListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserListControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $this->book = Book::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($this->user);
    }

    private function headers(): array
    {
        return ['X-Acting-As-Test' => '1'];
    }

    public function test_can_create_a_list(): void
    {
        $stringId = (string) \Illuminate\Support\Str::uuid();

        $response = $this->postJson('/api/v1/lists', [
            'string_id'  => $stringId,
            'name'       => 'Read Queue',
            'is_default' => true,
            'created_at' => 1000,
            'updated_at' => 1000,
        ], $this->headers())->assertStatus(201);

        $response->assertJson([
            'list' => [
                'id'         => $stringId,
                'name'       => 'Read Queue',
                'is_default' => true,
            ],
        ]);

        $this->assertDatabaseHas('user_lists', [
            'user_id'   => $this->user->id,
            'string_id' => $stringId,
            'name'      => 'Read Queue',
        ]);
    }

    public function test_posting_the_same_string_id_twice_updates_instead_of_duplicating(): void
    {
        $stringId = (string) \Illuminate\Support\Str::uuid();
        $payload = ['string_id' => $stringId, 'name' => 'Original', 'created_at' => 1000, 'updated_at' => 1000];

        $this->postJson('/api/v1/lists', $payload, $this->headers())->assertStatus(201);
        $this->postJson('/api/v1/lists', [...$payload, 'name' => 'Renamed', 'updated_at' => 2000], $this->headers())->assertStatus(201);

        $this->assertDatabaseCount('user_lists', 1);
        $this->assertDatabaseHas('user_lists', ['string_id' => $stringId, 'name' => 'Renamed']);
    }

    public function test_index_returns_only_the_authenticated_users_lists(): void
    {
        $otherUser = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        UserList::create(['user_id' => $otherUser->id, 'string_id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Not Mine', 'is_default' => false, 'created_at' => 1, 'updated_at' => 1]);
        $mine = UserList::create(['user_id' => $this->user->id, 'string_id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Mine', 'is_default' => false, 'created_at' => 1, 'updated_at' => 1]);

        $response = $this->getJson('/api/v1/lists', $this->headers())->assertStatus(200);

        $names = collect($response->json('lists'))->pluck('name');
        $this->assertEquals(['Mine'], $names->all());
    }

    public function test_cannot_rename_another_users_list(): void
    {
        $otherUser = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $list = UserList::create(['user_id' => $otherUser->id, 'string_id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Not Mine', 'is_default' => false, 'created_at' => 1, 'updated_at' => 1]);

        $this->patchJson('/api/v1/lists/' . $list->string_id, ['name' => 'Hijacked'], $this->headers())
            ->assertStatus(403);
    }

    public function test_can_add_and_remove_a_book_from_a_list(): void
    {
        $list = UserList::create(['user_id' => $this->user->id, 'string_id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'My List', 'is_default' => false, 'created_at' => 1, 'updated_at' => 1]);

        $this->postJson('/api/v1/lists/' . $list->string_id . '/items', [
            'book_id'  => $this->book->id,
            'added_at' => 5000,
        ], $this->headers())->assertStatus(201);

        $this->assertDatabaseHas('user_list_items', ['user_list_id' => $list->id, 'book_id' => $this->book->id]);

        $items = $this->getJson('/api/v1/lists/' . $list->string_id . '/items', $this->headers())
            ->assertStatus(200)
            ->json('items');
        $this->assertCount(1, $items);
        $this->assertEquals($this->book->id, $items[0]['book_id']);

        $this->deleteJson('/api/v1/lists/' . $list->string_id . '/items/' . $this->book->id, [], $this->headers())
            ->assertStatus(200);

        $this->assertDatabaseMissing('user_list_items', ['user_list_id' => $list->id, 'book_id' => $this->book->id]);
    }

    public function test_deleting_a_list_cascades_its_items(): void
    {
        $list = UserList::create(['user_id' => $this->user->id, 'string_id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'My List', 'is_default' => false, 'created_at' => 1, 'updated_at' => 1]);
        UserListItem::create(['user_list_id' => $list->id, 'book_id' => $this->book->id, 'added_at' => 1]);

        $this->deleteJson('/api/v1/lists/' . $list->string_id, [], $this->headers())->assertStatus(200);

        $this->assertDatabaseMissing('user_lists', ['id' => $list->id]);
        $this->assertDatabaseMissing('user_list_items', ['user_list_id' => $list->id]);
    }
}
