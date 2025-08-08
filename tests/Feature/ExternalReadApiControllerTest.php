<?php

namespace Tests\Feature;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Api\ExternalReadApiController;
use Illuminate\Foundation\Testing\WithFaker;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExternalReadApiControllerTest extends TestCase
{
    use WithFaker;

    protected $mockService;

    protected string $userId;

    protected string $bookId;

    protected string $externalReadId;

    protected array $testExternalRead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = 'user123';
        $this->bookId = 'book123';
        $this->externalReadId = 'externalRead123';

        $this->testExternalRead = [
            '_id' => $this->externalReadId,
            'user_id' => $this->userId,
            'book_id' => $this->bookId,
            'origin' => 'external',
            'source' => 'Audible',
            'note' => 'Listened before',
            'started_at' => '2023-01-01T00:00:00Z',
            'finished_at' => '2023-01-02T00:00:00Z',
            'created_at' => '2023-01-02T00:00:00Z',
            'updated_at' => '2023-01-02T00:00:00Z',
        ];

        $this->mockService = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->mockService);

        // Register routes for testing
        \Illuminate\Support\Facades\Route::get('/api/v1/books/{book}/external-reads', [ExternalReadApiController::class, 'getExternalReads']);
        \Illuminate\Support\Facades\Route::post('/api/v1/books/{book}/external-reads', [ExternalReadApiController::class, 'createExternalRead']);
        \Illuminate\Support\Facades\Route::get('/api/v1/books/{book}/external-reads/{externalRead}', [ExternalReadApiController::class, 'getExternalRead']);
        \Illuminate\Support\Facades\Route::put('/api/v1/books/{book}/external-reads/{externalRead}', [ExternalReadApiController::class, 'updateExternalRead']);
        \Illuminate\Support\Facades\Route::patch('/api/v1/books/{book}/external-reads/{externalRead}', [ExternalReadApiController::class, 'updateExternalRead']);
        \Illuminate\Support\Facades\Route::delete('/api/v1/books/{book}/external-reads/{externalRead}', [ExternalReadApiController::class, 'deleteExternalRead']);

        $this->withoutMiddleware();

        config(['auth.guards.api' => ['driver' => 'token', 'provider' => 'users']]);
        config(['auth.providers.users' => ['driver' => 'eloquent', 'model' => DocumentstoreUser::class]]);

        $user = new DocumentstoreUser(['id' => $this->userId, 'name' => 'Test User', 'email' => 'test@example.com']);
        $this->actingAs($user, 'api');
    }

    #[Test]
    public function get_external_reads_returns_entries_for_user_and_book()
    {
        $this->mockService->shouldReceive('getBook')->once()->with($this->bookId)->andReturn((object) ['id' => $this->bookId]);
        $this->mockService->shouldReceive('getExternalReads')->once()->with($this->userId, $this->bookId)->andReturn([$this->testExternalRead]);

        $response = $this->getJson("/api/v1/books/{$this->bookId}/external-reads");

        $expected = [
            'id' => (string) $this->externalReadId,
            'book_id' => $this->bookId,
            'origin' => 'external',
            'source' => 'Audible',
            'note' => 'Listened before',
            'started_at' => '2023-01-01T00:00:00Z',
            'finished_at' => '2023-01-02T00:00:00Z',
            'created_at' => '2023-01-02T00:00:00Z',
            'updated_at' => '2023-01-02T00:00:00Z',
        ];

        $response->assertStatus(200)->assertJson(['data' => [$expected]]);
    }

    #[Test]
    public function get_external_reads_returns404_for_nonexistent_book()
    {
        $this->mockService->shouldReceive('getBook')->once()->with('nonexistent-book')->andReturn(null);

        $response = $this->getJson('/api/v1/books/nonexistent-book/external-reads');
        $response->assertStatus(404);
    }

    #[Test]
    public function create_external_read_creates_new_entry_successfully()
    {
        $this->mockService->shouldReceive('getBook')->once()->with($this->bookId)->andReturn((object) ['id' => $this->bookId]);
        $this->mockService->shouldReceive('createExternalRead')->once()->andReturn($this->externalReadId);
        $this->mockService->shouldReceive('getExternalRead')->once()->with((string) $this->externalReadId, $this->userId, $this->bookId)->andReturn($this->testExternalRead);

        $payload = [
            'origin' => 'previous',
            'source' => 'Kindle',
            'note' => 'Read the hardcover years ago',
            'started_at' => '2023-01-01T00:00:00Z',
            'finished_at' => '2023-01-02T00:00:00Z',
        ];

        $response = $this->postJson("/api/v1/books/{$this->bookId}/external-reads", $payload);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'book_id', 'origin', 'source', 'note', 'started_at', 'finished_at', 'created_at', 'updated_at'])
            ->assertJsonMissing(['_id', 'user_id']);
    }

    #[Test]
    public function create_external_read_validates_input()
    {
        $response = $this->postJson("/api/v1/books/{$this->bookId}/external-reads", ['origin' => 'invalid']);
        $response->assertStatus(422);
    }

    #[Test]
    public function get_external_read_returns_specific_entry()
    {
        $this->mockService->shouldReceive('getExternalRead')->once()->with($this->externalReadId, $this->userId, $this->bookId)->andReturn($this->testExternalRead);

        $response = $this->getJson("/api/v1/books/{$this->bookId}/external-reads/{$this->externalReadId}");

        $expected = [
            'id' => (string) $this->externalReadId,
            'book_id' => $this->bookId,
            'origin' => 'external',
            'source' => 'Audible',
            'note' => 'Listened before',
            'started_at' => '2023-01-01T00:00:00Z',
            'finished_at' => '2023-01-02T00:00:00Z',
            'created_at' => '2023-01-02T00:00:00Z',
            'updated_at' => '2023-01-02T00:00:00Z',
        ];

        $response->assertStatus(200)->assertJson($expected);
    }

    #[Test]
    public function get_external_read_returns404_for_nonexistent_entry()
    {
        $this->mockService->shouldReceive('getExternalRead')->once()->andReturn(null);

        $response = $this->getJson("/api/v1/books/{$this->bookId}/external-reads/nonexistent");
        $response->assertStatus(404);
    }

    #[Test]
    public function update_external_read_updates_entry_successfully()
    {
        $updated = array_merge($this->testExternalRead, ['note' => 'Updated note']);
        $this->mockService->shouldReceive('getExternalRead')->andReturn($this->testExternalRead, $updated);
        $this->mockService->shouldReceive('updateExternalRead')->once()->andReturn(true);

        $payload = ['note' => 'Updated note'];
        $response = $this->putJson("/api/v1/books/{$this->bookId}/external-reads/{$this->externalReadId}", $payload);

        $expected = [
            'id' => (string) $this->externalReadId,
            'book_id' => $this->bookId,
            'origin' => 'external',
            'source' => 'Audible',
            'note' => 'Updated note',
            'started_at' => '2023-01-01T00:00:00Z',
            'finished_at' => '2023-01-02T00:00:00Z',
            'created_at' => '2023-01-02T00:00:00Z',
            'updated_at' => '2023-01-02T00:00:00Z',
        ];

        $response->assertStatus(200)->assertJson($expected);
    }

    #[Test]
    public function update_external_read_returns404_for_nonexistent_entry()
    {
        $this->mockService->shouldReceive('getExternalRead')->once()->andReturn(null);

        $response = $this->putJson("/api/v1/books/{$this->bookId}/external-reads/nonexistent", []);
        $response->assertStatus(404);
    }

    #[Test]
    public function update_external_read_validates_input()
    {
        $response = $this->putJson("/api/v1/books/{$this->bookId}/external-reads/{$this->externalReadId}", ['origin' => 'invalid']);
        $response->assertStatus(422);
    }

    #[Test]
    public function delete_external_read_deletes_entry_successfully()
    {
        $this->mockService->shouldReceive('deleteExternalRead')->once()->with($this->externalReadId, $this->userId, $this->bookId)->andReturn(true);

        $response = $this->deleteJson("/api/v1/books/{$this->bookId}/external-reads/{$this->externalReadId}");
        $response->assertStatus(204);
    }

    #[Test]
    public function delete_external_read_returns404_for_nonexistent_entry()
    {
        $this->mockService->shouldReceive('deleteExternalRead')->once()->andReturn(false);

        $response = $this->deleteJson("/api/v1/books/{$this->bookId}/external-reads/nonexistent");
        $response->assertStatus(404);
    }
}
