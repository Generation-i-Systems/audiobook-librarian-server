<?php

namespace Tests\Unit\Services\AI;

use App\Contracts\AI\AIProviderInterface;
use App\Contracts\AI\AIResponse;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use App\Services\AI\AIAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * @phpstan-ignore-next-line method.notFound,property.nonObject
 */
class AIAssistantServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AIAssistantService $service;
    /** @var AIProviderInterface&Mockery\MockInterface */
    protected AIProviderInterface $mockProvider;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Create a mock provider
        $this->mockProvider = Mockery::mock(AIProviderInterface::class);
        $this->mockProvider->shouldReceive('getName')->andReturn('test');
        $this->mockProvider->shouldReceive('getModel')->andReturn('test-model');
        $this->mockProvider->shouldReceive('canMakeRequest')->andReturn(true);
        $this->mockProvider->shouldReceive('getUsageStats')->andReturn([
            'session_cost' => 0.0,
            'requests_this_minute' => 0,
            'requests_today' => 0,
            'rate_limits' => [
                'requests_per_minute' => 100,
                'requests_per_day' => null,
            ],
            'pricing' => [
                'input_per_million' => 0.10,
                'output_per_million' => 0.40,
            ],
        ]);

        $this->mockProvider->shouldReceive('completeStructured')->byDefault()
            ->andReturn(AIResponse::successWithData([
                'intent' => 'search',
                'parameters' => [],
                'explanation' => 'Generic search',
                'confidence' => 0.9,
            ]));
        $this->mockProvider->shouldReceive('complete')->byDefault()
            ->andReturn(AIResponse::success('Generic response'));

        $this->service = new AIAssistantService();

        // Inject the mock provider using reflection
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('provider');
        $property->setAccessible(true);
        $property->setValue($this->service, $this->mockProvider);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testProcessRequestCreatesNewSession(): void
    {
        // Create test books
        $book = Book::factory()->create(['title' => 'The Way of Kings']);
        $author = Author::factory()->create(['name' => 'Brandon Sanderson']);
        $genre = Genre::factory()->create(['name' => 'Fantasy']);
        $book->authors()->attach($author);
        $book->genres()->attach($genre);

        $userId = $this->user->id;
        $message = 'Find all fantasy books';

        // Mock the AI provider's responses
        $this->mockProvider->shouldReceive('completeStructured')
            ->andReturn(AIResponse::successWithData([
                'intent' => 'search',
                'parameters' => ['genre' => 'Fantasy'],
                'explanation' => 'Searching for fantasy books',
                'confidence' => 0.9,
            ]));

        $result = $this->service->processRequest($message, null, $userId);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('session_id', $result);
        $this->assertArrayHasKey('intent', $result);
        $this->assertArrayHasKey('explanation', $result);
        $this->assertArrayHasKey('operations', $result);

        $this->assertDatabaseHas('ai_assistant_sessions', [
            'user_id' => $userId,
            'status' => 'pending_approval',
        ]);
    }

    public function testProcessRequestContinuesExistingSession(): void
    {
        $userId = $this->user->id;

        // Create a book to avoid "no books found" error
        $book = Book::factory()->create(['title' => 'The Way of Kings']);
        $genre = Genre::factory()->create(['name' => 'Fantasy']);
        $book->genres()->attach($genre);

        $sessionId = DB::table('ai_assistant_sessions')->insertGetId([
            'user_id' => $userId,
            'conversation_history' => json_encode([
                ['role' => 'user', 'content' => 'Find all books', 'timestamp' => now()->toISOString()],
            ]),
            'last_intent' => 'search',
            'operations' => json_encode([]),
            'status' => 'pending_approval',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mockProvider->shouldReceive('completeStructured')
            ->andReturn(AIResponse::successWithData([
                'intent' => 'search',
                'parameters' => ['genre' => 'Fantasy'],
                'explanation' => 'Searching for fantasy books',
            ]));

        $result = $this->service->processRequest('Only fantasy books', $sessionId, $userId);

        $this->assertTrue($result['success']);
        $this->assertEquals($sessionId, $result['session_id']);

        $session = DB::table('ai_assistant_sessions')->find($sessionId);
        $this->assertNotNull($session);
        /** @var object $session */
        $conversation = json_decode($session->conversation_history, true);
        $this->assertCount(3, $conversation); // Original + new user + assistant
    }

    public function testGenerateSearchResultsFindsBooks(): void
    {
        // Create test books
        $author1 = Author::factory()->create(['name' => 'Brandon Sanderson']);
        $author2 = Author::factory()->create(['name' => 'William Gibson']);
        $genre1 = Genre::factory()->create(['name' => 'Fantasy']);
        $genre2 = Genre::factory()->create(['name' => 'Science Fiction']);

        $book1 = Book::factory()->create(['title' => 'The Way of Kings', 'year' => 2010]);
        $book1->authors()->attach($author1);
        $book1->genres()->attach($genre1);

        $book2 = Book::factory()->create(['title' => 'Words of Radiance', 'year' => 2014]);
        $book2->authors()->attach($author1);
        $book2->genres()->attach($genre1);

        $book3 = Book::factory()->create(['title' => 'Neuromancer', 'year' => 1984]);
        $book3->authors()->attach($author2);
        $book3->genres()->attach($genre2);

        $this->mockProvider->shouldReceive('completeStructured')
            ->andReturn(AIResponse::successWithData([
                'intent' => 'search',
                'parameters' => ['author' => 'Brandon Sanderson', 'genre' => 'Fantasy'],
            ]));

        $result = $this->service->processRequest(
            'Find all fantasy books by Brandon Sanderson',
            null,
            $this->user->id
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('search', $result['intent']);
        $this->assertGreaterThan(0, $result['affected_count']);
    }

    public function testGenerateUpdateOperationsCreatesCorrectStructure(): void
    {
        $author = Author::factory()->create(['name' => 'Terry Pratchett']);
        $genre = Genre::factory()->create(['name' => 'Other']);
        $book = Book::factory()->create(['title' => 'The Color of Magic']);
        $book->authors()->attach($author);
        $book->genres()->attach($genre);

        $this->mockProvider->shouldReceive('completeStructured')
            ->andReturn(AIResponse::successWithData([
                'intent' => 'update',
                'parameters' => [
                    'search' => ['author' => 'Terry Pratchett'],
                    'changes' => ['genre' => 'Fantasy']
                ],
            ]));

        $result = $this->service->processRequest(
            'Change the genre of all Terry Pratchett books to Fantasy',
            null,
            $this->user->id
        );

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, count($result['operations']));

        $operation = $result['operations'][0];
        $this->assertEquals('update', $operation['type']);
        $this->assertArrayHasKey('book_id', $operation);
        $this->assertArrayHasKey('current', $operation);
        $this->assertArrayHasKey('changes', $operation);
        $this->assertArrayHasKey('preview', $operation);
    }

    public function testGenerateDeleteOperationsCreatesCorrectStructure(): void
    {
        $book = Book::factory()->create(['title' => 'Unknown Book']);

        $this->mockProvider->shouldReceive('completeStructured')
            ->andReturn(AIResponse::successWithData([
                'intent' => 'delete',
                'parameters' => [
                    'criteria' => ['title' => 'Unknown Book'],
                    'delete_files' => false
                ],
            ]));

        $result = $this->service->processRequest(
            'Delete all books with no author',
            null,
            $this->user->id
        );

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, count($result['operations']));

        $operation = $result['operations'][0];
        $this->assertEquals('delete', $operation['type']);
        $this->assertArrayHasKey('book_id', $operation);
        $this->assertArrayHasKey('title', $operation);
        $this->assertArrayHasKey('delete_files', $operation);
    }

    public function testGenerateCreateOperationsCreatesCorrectStructure(): void
    {
        $this->mockProvider->shouldReceive('completeStructured')
            ->andReturn(AIResponse::successWithData([
                'intent' => 'create',
                'parameters' => [
                    'title' => 'New Book',
                    'author' => 'New Author'
                ],
            ]));

        $result = $this->service->processRequest(
            'Add a new book New Book by New Author',
            null,
            $this->user->id
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(1, count($result['operations']));
        $this->assertEquals('create', $result['operations'][0]['type']);
    }

    public function testGenerateTagOperationsCreatesCorrectStructure(): void
    {
        $book = Book::factory()->create(['title' => 'Mistborn']);
        $genre = Genre::factory()->create(['name' => 'Other']);
        $book->genres()->attach($genre);

        $this->mockProvider->shouldReceive('completeStructured')
            ->andReturn(AIResponse::successWithData([
                'intent' => 'tag',
                'parameters' => [
                    'search' => ['title' => 'Mistborn'],
                    'tag' => 'Fantasy'
                ],
            ]));

        $result = $this->service->processRequest(
            'Tag Mistborn as Fantasy',
            null,
            $this->user->id
        );

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, count($result['operations']));
        $this->assertEquals('update', $result['operations'][0]['type']);
        $this->assertEquals('Fantasy', $result['operations'][0]['changes']['genre']);
    }

    public function testExecuteOperationsUpdatesBooks(): void
    {
        $book = Book::factory()->create(['title' => 'Mistborn']);
        $genre = Genre::factory()->create(['name' => 'Other']);
        $book->genres()->attach($genre);

        $sessionId = DB::table('ai_assistant_sessions')->insertGetId([
            'user_id' => $this->user->id,
            'conversation_history' => json_encode([]),
            'last_intent' => 'update',
            'operations' => json_encode([
                [
                    'type' => 'update',
                    'book_id' => $book->id,
                    'current' => ['title' => 'Mistborn', 'genre' => 'Other'],
                    'changes' => ['genre' => 'Fantasy'],
                ],
            ]),
            'status' => 'pending_approval',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->service->executeOperations($sessionId);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['executed_count']);
        $this->assertEquals(0, $result['error_count']);

        $this->assertEquals('Fantasy', $book->fresh()->genres->first()->name);

        $session = DB::table('ai_assistant_sessions')->find($sessionId);
        $this->assertNotNull($session);
        /** @var object $session */
        $this->assertEquals('completed', $session->status);
    }

    public function testExecuteOperationsDeletesBooks(): void
    {
        $book = Book::factory()->create(['title' => 'Test Book']);

        $sessionId = DB::table('ai_assistant_sessions')->insertGetId([
            'user_id' => $this->user->id,
            'conversation_history' => json_encode([]),
            'last_intent' => 'delete',
            'operations' => json_encode([
                [
                    'type' => 'delete',
                    'book_id' => $book->id,
                    'title' => 'Test Book',
                    'file_path' => null,
                    'delete_files' => false,
                ],
            ]),
            'status' => 'pending_approval',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->service->executeOperations($sessionId);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['executed_count']);

        // Book model uses SoftDeletes, so check that it's soft deleted
        $this->assertSoftDeleted('books', ['id' => $book->id]);
    }

    public function testExecuteOperationsWithSelectiveExecution(): void
    {
        $book1 = Book::factory()->create(['title' => 'Book 1']);
        $genre = Genre::factory()->create(['name' => 'Other']);
        $book1->genres()->attach($genre);

        $book2 = Book::factory()->create(['title' => 'Book 2']);
        $book2->genres()->attach($genre);

        $sessionId = DB::table('ai_assistant_sessions')->insertGetId([
            'user_id' => $this->user->id,
            'conversation_history' => json_encode([]),
            'last_intent' => 'update',
            'operations' => json_encode([
                [
                    'type' => 'update',
                    'book_id' => $book1->id,
                    'current' => ['genre' => 'Other'],
                    'changes' => ['genre' => 'Fantasy'],
                ],
                [
                    'type' => 'update',
                    'book_id' => $book2->id,
                    'current' => ['genre' => 'Other'],
                    'changes' => ['genre' => 'Fantasy'],
                ],
            ]),
            'status' => 'pending_approval',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Only execute first book
        $result = $this->service->executeOperations($sessionId, [$book1->id]);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['executed_count']);

        $this->assertEquals('Fantasy', $book1->fresh()->genres->first()->name);
        $this->assertEquals('Other', $book2->fresh()->genres->first()->name); // Not changed
    }

    public function testExecuteOperationsHandlesErrors(): void
    {
        $sessionId = DB::table('ai_assistant_sessions')->insertGetId([
            'user_id' => $this->user->id,
            'conversation_history' => json_encode([]),
            'last_intent' => 'update',
            'operations' => json_encode([
                [
                    'type' => 'update',
                    'book_id' => 99999, // Non-existent book
                    'current' => ['genre' => 'Other'],
                    'changes' => ['genre' => 'Fantasy'],
                ],
            ]),
            'status' => 'pending_approval',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->service->executeOperations($sessionId);

        $this->assertFalse($result['success']);
        $this->assertGreaterThan(0, $result['error_count']);

        $session = DB::table('ai_assistant_sessions')->find($sessionId);
        $this->assertNotNull($session);
        /** @var object $session */
        $this->assertEquals('partially_completed', $session->status);
    }

    public function testGetUsageStatsReturnsProviderStats(): void
    {
        $stats = $this->service->getUsageStats();

        $this->assertArrayHasKey('session_cost', $stats);
        $this->assertArrayHasKey('requests_this_minute', $stats);
        $this->assertArrayHasKey('requests_today', $stats);
        $this->assertArrayHasKey('rate_limits', $stats);
        $this->assertArrayHasKey('pricing', $stats);
    }
}
