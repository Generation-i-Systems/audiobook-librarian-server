<?php

namespace Tests\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookShowViewTest extends TestCase
{
    use RefreshDatabase;

    protected $mockService;
    protected $user;
    protected $testBook1;
    protected $testBook2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user using the User factory
        $this->user = \App\Models\User::factory()->create([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'role' => 'library-user',
        ]);

        // Set up test book data first
        $this->testBook1 = [
            'id' => 'test-book-1',
            'title' => 'Test Book with Series',
            'author' => ['Test Author'],
            'authors' => ['Test Author'],
            'series' => [
                [
                    'seriesName' => 'Test Series',
                    'number' => 1
                ]
            ],
            'description' => 'Test description',
            'cover' => 'test-cover.jpg',
            'cover_image' => 'test-cover.jpg',
            'dateAdded' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        $this->testBook2 = [
            'id' => 'test-book-2',
            'title' => 'Test Book without Series',
            'author' => ['Test Author'],
            'authors' => ['Test Author'],
            'description' => 'Test description',
            'cover' => 'test-cover.jpg',
            'cover_image' => 'test-cover.jpg',
            'dateAdded' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        // Create a mock of the DocumentStoreServiceInterface
        $this->mockService = \Mockery::mock(DocumentStoreServiceInterface::class);

        // Set up default mock expectations for listBooks
        $this->mockService->shouldReceive('listBooks')
            ->with(1, 24, [], true)
            ->andReturn([
                'data' => [
                    $this->testBook1,
                    $this->testBook2
                ],
                'total' => 2,
                'per_page' => 24,
                'current_page' => 1,
                'last_page' => 1
            ]);

        // Add expectation for related books in show method
        $this->mockService->shouldReceive('listBooks')
            ->with(1, 100)
            ->andReturn([
                'data' => [
                    $this->testBook1,
                    $this->testBook2
                ],
                'total' => 2,
                'per_page' => 100,
                'current_page' => 1,
                'last_page' => 1
            ]);

        // Set up default expectations for getBook - allow any calls but no default return
        $this->mockService->shouldReceive('getBook')->byDefault();

        // Bind the mock service to the service container
        $this->app->instance(DocumentStoreServiceInterface::class, $this->mockService);

        // Authenticate the test user
        $this->actingAs($this->user);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDisplaysSeriesInformationCorrectly()
    {
        // Set up the mock to return our test book with series when getBook is called
        $this->mockService
            ->shouldReceive('getBook')
            ->with('test-book-1')
            ->andReturn($this->testBook1);

        // Make the request to the show route with the book ID
        $response = $this->get(route('books.show', ['book' => 'test-book-1']));

        // Assert the response is successful
        $response->assertStatus(200);

        // Assert the series information is displayed correctly
        $response->assertSee('Test Series');
        $response->assertSee('Book 1');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itHandlesBooksWithoutSeries()
    {
        // Set up the mock to return our test book without series when getBook is called
        $this->mockService
            ->shouldReceive('getBook')
            ->with('test-book-2')
            ->andReturn($this->testBook2);

        // Make the request to the show route with the book ID
        $response = $this->get(route('books.show', ['book' => 'test-book-2']));

        // Assert the response is successful
        $response->assertStatus(200);

        // Assert the series label is not shown when there's no series data
        $response->assertDontSee('Series:');
    }
}
