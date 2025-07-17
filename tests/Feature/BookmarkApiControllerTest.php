<?php

namespace Tests\Feature;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Api\BookmarkApiController;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookmarkApiControllerTest extends TestCase
{
    use WithFaker;

    protected $mockService;

    protected string $userId;

    protected string $bookId;

    protected string $bookmarkId;

    protected array $testBookmark;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = 'user123';
        $this->bookId = 'book123';
        $this->bookmarkId = 'bookmark123';

        $this->testBookmark = [
            '_id' => $this->bookmarkId,
            'user_id' => $this->userId,
            'book_id' => $this->bookId,
            'chapter' => 3,
            'position' => 150,
            'title' => 'Test Bookmark',
            'note' => 'Test note content',
            'created_at' => '2023-01-01T00:00:00Z',
            'updated_at' => '2023-01-01T00:00:00Z',
        ];

        $this->mockService = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->mockService);

        Route::get('/api/v1/books/{book}/bookmarks', [BookmarkApiController::class, 'getBookmarks']);
        Route::post('/api/v1/books/{book}/bookmarks', [BookmarkApiController::class, 'createBookmark']);
        Route::get('/api/v1/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'getBookmark']);
        Route::put('/api/v1/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'updateBookmark']);
        Route::patch('/api/v1/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'updateBookmark']);
        Route::delete('/api/v1/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'deleteBookmark']);

        $this->withoutMiddleware();

        config(['auth.guards.api' => ['driver' => 'token', 'provider' => 'users']]);
        config(['auth.providers.users' => ['driver' => 'eloquent', 'model' => DocumentstoreUser::class]]);

        $user = new DocumentstoreUser(['id' => $this->userId, 'name' => 'Test User', 'email' => 'test@example.com']);
        $this->actingAs($user, 'api');
    }

    #[Test]
    public function get_bookmarks_returns_bookmarks_for_user_and_book()
    {
        $this->mockService->shouldReceive('getBook')->once()->with($this->bookId)->andReturn((object) ['id' => $this->bookId]);
        $this->mockService->shouldReceive('getBookmarks')->once()->with($this->userId, $this->bookId)->andReturn([$this->testBookmark]);

        $response = $this->getJson("/api/v1/books/{$this->bookId}/bookmarks");

        $expectedBookmark = $this->testBookmark;
        $expectedBookmark['id'] = (string) $expectedBookmark['_id'];
        unset($expectedBookmark['_id'], $expectedBookmark['user_id']);

        $response->assertStatus(200)->assertJson(['data' => [$expectedBookmark]]);
    }

    #[Test]
    public function get_bookmarks_returns404_for_nonexistent_book()
    {
        $this->mockService->shouldReceive('getBook')->once()->with('nonexistent-book')->andReturn(null);

        $response = $this->getJson('/api/v1/books/nonexistent-book/bookmarks');
        $response->assertStatus(404);
    }

    #[Test]
    public function create_bookmark_creates_new_bookmark_successfully()
    {
        $this->mockService->shouldReceive('getBook')->once()->with($this->bookId)->andReturn((object) ['id' => $this->bookId]);
        $this->mockService->shouldReceive('createBookmark')->once()->andReturn($this->bookmarkId);

        $bookmarkData = ['chapter' => 3, 'position' => 150, 'title' => 'Test Bookmark', 'note' => 'Test note content'];
        $response = $this->postJson("/api/v1/books/{$this->bookId}/bookmarks", $bookmarkData);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'book_id', 'chapter', 'position', 'title', 'note', 'created_at', 'updated_at'])
            ->assertJsonMissing(['_id', 'user_id']);
    }

    #[Test]
    public function create_bookmark_returns404_for_nonexistent_book()
    {
        $this->mockService->shouldReceive('getBook')->once()->with('nonexistent-book')->andReturn(null);

        $bookmarkData = ['chapter' => 1, 'position' => 1];
        $response = $this->postJson('/api/v1/books/nonexistent-book/bookmarks', $bookmarkData);
        $response->assertStatus(404);
    }

    #[Test]
    public function create_bookmark_validates_input()
    {
        $response = $this->postJson("/api/v1/books/{$this->bookId}/bookmarks", ['chapter' => 'not-an-integer']);
        $response->assertStatus(422);
    }

    #[Test]
    public function get_bookmark_returns_specific_bookmark()
    {
        $this->mockService->shouldReceive('getBookmark')->once()->with($this->bookmarkId, $this->userId, $this->bookId)->andReturn($this->testBookmark);

        $response = $this->getJson("/api/v1/books/{$this->bookId}/bookmarks/{$this->bookmarkId}");

        $expectedBookmark = $this->testBookmark;
        $expectedBookmark['id'] = (string) $expectedBookmark['_id'];
        unset($expectedBookmark['_id'], $expectedBookmark['user_id']);

        $response->assertStatus(200)->assertJson($expectedBookmark);
    }

    #[Test]
    public function get_bookmark_returns404_for_nonexistent_bookmark()
    {
        $this->mockService->shouldReceive('getBookmark')->once()->andReturn(null);

        $response = $this->getJson("/api/v1/books/{$this->bookId}/bookmarks/nonexistent-bookmark");
        $response->assertStatus(404);
    }

    #[Test]
    public function update_bookmark_updates_bookmark_successfully()
    {
        $updatedBookmark = array_merge($this->testBookmark, ['chapter' => 4]);
        $this->mockService->shouldReceive('getBookmark')->andReturn($this->testBookmark, $updatedBookmark);
        $this->mockService->shouldReceive('updateBookmark')->once()->andReturn(true);

        $updateData = ['chapter' => 4];
        $response = $this->putJson("/api/v1/books/{$this->bookId}/bookmarks/{$this->bookmarkId}", $updateData);

        $expectedBookmark = $updatedBookmark;
        $expectedBookmark['id'] = (string) $expectedBookmark['_id'];
        unset($expectedBookmark['_id'], $expectedBookmark['user_id']);

        $response->assertStatus(200)->assertJson($expectedBookmark);
    }

    #[Test]
    public function update_bookmark_returns404_for_nonexistent_bookmark()
    {
        $this->mockService->shouldReceive('getBookmark')->once()->andReturn(null);

        $response = $this->putJson("/api/v1/books/{$this->bookId}/bookmarks/nonexistent-bookmark", []);
        $response->assertStatus(404);
    }

    #[Test]
    public function update_bookmark_validates_input()
    {
        $response = $this->putJson("/api/v1/books/{$this->bookId}/bookmarks/{$this->bookmarkId}", ['chapter' => 'not-an-integer']);
        $response->assertStatus(422);
    }

    #[Test]
    public function delete_bookmark_deletes_bookmark_successfully()
    {
        $this->mockService->shouldReceive('deleteBookmark')->once()->with($this->bookmarkId, $this->userId, $this->bookId)->andReturn(true);

        $response = $this->deleteJson("/api/v1/books/{$this->bookId}/bookmarks/{$this->bookmarkId}");
        $response->assertStatus(204);
    }

    #[Test]
    public function delete_bookmark_returns404_for_nonexistent_bookmark()
    {
        $this->mockService->shouldReceive('deleteBookmark')->once()->andReturn(false);

        $response = $this->deleteJson("/api/v1/books/{$this->bookId}/bookmarks/nonexistent-bookmark");
        $response->assertStatus(404);
    }
}
