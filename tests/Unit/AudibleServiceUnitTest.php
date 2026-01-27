<?php

namespace Tests\Unit;

use App\Services\AudibleService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AudibleService Unit Tests
 *
 * @method void assertExists(string $path)
 * @method void assertMissing(string $path)
 */
class AudibleServiceUnitTest extends TestCase
{
    protected AudibleService $audibleService;

    protected MockInterface $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        // Create a mock logger that allows any method calls
        /** @var \Psr\Log\LoggerInterface&\Mockery\MockInterface $loggerMock */
        $loggerMock = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $this->loggerMock = $loggerMock;
        $this->loggerMock->shouldReceive('emergency')->withAnyArgs()->andReturnNull();
        $this->loggerMock->shouldReceive('alert')->withAnyArgs()->andReturnNull();
        $this->loggerMock->shouldReceive('critical')->withAnyArgs()->andReturnNull();
        $this->loggerMock->shouldReceive('error')->withAnyArgs()->andReturnNull();
        $this->loggerMock->shouldReceive('warning')->withAnyArgs()->andReturnNull();
        $this->loggerMock->shouldReceive('notice')->withAnyArgs()->andReturnNull();
        $this->loggerMock->shouldReceive('info')->withAnyArgs()->andReturnNull();
        $this->loggerMock->shouldReceive('debug')->withAnyArgs()->andReturnNull();
        $this->loggerMock->shouldReceive('log')->withAnyArgs()->andReturnNull();

        // Create a log manager mock that returns our logger mock
        /** @var \Mockery\MockInterface $logManagerMock */
        $logManagerMock = Mockery::mock(\Illuminate\Log\LogManager::class);
        $logManagerMock->shouldReceive('channel')->withAnyArgs()->andReturn($this->loggerMock);
        $logManagerMock->shouldReceive('stack')->withAnyArgs()->andReturn($this->loggerMock);
        $logManagerMock->shouldReceive('emergency')->withAnyArgs()->andReturn($this->loggerMock);
        $logManagerMock->shouldReceive('alert')->withAnyArgs()->andReturn($this->loggerMock);
        $logManagerMock->shouldReceive('critical')->withAnyArgs()->andReturn($this->loggerMock);
        $logManagerMock->shouldReceive('error')->withAnyArgs()->andReturn($this->loggerMock);
        $logManagerMock->shouldReceive('warning')->withAnyArgs()->andReturn($this->loggerMock);
        $logManagerMock->shouldReceive('notice')->withAnyArgs()->andReturn($this->loggerMock);
        $logManagerMock->shouldReceive('info')->withAnyArgs()->andReturn($this->loggerMock);
        $logManagerMock->shouldReceive('debug')->withAnyArgs()->andReturn($this->loggerMock);
        $logManagerMock->shouldReceive('log')->withAnyArgs()->andReturn($this->loggerMock);

        // Replace the Log facade with our mock
        $this->app->instance(\Illuminate\Log\LogManager::class, $logManagerMock);
        Log::swap($logManagerMock);

        $this->audibleService = new AudibleService();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function test_downloads_cover_image_successfully_with_content_type_extension()
    {
        Storage::fake('public');
        $imageUrl = 'https://m.media-amazon.com/images/I/51Q42D63G5L.jpg';
        $asin = 'B002V1O1QK';
        $imageContent = 'fake-image-data';

        Http::fake([$imageUrl => Http::response($imageContent, 200, ['Content-Type' => 'image/jpeg'])]);
        // No need to set log expectations with Log::fake()

        $filePath = $this->audibleService->downloadCoverImage($imageUrl, $asin);

        $this->assertNotNull($filePath);
        $this->assertEquals('covers/' . $asin . '.jpg', $filePath);
        Storage::disk('public')->assertExists($filePath);
        Storage::disk('public')->assertMissing('covers/' . $asin . '.png');
        $this->assertEquals($imageContent, Storage::disk('public')->get($filePath));
    }

