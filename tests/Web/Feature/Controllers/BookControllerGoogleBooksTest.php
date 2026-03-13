<?php

namespace Tests\Web\Feature\Controllers;

use App\Http\Controllers\Admin\BookMetadataSearchController;
use App\Services\GoogleBooksApiService;
use App\Traits\BookImportTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class BookControllerGoogleBooksTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test that the searchGoogleBooksWithSimilarity method returns camelCase formatted results
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function search_google_books_with_similarity_returns_camel_case_formatted_results()
    {
        // Mock the Log facade
        Log::shouldReceive('info')->zeroOrMoreTimes();

        // Create a mock of GoogleBooksApiService
        $mockGoogleBooksApiService = Mockery::mock(GoogleBooksApiService::class);

        // Define raw Google Books API results
        $rawGoogleBooksResults = [
            [
                'id' => '12345',
                'volumeInfo' => [
                    'title' => 'Test Book',
                    'authors' => ['Test Author'],
                    'publishedDate' => '2023',
                    'imageLinks' => [
                        'thumbnail' => 'http://example.com/cover.jpg',
                    ],
                    'description' => 'Test description',
                    'categories' => ['Fiction'],
                    'industryIdentifiers' => [
                        ['type' => 'ISBN_13', 'identifier' => '9781234567890'],
                    ],
                    'series' => 'Test Series',
                    'seriesNumber' => '1',
                ],
            ],
        ];

        // Setup the mock to return raw results
        $mockGoogleBooksApiService->shouldReceive('searchBooks')
            ->once()
            ->withAnyArgs()
            ->andReturn($rawGoogleBooksResults);

        // Create a test class that uses the BookImportTrait
        $testClass = new class () {
            use BookImportTrait;
        };

        // Use reflection to set the protected property
        $reflection = new \ReflectionClass($testClass);
        $property = $reflection->getProperty('googleBooksApiService');
        $property->setAccessible(true);
        $property->setValue($testClass, $mockGoogleBooksApiService);

        // Call the method directly
        $results = $testClass->searchGoogleBooksWithSimilarity(
            'Test Book',
            'Test Author',
            'Test Series',
            '1',
            false,
            10
        );

        // Verify the results
        $this->assertNotEmpty($results);

        // Get the first result
        $item = reset($results);

        // Check that specific camelCase keys exist
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('author', $item);
        $this->assertArrayHasKey('publishedYear', $item);
        $this->assertArrayHasKey('coverImageUrl', $item);
        $this->assertArrayHasKey('matchScore', $item);
        $this->assertArrayHasKey('seriesName', $item);
        $this->assertArrayHasKey('seriesNumber', $item);
        $this->assertArrayHasKey('googleBooksId', $item);

        // Ensure no snake_case keys exist
        $this->assertArrayNotHasKey('published_year', $item);
        $this->assertArrayNotHasKey('cover_image_url', $item);
        $this->assertArrayNotHasKey('match_score', $item);
        $this->assertArrayNotHasKey('series_number', $item);
        $this->assertArrayNotHasKey('google_books_id', $item);

        // Check specific values
        $this->assertEquals('Test Book', $item['title']);
        $this->assertEquals(['Test Author'], $item['author']);
        $this->assertEquals('2023', $item['publishedYear']);
        $this->assertEquals('http://example.com/cover.jpg', $item['coverImageUrl']);
        $this->assertEquals('Test Series', $item['seriesName']);
        $this->assertEquals('1', $item['seriesNumber']);
        $this->assertEquals('12345', $item['googleBooksId']);
    }

    /**
     * Test that the googleBooks controller method returns camelCase formatted results
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function google_books_controller_returns_results()
    {
        // Mock the Log facade
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $mockGoogleBooksApi = Mockery::mock(GoogleBooksApiService::class);
        $mockAudibleService = Mockery::mock(\App\Services\AudibleService::class);

        // Define the expected results from the service
        $expectedResults = [
            ['id' => '123', 'volumeInfo' => ['title' => 'Test Book']],
        ];

        // Mock the searchBooks method to return results
        $mockGoogleBooksApi->shouldReceive('searchBooks')
            ->once()
            ->withAnyArgs()
            ->andReturn($expectedResults);

        $controller = new BookMetadataSearchController(
            $mockGoogleBooksApi,
            $mockAudibleService
        );

        // Create a request with query parameters
        $request = new Request([
            'title' => 'Test Book',
            'author' => 'Test Author',
            'limit' => 10,
        ]);

        // Call the controller method
        $response = $controller->googleBooks($request);

        // Verify the response
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($expectedResults, json_decode($response->getContent(), true));
    }
}
