<?php

namespace Tests\Feature;

use App\Services\AudiobookBayApiService;
use App\Services\AudiobookBayService;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudiobookBayTest extends TestCase
{
    // use RefreshDatabase; // Uncomment if database interactions are tested

    protected AudiobookBayService $service;

    protected string $baseUrl;

    protected $audiobookBayApiServiceMock; // Mockery objects don't always play nice with strict typing here

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUrl = 'https://audiobookbay.test';
        config(['services.audiobook_bay.base_url' => $this->baseUrl]);

        // Config values for AudiobookBayApiService are typically loaded from config/services.php
        // Ensure your test environment has necessary stubs or actual config if service relies on it.
        // e.g., config(['services.audiobook_bay.username' => 'testuser']);
        // e.g., config(['services.audiobook_bay.password' => 'testpass']);

        $this->audiobookBayApiServiceMock = Mockery::mock(AudiobookBayApiService::class);

        // Instantiate AudiobookBayService with the mock
        $this->service = new AudiobookBayService($this->audiobookBayApiServiceMock);

        // Disable logging for tests to keep output clean
        Log::spy();

        // Clear relevant caches before each test if AudiobookBayService uses specific cache keys
        // For example, if performSearch uses a cache key like 'audiobookbay_service_search_...'
        // Cache::forget('some_specific_cache_key_used_by_AudiobookBayService');
    }

    /**
     * Clean up the testing environment before the next test.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function test_can_search_audiobooks_and_formats_results()
    {
        $query = 'test query';
        $options = ['page' => 1]; // Example options passed to apiService
        $serviceOptions = ['page' => 1, 'limit' => 10]; // Options including limit for service layer

        // Expected data structure from AudiobookBayApiService->searchAudiobooks()
        // This structure should match what AudiobookBayParserTrait's parseSearchResults produces
        $mockApiResults = [
            [
                'title' => 'Test Book 1 from API',
                'authors' => [['name' => 'Author A']],
                'narrators' => [['name' => 'Narrator X']],
                'url' => rtrim($this->baseUrl, '/') . '/ab/test-book-1-slug',
                'info_hash' => 'hash1',
                'cover_image_url' => 'http://example.com/cover1.jpg',
                'description' => 'Description for book 1.',
                'language' => 'English',
                'metadata' => [
                    'size' => '100 MB',
                    'format' => 'MP3',
                    'source' => 'audiobookbay', // Added by parser
                    'categories' => ['Fiction'],
                    'bitrate' => '64 kbps',
                ],
            ],
            [
                'title' => 'Test Book 2 from API',
                'authors' => [['name' => 'Author B']],
                'narrators' => [['name' => 'Narrator Y']],
                'url' => rtrim($this->baseUrl, '/') . '/ab/test-book-2-slug',
                'info_hash' => 'hash2',
                'cover_image_url' => 'http://example.com/cover2.jpg',
                'description' => 'Description for book 2.',
                'language' => 'German',
                'metadata' => [
                    'size' => '120 MB',
                    'format' => 'M4B',
                    'source' => 'audiobookbay',
                    'categories' => ['Non-Fiction'],
                    'bitrate' => '128 kbps',
                ],
            ],
        ];

        $this->audiobookBayApiServiceMock
            ->shouldReceive('searchAudiobooks')
            ->once()
            ->with($query, $options) // Assert options passed to the underlying API service
            ->andReturn($mockApiResults);

        // Call the method on AudiobookBayService (which includes its own caching and formatting)
        $serviceResults = $this->service->performSearch($query, $serviceOptions);

        $this->assertIsArray($serviceResults);
        $this->assertNotEmpty($serviceResults);
        // Assuming default limit of 10, and we provided 2 mock results, count should be 2.
        // If $serviceOptions['limit'] was less than count($mockApiResults), this would be different.
        $this->assertCount(count($mockApiResults), $serviceResults);

        // Check formatting of the first result by AudiobookBayService
        $firstServiceResult = $serviceResults[0];
        $firstApiResult = $mockApiResults[0];

        $this->assertEquals('test-book-1-slug', $firstServiceResult['id']);
        $this->assertEquals($firstApiResult['title'], $firstServiceResult['title']);
        $this->assertEquals($firstApiResult['authors'][0]['name'], $firstServiceResult['author']);
        $this->assertEquals($firstApiResult['narrators'][0]['name'], $firstServiceResult['narrator']);
        $this->assertEquals($firstApiResult['metadata']['size'], $firstServiceResult['size']);
        $this->assertEquals($firstApiResult['metadata']['format'], $firstServiceResult['format']);
        $this->assertEquals($firstApiResult['url'], $firstServiceResult['link']);
        $this->assertEquals($firstApiResult['cover_image_url'], $firstServiceResult['cover']);
        $this->assertEquals($firstApiResult['description'], $firstServiceResult['description']);
        $this->assertEquals($firstApiResult['metadata'], $firstServiceResult['metadata']);
    }

    #[Test]
    public function test_perform_search_returns_empty_array_on_api_service_failure()
    {
        $query = 'failing query';
        $options = ['page' => 1];
        $serviceOptions = ['page' => 1, 'limit' => 10];

        $this->audiobookBayApiServiceMock
            ->shouldReceive('searchAudiobooks')
            ->once()
            ->with($query, $options)
            ->andReturn(null); // Simulate API service returning null (e.g., on error)

        $serviceResults = $this->service->performSearch($query, $serviceOptions);

        $this->assertIsArray($serviceResults);
        $this->assertEmpty($serviceResults);
    }

    #[Test]
    public function test_can_get_book_details_and_formats_result()
    {
        $bookIdOrSlug = 'test-book-detailed-slug';

        // Expected data structure from AudiobookBayApiService->getAudiobookDetails()
        // This structure should match what AudiobookBayParserTrait's parseAudiobookDetails produces
        $mockApiDetails = [
            'id' => $bookIdOrSlug, // Assuming apiService might add this or it's derived from URL
            'title' => 'Detailed Test Book',
            'subtitle' => 'The Subtitle of The Detailed Test Book',
            'authors' => [['name' => 'Author Detailed', 'id' => null]],
            'narrators' => [['name' => 'Narrator Detailed', 'id' => null]],
            'description' => 'A very detailed description of the book content.',
            'published_date' => '2023-03-15',
            'publisher' => 'Detailed Publisher Inc.',
            'cover_image_url' => 'http://example.com/detailed_cover.jpg',
            'categories' => ['Mystery', 'Thriller'], // This might be $mockApiDetails['metadata']['categories'] too
            'language' => 'French',
            'series' => ['name' => 'Detailed Series', 'number' => '3'],
            'url' => rtrim($this->baseUrl, '/') . '/ab/test-book-detailed-slug',
            'metadata' => [
                'source' => 'audiobookbay', // Added by parser
                'format' => 'M4B - HQ',
                'size' => '350 MB',
                'duration' => 'PT8H15M30S', // ISO8601 Duration format
                'downloads' => '1234 times',
                'categories' => ['Mystery', 'Thriller'], // Ensure consistency if categories are here too
            ],
        ];

        $this->audiobookBayApiServiceMock
            ->shouldReceive('getAudiobookDetails')
            ->once()
            ->with($bookIdOrSlug)
            ->andReturn($mockApiDetails);

        // Call the method on AudiobookBayService
        $serviceDetails = $this->service->performGetBookDetails($bookIdOrSlug);

        $this->assertIsArray($serviceDetails);
        $this->assertNotEmpty($serviceDetails);


        $this->assertEquals($bookIdOrSlug, $serviceDetails['id']);
        $this->assertEquals($mockApiDetails['title'], $serviceDetails['title']);
        $this->assertEquals($mockApiDetails['subtitle'], $serviceDetails['subtitle']);
        $this->assertCount(1, $serviceDetails['authors']);
        $this->assertEquals($mockApiDetails['authors'][0]['name'], $serviceDetails['authors'][0]['author']['name']);
        $this->assertCount(1, $serviceDetails['narrators']);
        $this->assertEquals($mockApiDetails['narrators'][0]['name'], $serviceDetails['narrators'][0]['author']['name']);
        $this->assertEquals($mockApiDetails['description'], $serviceDetails['description']);
        $this->assertEquals($mockApiDetails['published_date'], $serviceDetails['published_date']);
        $this->assertEquals($mockApiDetails['publisher'], $serviceDetails['publisher']);
        $this->assertEquals($mockApiDetails['cover_image_url'], $serviceDetails['coverImageUrl']);
        $this->assertCount(2, $serviceDetails['categories']);
        $this->assertEquals($mockApiDetails['categories'][0], $serviceDetails['categories'][0]['genre']['name']);
        $this->assertEquals($mockApiDetails['language'], $serviceDetails['language']);
        $this->assertEquals('Detailed Series #3', $serviceDetails['series']);
        $this->assertEquals('3', $serviceDetails['seriesNumber']);

        // Duration: PT8H15M30S = (8*3600) + (15*60) + 30 = 28800 + 900 + 30 = 29730 seconds
        $this->assertEquals(29730, $serviceDetails['durationSeconds']);

        $this->assertArrayHasKey('source', $serviceDetails['metadata']);
        $this->assertEquals('AudiobookBay', $serviceDetails['metadata']['source']);
        $this->assertEquals($mockApiDetails['url'], $serviceDetails['metadata']['url']);
        $this->assertEquals($mockApiDetails['metadata']['format'], $serviceDetails['metadata']['format']);
        $this->assertEquals($mockApiDetails['metadata']['size'], $serviceDetails['metadata']['size']);
    }

    #[Test]
    public function test_perform_get_book_details_returns_null_on_api_service_failure()
    {
        $bookIdOrSlug = 'non-existent-slug';

        $this->audiobookBayApiServiceMock
            ->shouldReceive('getAudiobookDetails')
            ->once()
            ->with($bookIdOrSlug)
            ->andReturn(null); // Simulate API service returning null

        $serviceDetails = $this->service->performGetBookDetails($bookIdOrSlug);

        $this->assertNull($serviceDetails);
    }

    #[Test]
    public function test_is_available_returns_true_when_service_is_set()
    {
        // In setUp, $this->service is initialized with a mock, so it should be available.
        $this->assertTrue($this->service->isAvailable());
    }
}
