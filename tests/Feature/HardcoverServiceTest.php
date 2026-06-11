<?php

namespace Tests\Feature;

use App\Services\HardcoverService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
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
    public function itCanSearchBooksByTitle()
    {
        // Create a partial mock of the service
        $mock = $this->getMockBuilder(HardcoverService::class)
            ->onlyMethods(['makeRequest'])
            ->getMock();

        // Mock the makeRequest method to return our test data
        $mock->expects($this->once())->method('makeRequest')
            ->willReturn([
                'data' => [
                    'search' => [
                        'results' => [
                            'hits' => [
                                [
                                    'document' => [
                                        'id' => '1',
                                        'title' => 'Test Book',
                                        'subtitle' => 'A Test Subtitle',
                                        'description' => 'A test book',
                                        'release_date' => '2023-01-01',
                                        'release_year' => 2023,
                                        'author_names' => ['Test Author'],
                                        'genres' => ['Science Fiction', 'Adventure'],
                                        'image' => ['url' => 'https://example.com/cover.jpg'],
                                        'isbns' => ['1234567890', '9781234567897'],
                                        'pages' => 300,
                                    ],
                                ],
                            ],
                            'found' => 1,
                        ],
                    ],
                ],
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
    public function itHandlesApiErrorsGracefully()
    {
        // Mock error response
        Http::fake([
            'api.hardcover.app/v1/graphql' => Http::response([
                'errors' => [
                    ['message' => 'Invalid token'],
                ],
            ], 401),
        ]);

        $service = app(HardcoverService::class);
        $result = $service->searchBooks('Test Book');

        $this->assertNull($result);
    }

    #[Test]
    public function itSendsExpirationWarningWhenTokenIsAboutToExpire()
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
    public function itHandlesTokenExpiration()
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
    public function itGetsBookDetails()
    {
        // Create a partial mock of the service
        $mock = $this->getMockBuilder(HardcoverService::class)
            ->onlyMethods(['makeRequest'])
            ->getMock();

        // Mock the makeRequest method to return our test data
        // Format matches the current Hardcover GraphQL schema (contributions/taggings/image.url)
        $mock->expects($this->once())->method('makeRequest')
            ->willReturn([
                'data' => [
                    'books_by_pk' => [
                        'id' => '1',
                        'title' => 'Test Book',
                        'description' => 'A test book',
                        'pages' => 300,
                        'release_date' => '2020-01-01',
                        'image' => ['url' => 'https://example.com/cover.jpg'],
                        'contributions' => [
                            ['author' => ['id' => 42, 'name' => 'Test Author']],
                        ],
                        'taggings' => [
                            ['tag' => ['tag' => 'Science Fiction']],
                        ],
                    ],
                ],
            ]);

        // Call the method we want to test
        $book = $mock->getBookDetails('1');

        // Assert the results
        $this->assertIsArray($book);
        $this->assertEquals('Test Book', $book['title']);
        $this->assertEquals('Test Author', $book['authors'][0]['author']['name']);
        $this->assertEquals('https://example.com/cover.jpg', $book['cover_image_url']);
        $this->assertEquals('Science Fiction', $book['genres'][0]['genre']['name']);
        $this->assertEquals(['Science Fiction'], $book['genre']);
    }
}