    #[Test]
    public function test_downloads_cover_image_successfully_with_url_extension()
    {
        Storage::fake('public');
        $imageUrl = 'https://m.media-amazon.com/images/I/51Q42D63G5L.png';
        $asin = 'B002V1O1QK';
        $imageContent = 'fake-image-data';

        Http::fake([$imageUrl => Http::response($imageContent, 200, ['Content-Type' => 'application/octet-stream'])]);
        // No need to set log expectations with Log::fake()

        $filePath = $this->audibleService->downloadCoverImage($imageUrl, $asin);

        $this->assertNotNull($filePath);
        $this->assertEquals('covers/' . $asin . '.png', $filePath);
        Storage::disk('public')->assertExists($filePath);
    }

    #[Test]
    public function test_creates_directory_if_not_exists()
    {
        Storage::fake('public');
        $imageUrl = 'https://m.media-amazon.com/images/I/51Q42D63G5L.jpg';
        $asin = 'B002V1O1QK';
        $imageContent = 'fake-image-data';
        $subDirectory = 'new-covers/sub';

        Http::fake([$imageUrl => Http::response($imageContent, 200, ['Content-Type' => 'image/jpeg'])]);
        // No need to set log expectations with Log::fake()
        Storage::disk('public')->assertMissing($subDirectory);

        $filePath = $this->audibleService->downloadCoverImage($imageUrl, $asin, $subDirectory);

        $this->assertNotNull($filePath);
        $this->assertEquals($subDirectory . '/' . $asin . '.jpg', $filePath);
        Storage::disk('public')->assertExists($subDirectory);
        Storage::disk('public')->assertExists($filePath);
    }

    #[Test]
    public function test_handles_http_error_gracefully()
    {
        Storage::fake('public');
        $imageUrl = 'https://m.media-amazon.com/images/I/invalid.jpg';
        $asin = 'B002V1O1QK';

        Http::fake([$imageUrl => Http::response(null, 404)]);
        // No need to set log expectations with Log::fake()

        $filePath = $this->audibleService->downloadCoverImage($imageUrl, $asin);

        $this->assertNull($filePath);
        Storage::disk('public')->assertMissing('covers/' . $asin . '.jpg');
    }

    #[Test]
    public function test_handles_storage_put_failure()
    {
        $imageUrl = 'https://m.media-amazon.com/images/I/51Q42D63G5L.jpg';
        $asin = 'B002V1O1QK';
        $expectedPath = 'covers/' . $asin . '.jpg';

        Http::fake([$imageUrl => Http::response('fake-data', 200, ['Content-Type' => 'image/jpeg'])]);

        $diskMock = Mockery::mock(Filesystem::class);

        $diskMock->shouldReceive('exists')->with('covers')->once()->andReturn(false);
        $diskMock->shouldReceive('makeDirectory')->with('covers')->once()->andReturn(true);
        $diskMock->shouldReceive('put')->with($expectedPath, 'fake-data')->once()->andReturn(false);

        Storage::shouldReceive('disk')->with('public')->andReturn($diskMock);

        $result = $this->audibleService->downloadCoverImage($imageUrl, $asin, 'covers');

        $this->assertNull($result);
    }

    #[Test]
    public function test_defaults_to_jpg_when_extension_is_unknown()
    {
        Storage::fake('public');
        $imageUrl = 'https://m.media-amazon.com/images/I/51Q42D63G5L';
        $asin = 'B002V1O1QK';

        Http::fake([$imageUrl => Http::response('fake-data', 200, ['Content-Type' => 'application/octet-stream'])]);

        $filePath = $this->audibleService->downloadCoverImage($imageUrl, $asin);

        $this->assertEquals('covers/' . $asin . '.jpg', $filePath);
        Storage::disk('public')->assertExists($filePath);
    }

