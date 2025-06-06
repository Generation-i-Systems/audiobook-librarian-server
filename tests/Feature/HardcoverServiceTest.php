<?php

namespace Tests\Feature;

use App\Services\HardcoverService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HardcoverServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock the HTTP client for all tests
        Http::fake();

        // Fake mail sending
        Mail::fake();

        // Set test configuration
        config([
            'hardcover.api_url' => 'https://api.hardcover.app/v1/graphql',
            'hardcover.api_token' => 'test-token',
            'hardcover.token_expires_at' => now()->addDays(60)->toDateString(),
            'hardcover.notification_email' => 'test@example.com',
        ]);
    }

    #[Test]
    public function it_can_search_books_by_title()
    {
        // Create a partial mock of the service
        $mock = $this->getMockBuilder(HardcoverService::class)
            ->onlyMethods(['makeRequest'])
            ->getMock();

        // Mock the makeRequest method to return our test data
        $mock->method('makeRequest')
            ->willReturn([
                'data' => [
                    'books' => [
                        [
                            'id' => '1',
                            'title' => 'Test Book',
                            'subtitle' => 'A Test Subtitle',
                            'description' => 'A test book',
                            'release_date' => '2023-01-01',
                            'cover_image_url' => 'https://example.com/cover.jpg',
                            'genres' => [
                                ['genre' => ['name' => 'Science Fiction']],
                                ['genre' => ['name' => 'Adventure']]
                            ],
                            'authors' => [
                                ['author' => ['name' => 'Test Author']]
                            ]
                        ]
                    ]
                ]
            ]);

        // Call the method we want to test
        $results = $mock->searchBooks('Test Book');

        // Assert the results
        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('Test Book', $results[0]['title']);
        $this->assertEquals('Test Author', $results[0]['authors'][0]['author']['name']);
        $this->assertCount(2, $results[0]['genres']);
    }

    #[Test]
    public function it_handles_api_errors_gracefully()
    {
        // Mock error response
        Http::fake([
            'api.hardcover.app/v1/graphql' => Http::response([
                'errors' => [
                    ['message' => 'Invalid token']
                ]
            ], 401)
        ]);

        $service = app(HardcoverService::class);
        $result = $service->searchBooks('Test Book');

        $this->assertNull($result);
    }

    #[Test]
    public function it_sends_expiration_warning_when_token_is_about_to_expire()
    {
        // Set token to expire in 15 days
        config(['hardcover.token_expires_at' => now()->addDays(15)->toDateString()]);

        $service = app(HardcoverService::class);

        // This will trigger the expiration check
        $service->searchBooks('Test Book');

        // Assert email was sent
        Mail::assertSent(\App\Mail\HardcoverTokenExpiring::class, function ($mail) {
            return $mail->daysUntilExpiration <= 30;
        });
    }

    #[Test]
    public function it_handles_token_expiration()
    {
        // Set token as expired
        config(['hardcover.token_expires_at' => now()->subDay()->toDateString()]);

        $service = app(HardcoverService::class);
        $result = $service->searchBooks('Test Book');

        $this->assertNull($result);

        // Assert expiration email was sent
        Mail::assertSent(\App\Mail\HardcoverTokenExpiring::class, function ($mail) {
            return $mail->daysUntilExpiration === 0;
        });
    }

    #[Test]
    public function it_gets_book_details()
    {
        // Create a partial mock of the service
        $mock = $this->getMockBuilder(HardcoverService::class)
            ->onlyMethods(['makeRequest'])
            ->getMock();

        // Mock the makeRequest method to return our test data
        $mock->method('makeRequest')
            ->willReturn([
                'data' => [
                    'books_by_pk' => [
                        'id' => '1',
                        'title' => 'Test Book',
                        'description' => 'A test book',
                        'pages' => 300,
                        'cover_image_url' => 'https://example.com/cover.jpg',
                        'publisher' => ['name' => 'Test Publisher'],
                        'authors' => [
                            ['author' => ['name' => 'Test Author']]
                        ],
                        'narrators' => [
                            ['author' => ['name' => 'Test Narrator']]
                        ],
                        'genres' => [
                            ['genre' => ['name' => 'Science Fiction']]
                        ]
                    ]
                ]
            ]);

        // Call the method we want to test
        $book = $mock->getBookDetails('1');

        // Assert the results
        $this->assertIsArray($book);
        $this->assertEquals('Test Book', $book['title']);
        $this->assertEquals('Test Author', $book['authors'][0]['author']['name']);
        $this->assertEquals('Test Narrator', $book['narrators'][0]['author']['name']);
        $this->assertEquals('Science Fiction', $book['genres'][0]['genre']['name']);
    }
}
