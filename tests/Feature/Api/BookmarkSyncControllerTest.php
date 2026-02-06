<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Bookmark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Str;

class BookmarkSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $token;
    protected $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->token = $this->user->createToken('test-token')->plainTextToken;
        $this->book = Book::factory()->create();

        Sanctum::actingAs($this->user);
    }

    public function testGetBookmarksReturnsEmptyForNewUser()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson('/api/v1/sync/bookmarks');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'server_timestamp',
                'bookmarks',
            ]);

        $this->assertEmpty($response->json('bookmarks'));
    }

    public function testGetBookmarksReturnsUserBookmarks()
    {
        $stringId = Str::uuid()->toString();

        Bookmark::create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'string_id' => $stringId,
            'device_id' => 'device-1',
            'device_name' => 'Phone',
            'position_ms' => 1800000,
            'position' => 1800,
            'title' => 'Test Bookmark',
            'notes' => 'Test notes',
            'is_auto' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson('/api/v1/sync/bookmarks');

        $response->assertStatus(200);

        $bookmarks = $response->json('bookmarks');
        $this->assertCount(1, $bookmarks);
        $this->assertEquals($stringId, $bookmarks[0]['string_id']);
        $this->assertEquals(1800000, $bookmarks[0]['position_ms']);
    }

    public function testGetBookmarksIncludesDeletedByDefault()
    {
        $stringId = Str::uuid()->toString();

        $bookmark = Bookmark::create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'string_id' => $stringId,
            'device_id' => 'device-1',
            'device_name' => 'Phone',
            'position_ms' => 1800000,
            'position' => 1800,
        ]);

        $bookmark->delete();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson('/api/v1/sync/bookmarks');

        $response->assertStatus(200);

        $bookmarks = $response->json('bookmarks');
        $this->assertCount(1, $bookmarks);
        $this->assertNotNull($bookmarks[0]['deleted_at']);
    }

    public function testGetBookmarksFiltersBySince()
    {
        $stringId = Str::uuid()->toString();

        Bookmark::create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'string_id' => $stringId,
            'device_id' => 'device-1',
            'device_name' => 'Phone',
            'position_ms' => 1800000,
            'position' => 1800,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $sinceTime = now()->subHour();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson('/api/v1/sync/bookmarks?since=' . $sinceTime->toIso8601String());

        $response->assertStatus(200);
        $this->assertEmpty($response->json('bookmarks'));
    }

    public function testShowBookmarksForSpecificBook()
    {
        $book2 = Book::factory()->create();

        Bookmark::create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'string_id' => Str::uuid()->toString(),
            'device_id' => 'device-1',
            'device_name' => 'Phone',
            'position_ms' => 1800000,
            'position' => 1800,
        ]);

        Bookmark::create([
            'user_id' => $this->user->id,
            'book_id' => $book2->id,
            'string_id' => Str::uuid()->toString(),
            'device_id' => 'device-1',
            'device_name' => 'Phone',
            'position_ms' => 3600000,
            'position' => 3600,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson("/api/v1/sync/bookmarks/{$this->book->id}");

        $response->assertStatus(200);

        $bookmarks = $response->json('bookmarks');
        $this->assertCount(1, $bookmarks);
        $this->assertEquals($this->book->id, $bookmarks[0]['book_id']);
    }

    public function testStoreBookmarkCreatesNewRecord()
    {
        $stringId = Str::uuid()->toString();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-1',
            'X-Device-Name' => 'Test Phone',
        ])
            ->postJson('/api/v1/sync/bookmarks', [
                'client_timestamp' => now()->toIso8601String(),
                'bookmarks' => [
                    [
                        'string_id' => $stringId,
                        'book_id' => $this->book->id,
                        'position_ms' => 1800000,
                        'title' => 'Chapter 5',
                        'note' => 'Important part',
                        'is_auto' => false,
                        'chapter_number' => 5,
                        'chapter_title' => 'The Discovery',
                        'created_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'accepted' => 1,
                'duplicates_skipped' => 0,
            ]);

        $this->assertDatabaseHas('bookmarks', [
            'string_id' => $stringId,
            'book_id' => $this->book->id,
            'device_id' => 'device-1',
            'position_ms' => 1800000,
        ]);
    }

    public function testStoreBookmarkUpdatesExistingRecord()
    {
        $stringId = Str::uuid()->toString();

        Bookmark::create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'string_id' => $stringId,
            'device_id' => 'device-1',
            'device_name' => 'Phone',
            'position_ms' => 1800000,
            'position' => 1800,
            'title' => 'Old Title',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-2',
            'X-Device-Name' => 'Tablet',
        ])
            ->postJson('/api/v1/sync/bookmarks', [
                'client_timestamp' => now()->toIso8601String(),
                'bookmarks' => [
                    [
                        'string_id' => $stringId,
                        'book_id' => $this->book->id,
                        'position_ms' => 3600000,
                        'title' => 'Updated Title',
                        'created_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'accepted' => 0,
                'duplicates_skipped' => 1,
            ]);

        $this->assertDatabaseHas('bookmarks', [
            'string_id' => $stringId,
            'title' => 'Updated Title',
            'position_ms' => 3600000,
            'device_id' => 'device-2',
        ]);
    }

    public function testStoreBookmarkRestoresDeletedBookmark()
    {
        $stringId = Str::uuid()->toString();

        $bookmark = Bookmark::create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'string_id' => $stringId,
            'device_id' => 'device-1',
            'device_name' => 'Phone',
            'position_ms' => 1800000,
            'position' => 1800,
        ]);

        $bookmark->delete();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-1',
            'X-Device-Name' => 'Phone',
        ])
            ->postJson('/api/v1/sync/bookmarks', [
                'client_timestamp' => now()->toIso8601String(),
                'bookmarks' => [
                    [
                        'string_id' => $stringId,
                        'book_id' => $this->book->id,
                        'position_ms' => 1800000,
                        'created_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $bookmark->refresh();
        $this->assertNull($bookmark->deleted_at);
    }

    public function testStoreBookmarkRequiresDeviceHeaders()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->postJson('/api/v1/sync/bookmarks', [
                'client_timestamp' => now()->toIso8601String(),
                'bookmarks' => [
                    [
                        'string_id' => Str::uuid()->toString(),
                        'book_id' => $this->book->id,
                        'position_ms' => 1800000,
                        'created_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'X-Device-ID and X-Device-Name headers are required',
            ]);
    }

    public function testStoreBookmarkValidatesInput()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-1',
            'X-Device-Name' => 'Test Device',
        ])
            ->postJson('/api/v1/sync/bookmarks', [
                'client_timestamp' => now()->toIso8601String(),
                'bookmarks' => [],
            ]);

        $response->assertStatus(422);
    }

    public function testDestroyBookmarkSuccessfully()
    {
        $stringId = Str::uuid()->toString();

        $bookmark = Bookmark::create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'string_id' => $stringId,
            'device_id' => 'device-1',
            'device_name' => 'Phone',
            'position_ms' => 1800000,
            'position' => 1800,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->deleteJson("/api/v1/sync/bookmarks/{$stringId}");

        $response->assertStatus(200)
            ->assertJson([
                'string_id' => $stringId,
            ])
            ->assertJsonStructure([
                'string_id',
                'deleted_at',
            ]);

        $this->assertSoftDeleted('bookmarks', [
            'string_id' => $stringId,
        ]);
    }

    public function testDestroyBookmarkReturns404ForNonExistent()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->deleteJson('/api/v1/sync/bookmarks/' . Str::uuid()->toString());

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'Bookmark not found',
            ]);
    }
}