    #[Test]
    public function test_perform_search_successful()
    {
        // Create a partial mock of AudibleService to override only the performSearch method
        $mockService = $this->getMockBuilder(AudibleService::class)
            ->setConstructorArgs([$this->app->make('config'), $this->app->make('log')])
            ->onlyMethods(['performSearch'])
            ->getMock();

        // Set up the expected return value for performSearch
        $expectedResult = [
            [
                'source' => 'audible',
                'id' => 'B002V1O1QK',
                'title' => 'Dune',
                'authors' => [
                    ['author' => ['name' => 'Frank Herbert', 'id' => 'B123456']],
                ],
                'narrators' => [
                    ['narrator' => ['name' => 'Scott Brick', 'id' => 'B654321']],
                ],
                'cover_image_url' => 'http://example.com/cover.jpg',
                'description' => 'Test Description',
                'series' => null,
                'release_date' => '2023-01-01',
                'runtime' => 320,
                'publisher' => ['name' => 'Test Publisher'],
                'language' => 'english',
            ],
        ];

        // Configure the mock to return our expected result
        $mockService->expects($this->once())
            ->method('performSearch')
            ->with('dune', ['no_cache' => true])
            ->willReturn($expectedResult);

        // Replace the service in the container
        $this->app->instance(AudibleService::class, $mockService);

        // Call the searchBooks method which will use our mocked performSearch
        $results = $mockService->searchBooks('dune', ['no_cache' => true]);

        // Assertions
        $this->assertCount(1, $results);
        $this->assertEquals('Dune', $results[0]['title']);
        $this->assertEquals('Frank Herbert', $results[0]['authors'][0]['author']['name']);
    }

    #[Test]
    public function test_perform_search_api_failure()
    {
        // Create a partial mock of AudibleService to override only the performSearch method
        $mockService = $this->getMockBuilder(AudibleService::class)
            ->setConstructorArgs([$this->app->make('config'), $this->app->make('log')])
            ->onlyMethods(['performSearch'])
            ->getMock();

        // Configure the mock to return null (API failure)
        $mockService->expects($this->once())
            ->method('performSearch')
            ->with('dune', ['no_cache' => true])
            ->willReturn(null);

        // Replace the service in the container
        $this->app->instance(AudibleService::class, $mockService);

        // Call the searchBooks method which will use our mocked performSearch
        $results = $mockService->searchBooks('dune', ['no_cache' => true]);

        // Assert that the result is an empty array as expected
        // BaseBookService.searchBooks returns empty array when performSearch returns null
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    #[Test]
    public function test_perform_get_book_details_successful()
    {
        $asin = 'B002V1O1QK';

        // Create a partial mock of AudibleService to override only the performGetBookDetails method
        $mockService = $this->getMockBuilder(AudibleService::class)
            ->setConstructorArgs([$this->app->make('config'), $this->app->make('log')])
            ->onlyMethods(['performGetBookDetails'])
            ->getMock();

        // Set up the expected return value for performGetBookDetails
        $expectedResult = [
            'source' => 'audible',
            'id' => $asin,
            'title' => 'Dune',
            'authors' => [
                ['author' => ['name' => 'Frank Herbert', 'id' => null]],
            ],
            'narrators' => [
                ['narrator' => ['name' => 'Scott Brick', 'id' => null]],
            ],
            'cover_image_url' => 'http://example.com/cover.jpg',
            'description' => 'Test Description',
            'series' => null,
            'release_date' => '2023-01-01',
            'runtime' => 320,
            'publisher' => ['name' => 'Test Publisher'],
            'language' => 'english',
        ];

        // Configure the mock to return our expected result
        $mockService->expects($this->once())
            ->method('performGetBookDetails')
            ->with($asin)
            ->willReturn($expectedResult);

        // Replace the service in the container
        $this->app->instance(AudibleService::class, $mockService);

        // Call the getBookDetails method which will use our mocked performGetBookDetails
        $result = $mockService->getBookDetails($asin);

        // Assertions
        $this->assertIsArray($result);
        $this->assertEquals('Dune', $result['title']);
        $this->assertEquals('Frank Herbert', $result['authors'][0]['author']['name']);
    }

    #[Test]
    public function test_perform_get_book_details_api_failure()
    {
        $asin = 'B002V1O1QK';

        // Create a partial mock of AudibleService to override only the performGetBookDetails method
        $mockService = $this->getMockBuilder(AudibleService::class)
            ->setConstructorArgs([$this->app->make('config'), $this->app->make('log')])
            ->onlyMethods(['performGetBookDetails'])
            ->getMock();

        // Configure the mock to return null (API failure)
        $mockService->expects($this->once())
            ->method('performGetBookDetails')
            ->with($asin)
            ->willReturn(null);

        // Replace the service in the container
        $this->app->instance(AudibleService::class, $mockService);

        // Call the getBookDetails method which will use our mocked performGetBookDetails
        $result = $mockService->getBookDetails($asin);

        // Assert that the result is null as expected
        $this->assertNull($result);
    }
}
