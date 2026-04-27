<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Bookmark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookmarkApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'role' => 'library-user',
        ]);
        $this->book = Book::factory()->create();
    }

    /**
     * Test getting bookmarks for a book.
     */
    public function test_get_bookmarks(): void
    {
        Bookmark::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'title' => 'Test Bookmark',
            'notes' => 'This is a test note',
            'is_auto' => false,
        ]);

        $response = $this->actingAs($this->user, 'web')
            ->getJson("/api/v1/bookmarks/{$this->book->id}");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'bookmarks')
            ->assertJsonStructure([
                'bookmarks' => [
                    '*' => [
                        'id',
                        'book_id',
                        'position_ms',
                        'title',
                        'note',
                        'is_auto',
                        'created_at',
                    ]
                ]
            ]);

        $this->assertEquals('This is a test note', $response->json('bookmarks.0.note'));
    }

    /**
     * Test creating a bookmark.
     */
    public function test_create_bookmark(): void
    {
        $payload = [
            'position_ms' => 120000,
            'title' => 'New Bookmark',
            'note' => 'Plot twist at 2 minutes',
            'is_auto' => false,
        ];

        $response = $this->actingAs($this->user, 'web')
            ->postJson("/api/v1/bookmarks/{$this->book->id}", $payload);

        $response->assertStatus(201)
            ->assertJson([
                'book_id' => $this->book->id,
                'position_ms' => 120000,
                'title' => 'New Bookmark',
                'note' => 'Plot twist at 2 minutes',
                'is_auto' => false,
            ]);

        $this->assertDatabaseHas('bookmarks', [
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'position' => 120,
            'title' => 'New Bookmark',
            'notes' => 'Plot twist at 2 minutes',
            'is_auto' => false,
        ]);
    }

    /**
     * Test character mapping and compatibility.
     */
    public function test_create_bookmark_minimal(): void
    {
        $payload = [
            'position_ms' => 60000,
        ];

        $response = $this->actingAs($this->user, 'web')
            ->postJson("/api/v1/bookmarks/{$this->book->id}", $payload);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'book_id', 'position_ms', 'created_at']);

        $this->assertDatabaseHas('bookmarks', [
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'position' => 60,
            'chapter' => '1'
        ]);
    }

    /**
     * Test 404 for non-existent book.
     */
    public function test_get_bookmarks_not_found(): void
    {
        $response = $this->actingAs($this->user, 'web')
            ->getJson("/api/v1/bookmarks/9999");

        $response->assertStatus(404);
    }
}
