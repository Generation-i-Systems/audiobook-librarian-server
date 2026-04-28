<?php

namespace Tests\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Mockery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Facade;

class BookControllerIndexTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected $user;
    protected $mockDocumentStoreService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the filesystem
        Storage::fake('public');

        // Create a test user
        $this->user = User::create([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'role' => 'user',
        ]);

        // Get a fresh instance of the user from the database
        $user = User::find($this->user->id);
        $this->actingAs($user);

        // Mock the Log facade with required methods
        /** @var \Mockery\MockInterface $logMock */
        $logMock = \Mockery::mock('log');
        $logMock->shouldReceive('error');
        $logMock->shouldReceive('log');
        $logMock->shouldReceive('info');
        $logMock->shouldReceive('warning');
        $logMock->shouldReceive('debug');
        Log::swap($logMock);

        // Mock the DocumentStoreService
        $this->mockDocumentStoreService = \Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->mockDocumentStoreService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testItDisplaysBooksWithArrayAndStringProperties()
    {
        try {
            // Create test data with properly structured arrays for authors and series
            $testBooks = [
                [
                    'id' => 1,
                    'title' => 'Test Book 1',
                    'author' => [
                        ['name' => 'Author 1'],
                    ],
                    'series' => [
                        ['seriesName' => 'Test Series 1'],
                    ],
                    'cover_image' => 'test1.jpg',
                    'description' => 'Test description 1',
                    'published_date' => '2020-01-01',
                    'publisher' => 'Publisher 1',
                    'isbn' => '1234567890',
                    'language' => 'en',
                    'pages' => 300,
                    'rating' => 4.5,
                    'ratings_count' => 100,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 2,
                    'title' => 'Test Book 2',
                    'author' => [
                        ['name' => 'Author 2'],
                    ],
                    'series' => [
                        ['seriesName' => 'Test Series 2'],
                    ],
                    'cover_image' => 'test2.jpg',
                    'description' => 'Test description 2',
                    'published_date' => '2020-01-02',
                    'publisher' => 'Publisher 2',
                    'isbn' => '0987654321',
                    'language' => 'en',
                    'pages' => 350,
                    'rating' => 4.0,
                    'ratings_count' => 50,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ];

            // Mock the DocumentStoreService to return our test data
            $this->mockDocumentStoreService->shouldReceive('listBooks')
                ->once()
                ->with(1, 12, [], true)
                ->andReturn([
                    'data' => $testBooks,
                    'total' => 2,
                    'per_page' => 24,
                    'current_page' => 1,
                    'last_page' => 1,
                    'from' => 1,
                    'to' => 2,
                    'path' => 'http://localhost',
                    'first_page_url' => 'http://localhost?page=1',
                    'last_page_url' => 'http://localhost?page=1',
                    'next_page_url' => null,
                    'prev_page_url' => null,
                ]);

            // Mock the getUniqueValues method for filter options
            $this->mockDocumentStoreService->shouldReceive('getUniqueValues')
                ->with('genre')
                ->andReturn([]);

            $this->mockDocumentStoreService->shouldReceive('getUniqueValues')
                ->with('author')
                ->andReturn([]);

            $this->mockDocumentStoreService->shouldReceive('getUniqueValues')
                ->with('series', 'seriesName')
                ->andReturn([]);

            // Mock the getRecentBooks method with properly structured data
            $recentBooks = [
                [
                    'id' => 3,
                    'title' => 'Recent Book 1',
                    'author' => [
                        ['name' => 'Author 3'],
                    ],
                    'series' => [
                        ['seriesName' => 'Test Series 3'],
                    ],
                    'cover_image' => 'recent1.jpg',
                    'coverImage' => 'recent1.jpg', // Add coverImage to match what the view expects
                    'description' => 'Recent test description 1',
                    'published_date' => '2023-01-01',
                    'publisher' => 'Publisher 3',
                    'isbn' => '1122334455',
                    'language' => 'en',
                    'pages' => 280,
                    'rating' => 4.8,
                    'ratings_count' => 200,
                    'created_at' => now()->subDays(5), // Recent book
                    'updated_at' => now()->subDays(5),
                ],
            ];

            $this->mockDocumentStoreService->shouldReceive('getRecentBooks')
                ->with(5, 7)
                ->andReturn($recentBooks);

            // Make the request to the index page
            $response = $this->get(route('books.index'));

            // Now assert the response status
            $response->assertStatus(200);

            // If we get here, the status is 200, now check view data
            $view = $response->original;
            // Check if books/recentBooks data is present in the view
            // (No console output on success)

            // Assert the view has the books data
            $response->assertViewHas('books');
            $response->assertViewHas('recentBooks');

            // Assert the view contains the book titles (handling both string and array cases)
            $response->assertSee('Test Book 1');
            $response->assertSee('Test Book 2');

            // Assert the view contains the series names
            $response->assertSee('Test Series 1');
            $response->assertSee('Test Series 2');
        } catch (\Exception $e) {
            // Re-throw the exception to fail the test without noisy dumps
            throw $e;
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testItRendersCoverlessLibrivoxBooksWithoutPlaceholderCards()
    {
        $testBooks = [
            [
                'id' => 10,
                'title' => 'Coverless LibriVox Book',
                'authors' => ['Volunteer Reader'],
                'genres' => ['Poetry'],
                'series' => [],
                'coverImage' => null,
                'duration' => '1:23:45',
                'source' => 'librivox',
            ],
        ];

        $this->mockDocumentStoreService->shouldReceive('listBooks')
            ->once()
            ->with(1, 12, [], true)
            ->andReturn([
                'data' => $testBooks,
                'total' => 1,
                'per_page' => 24,
                'current_page' => 1,
                'last_page' => 1,
            ]);

        $this->mockDocumentStoreService->shouldReceive('getUniqueValues')
            ->with('genre')
            ->andReturn([]);

        $this->mockDocumentStoreService->shouldReceive('getUniqueValues')
            ->with('author')
            ->andReturn([]);

        $this->mockDocumentStoreService->shouldReceive('getUniqueValues')
            ->with('series', 'seriesName')
            ->andReturn([]);

        $this->mockDocumentStoreService->shouldReceive('getRecentBooks')
            ->with(5, 7)
            ->andReturn([]);

        $response = $this->get(route('books.index'));

        $response->assertStatus(200)
            ->assertSee('Coverless LibriVox Book')
            ->assertSee('data-source="librivox"', false)
            ->assertSee('Cover unavailable');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testLibrivoxModeOnlyShowsListViewToggle(): void
    {
        $user = User::create([
            'name' => 'LibriVox User',
            'username' => 'librivoxuser',
            'email' => 'librivox@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'role' => 'librivox-user',
        ]);

        $this->actingAs($user);

        $this->mockDocumentStoreService->shouldReceive('listBooks')
            ->once()
            ->with(1, 12, [], true)
            ->andReturn([
                'data' => [],
                'total' => 0,
                'per_page' => 24,
                'current_page' => 1,
                'last_page' => 1,
            ]);

        $this->mockDocumentStoreService->shouldReceive('getUniqueValues')->with('genre')->andReturn([]);
        $this->mockDocumentStoreService->shouldReceive('getUniqueValues')->with('author')->andReturn([]);
        $this->mockDocumentStoreService->shouldReceive('getUniqueValues')->with('series', 'seriesName')->andReturn([]);
        $this->mockDocumentStoreService->shouldReceive('getRecentBooks')->with(5, 7)->andReturn([]);

        $response = $this->get(route('books.index'));

        $response->assertStatus(200)
            ->assertDontSee('id="main-list-btn"', false)
            ->assertDontSee('id="recent-list-btn"', false)
            ->assertDontSee('id="main-grid-btn"', false)
            ->assertDontSee('id="main-compact-btn"', false)
            ->assertDontSee('View Mode');
    }
}
