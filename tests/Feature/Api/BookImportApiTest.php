<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use Illuminate\Support\Facades\File;

class BookImportApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create some test data
        Genre::factory()->count(3)->create();
        Author::factory()->count(3)->create();
        Series::factory()->count(3)->create();
    }

    public function testGetGenresReturnsListOfGenres(): void
    {
        $response = $this->getJson('/api/v1/imports/genres');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name'],
                ],
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    public function testSearchAuthorsReturnsMatchingAuthors(): void
    {
        Author::factory()->create(['name' => 'UniqueSearchSanderson']);
        Author::factory()->create(['name' => 'UniqueSearchMull']);
        Author::factory()->create(['name' => 'Stephen King']);

        $response = $this->getJson('/api/v1/authors/search?q=UniqueSearch');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'book_count'],
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertTrue(
            collect($data)->every(fn ($author) => str_contains($author['name'], 'UniqueSearch'))
        );
    }

    public function testSearchAuthorsReturnsEmptyForShortQuery(): void
    {
        $response = $this->getJson('/api/v1/authors/search?q=');

        $response->assertOk()
            ->assertJson(['data' => []]);
    }

    public function testCreateAuthorCreatesNewAuthor(): void
    {
        $response = $this->postJson('/api/v1/authors', [
            'name' => 'New Test Author',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name'],
                'created',
            ])
            ->assertJson([
                'created' => true,
                'data' => ['name' => 'New Test Author'],
            ]);

        $this->assertDatabaseHas('authors', ['name' => 'New Test Author']);
    }

    public function testCreateAuthorReturnsExistingIfDuplicate(): void
    {
        /** @var Author $existingAuthor */
        $existingAuthor = Author::factory()->create(['name' => 'Existing Author']);

        $response = $this->postJson('/api/v1/authors', [
            'name' => 'Existing Author',
        ]);

        $response->assertOk()
            ->assertJson([
                'created' => false,
                'data' => [
                    'id' => $existingAuthor->id,
                    'name' => 'Existing Author',
                ],
            ]);
    }

    public function testCreateAuthorValidatesName(): void
    {
        $response = $this->postJson('/api/v1/authors', [
            'name' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function testSearchSeriesReturnsMatchingSeries(): void
    {
        Series::factory()->create(['name' => 'Mistborn']);
        Series::factory()->create(['name' => 'Stormlight Archive']);
        Series::factory()->create(['name' => 'The Dark Tower']);

        $response = $this->getJson('/api/v1/series/search?q=Mist');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'is_collection', 'book_count'],
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Mistborn', $data[0]['name']);
    }

    public function testCreateSeriesCreatesNewSeries(): void
    {
        $response = $this->postJson('/api/v1/series', [
            'name' => 'New Test Series',
            'is_collection' => true,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'is_collection'],
                'created',
            ])
            ->assertJson([
                'created' => true,
                'data' => [
                    'name' => 'New Test Series',
                    'is_collection' => true,
                ],
            ]);

        $this->assertDatabaseHas('series', [
            'name' => 'New Test Series',
            'is_collection' => true,
        ]);
    }

    public function testCreateSeriesReturnsExistingIfDuplicate(): void
    {
        /** @var Series $existingSeries */
        $existingSeries = Series::factory()->create(['name' => 'Existing Series']);

        $response = $this->postJson('/api/v1/series', [
            'name' => 'Existing Series',
        ]);

        $response->assertOk()
            ->assertJson([
                'created' => false,
                'data' => [
                    'id' => $existingSeries->id,
                    'name' => 'Existing Series',
                ],
            ]);
    }

    public function testCheckDuplicateReturnsFalseForNewBook(): void
    {
        $response = $this->postJson('/api/v1/imports/check-duplicate', [
            'title' => 'A Brand New Book',
            'author' => 'Unknown Author',
        ]);

        $response->assertOk()
            ->assertJson([
                'is_duplicate' => false,
                'existing_book' => null,
            ]);
    }

    public function testCheckDuplicateReturnsTrueForExistingBook(): void
    {
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Brandon Sanderson']);
        /** @var Book $book */
        $book = Book::factory()->create(['title' => 'The Way of Kings']);
        $book->authors()->attach($author);

        $response = $this->postJson('/api/v1/imports/check-duplicate', [
            'title' => 'The Way of Kings',
            'author' => 'Brandon Sanderson',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'is_duplicate',
                'existing_book' => ['id', 'title', 'authors', 'directory_path'],
            ])
            ->assertJson([
                'is_duplicate' => true,
                'existing_book' => [
                    'id' => $book->id,
                    'title' => 'The Way of Kings',
                ],
            ]);
    }

    public function testCheckDuplicateValidatesInput(): void
    {
        $response = $this->postJson('/api/v1/imports/check-duplicate', [
            'title' => '',
            'author' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'author']);
    }

    public function testGetQueueStatsReturnsStatistics(): void
    {
        $response = $this->getJson('/api/v1/imports/queue/stats');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['pending', 'completed', 'failed'],
            ]);
    }

    public function testGetPendingImportsReturnsList(): void
    {
        $response = $this->getJson('/api/v1/imports/queue/pending');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'count',
            ]);
    }

    public function testGetImportStatusReturnsNotFoundForInvalidId(): void
    {
        $response = $this->getJson('/api/v1/imports/import-nonexistent/status');

        $response->assertNotFound()
            ->assertJson([
                'found' => false,
                'status' => null,
            ]);
    }

    public function testGetImportStatusReturnsPendingForQueuedImport(): void
    {
        // Create a mock queued import
        $queuePath = storage_path('app/import-queue/queue');
        $importId = 'import-' . uniqid();
        $importPath = $queuePath . '/' . $importId;

        if (!File::isDirectory($queuePath)) {
            File::makeDirectory($queuePath, 0775, true);
        }
        File::makeDirectory($importPath, 0775, true);
        File::put($importPath . '/librarian.json', json_encode([
            'version' => '2.0',
            'title' => 'Test Book',
            'authors' => [['name' => 'Test Author']],
            'directory_path' => 'Test Author/Test Book',
            'audio_files' => ['test.m4b'],
            'import_metadata' => [
                'queued_at' => now()->toIso8601String(),
            ],
        ]));

        try {
            $response = $this->getJson('/api/v1/imports/' . $importId . '/status');

            $response->assertOk()
                ->assertJson([
                    'found' => true,
                    'status' => [
                        'state' => 'pending',
                        'import_id' => $importId,
                    ],
                ]);
        } finally {
            // Clean up
            File::deleteDirectory($importPath);
        }
    }

    public function testEndpointsRequireAuthentication(): void
    {
        // Create a new test instance without auth
        $this->withHeaders(['Authorization' => '']);

        $endpoints = [
            ['GET', '/api/v1/imports/genres'],
            ['GET', '/api/v1/imports/queue/stats'],
            ['GET', '/api/v1/imports/queue/pending'],
            ['POST', '/api/v1/imports/check-duplicate'],
            ['GET', '/api/v1/authors/search?q=test'],
            ['POST', '/api/v1/authors'],
            ['GET', '/api/v1/series/search?q=test'],
            ['POST', '/api/v1/series'],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = $method === 'GET'
                ? $this->getJson($url)
                : $this->postJson($url, ['name' => 'test', 'title' => 'test', 'author' => 'test']);

            $this->assertContains(
                $response->status(),
                [401, 403],
                "Expected 401 or 403 for unauthenticated {$method} {$url}, got {$response->status()}"
            );
        }
    }
}
