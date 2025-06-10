<?php

namespace Tests\Unit;

use App\Services\AudibleService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;
use Mockery;

class AudibleServiceUnitTest extends TestCase
{
    protected AudibleService $audibleService;
    protected MockInterface|LoggerInterface $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->loggerMock = Mockery::mock(LoggerInterface::class);

        // Default permissive expectations on the mock itself
        $this->loggerMock->shouldReceive('info')->withAnyArgs()->andReturnNull()->byDefault();
        $this->loggerMock->shouldReceive('error')->withAnyArgs()->andReturnNull()->byDefault();
        $this->loggerMock->shouldReceive('warning')->withAnyArgs()->andReturnNull()->byDefault();
        $this->loggerMock->shouldReceive('debug')->withAnyArgs()->andReturnNull()->byDefault();

        // Swap the Log facade to use our mock.
        // This should ensure Log::info(), Log::error(), Log::channel()->info(), etc.,
        // all go through $this->loggerMock.
        Log::swap($this->loggerMock);

        Log::shouldReceive('channel')->with('audible_service')
            ->andReturn($this->loggerMock)
            ->byDefault();
        Log::shouldReceive('channel')->withNoArgs()
            ->andReturn($this->loggerMock)
            ->byDefault(); // For Log::channel() fallback

        // Bind AudibleService in the container to a factory that creates an instance
        // and immediately sets its logger to our mock.
        $this->app->bind(AudibleService::class, function ($app) {
            $service = new AudibleService(); // AudibleService constructor runs its own logger setup
            $service->logger = $this->loggerMock; // Immediately override with our mock
            return $service;
        });

        // Resolve the service from the container; it will use the factory defined above.
        $this->audibleService = $this->app->make(AudibleService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testDownloadsCoverImageSuccessfullyWithContentTypeExtension()
    {
        Storage::fake('public');
        $imageUrl = 'https://m.media-amazon.com/images/I/51Q42D63G5L.jpg';
        $asin = 'B002V1O1QK';
        $imageContent = 'fake-image-data';

        Http::fake([$imageUrl => Http::response($imageContent, 200, ['Content-Type' => 'image/jpeg'])]);
        $this->loggerMock->shouldReceive('info')->once();

        $service = new AudibleService();
        $filePath = $service->downloadCoverImage($imageUrl, $asin);

        $this->assertNotNull($filePath);
        $this->assertEquals('covers/' . $asin . '.jpg', $filePath);
        Storage::disk('public')->assertExists($filePath);
        Storage::disk('public')->assertMissing('covers/' . $asin . '.png');
        $this->assertEquals($imageContent, Storage::disk('public')->get($filePath));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testDownloadsCoverImageSuccessfullyWithUrlExtension()
    {
        Storage::fake('public');
        $imageUrl = 'https://m.media-amazon.com/images/I/51Q42D63G5L.png';
        $asin = 'B002V1O1QK';
        $imageContent = 'fake-image-data';

        Http::fake([$imageUrl => Http::response($imageContent, 200, ['Content-Type' => 'application/octet-stream'])]);
        $this->loggerMock->shouldReceive('info')->once();

        $service = new AudibleService();
        $filePath = $service->downloadCoverImage($imageUrl, $asin);

        $this->assertNotNull($filePath);
        $this->assertEquals('covers/' . $asin . '.png', $filePath);
        Storage::disk('public')->assertExists($filePath);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testCreatesDirectoryIfNotExists()
    {
        Storage::fake('public');
        $imageUrl = 'https://m.media-amazon.com/images/I/51Q42D63G5L.jpg';
        $asin = 'B002V1O1QK';
        $imageContent = 'fake-image-data';
        $subDirectory = 'new-covers/sub';

        Http::fake([$imageUrl => Http::response($imageContent, 200, ['Content-Type' => 'image/jpeg'])]);
        $this->loggerMock->shouldReceive('info')->once();
        Storage::disk('public')->assertMissing($subDirectory);

        $service = new AudibleService();
        $filePath = $service->downloadCoverImage($imageUrl, $asin, $subDirectory);

        $this->assertNotNull($filePath);
        $this->assertEquals($subDirectory . '/' . $asin . '.jpg', $filePath);
        Storage::disk('public')->assertExists($subDirectory);
        Storage::disk('public')->assertExists($filePath);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testHandlesHttpErrorGracefully()
    {
        Storage::fake('public');
        $imageUrl = 'https://m.media-amazon.com/images/I/invalid.jpg';
        $asin = 'B002V1O1QK';

        Http::fake([$imageUrl => Http::response(null, 404)]);
        $this->loggerMock->shouldReceive('error')->once();

        $service = new AudibleService();
        $filePath = $service->downloadCoverImage($imageUrl, $asin);

        $this->assertNull($filePath);
        Storage::disk('public')->assertMissing('covers/' . $asin . '.jpg');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testHandlesStoragePutFailure()
    {
        $imageUrl = 'https://m.media-amazon.com/images/I/51Q42D63G5L.jpg';
        $asin = 'B002V1O1QK';
        $expectedPath = 'covers/' . $asin . '.jpg';

        Http::fake([$imageUrl => Http::response('fake-data', 200, ['Content-Type' => 'image/jpeg'])]);

        $diskMock = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);

        // Expect 'exists' to be called and return false (directory does not exist)
        $diskMock->shouldReceive('exists')
            ->with('covers')
            ->once()
            ->andReturn(false);

        // Expect 'makeDirectory' to be called
        $diskMock->shouldReceive('makeDirectory')
            ->with('covers')
            ->once()
            ->andReturn(true);

        // Mock 'put' to return false, simulating a storage failure
        $diskMock->shouldReceive('put')
            ->with($expectedPath, 'fake-data')
            ->once()
            ->andReturn(false);

        Storage::shouldReceive('disk')->with('public')->andReturn($diskMock);

        // Expect the 'Failed to save image' error log on the shared logger mock
        $this->loggerMock->shouldReceive('error')
            ->with('AudibleService: Failed to save image to storage.', ['path' => $expectedPath])
            ->once();

        // Call downloadCoverImage on the service instance from setUp
        $result = $this->audibleService->downloadCoverImage($imageUrl, $asin, 'covers');

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testDefaultsToJpgWhenExtensionIsUnknown()
    {
        Storage::fake('public');
        $imageUrl = 'https://m.media-amazon.com/images/I/51Q42D63G5L';
        $asin = 'B002V1O1QK';

        Http::fake([$imageUrl => Http::response('fake-data', 200, ['Content-Type' => 'application/octet-stream'])]);
        $this->loggerMock->shouldReceive('warning')->once();
        $this->loggerMock->shouldReceive('info')->once();

        $service = new AudibleService();
        $filePath = $service->downloadCoverImage($imageUrl, $asin);

        $this->assertEquals('covers/' . $asin . '.jpg', $filePath);
        Storage::disk('public')->assertExists($filePath);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testPerformSearchSuccessful()
    {
        $query = 'dune';
        $mockResponse = [
            'products' => [
                [
                    'asin' => 'B002V1O1QK',
                    'title' => 'Dune',
                    'contributors' => [
                        ['role' => 'author', 'name' => 'Frank Herbert'],
                        ['role' => 'narrator', 'name' => 'Scott Brick'],
                    ],
                    'product_images' => ['500' => 'http://example.com/cover.jpg'],
                    'merchandising_summary' => 'A desert planet...',
                    'series' => [['title' => 'Dune Saga', 'sequence' => '1']],
                    'release_date' => '2007-01-01',
                    'runtime_length_min' => 1260,
                ]
            ]
        ];

        Http::fake([
            'https://api.audible.com/1.0/catalog/products*' => Http::response($mockResponse, 200)
        ]);

        $service = new AudibleService();
        $results = $service->searchBooks($query);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('B002V1O1QK', $results[0]['id']);
        $this->assertEquals('Dune', $results[0]['title']);
        $this->assertEquals([['author' => ['name' => 'Frank Herbert', 'id' => null]]], $results[0]['authors']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testPerformSearchApiError()
    {
        $query = 'dune';
        Http::fake([
            'https://api.audible.com/1.0/catalog/products?*' => Http::response(null, 500)
        ]);

        $this->loggerMock->shouldReceive('error')->once();

        $service = new AudibleService();
        $results = $service->searchBooks($query);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testPerformGetBookDetailsSuccessful()
    {
        $asin = 'B002V1O1QK';
        $mockResponse = [
            'product' => [
                'asin' => $asin,
                'title' => 'Dune',
                'authors' => [['name' => 'Frank Herbert']],
                'narrators' => [['name' => 'Scott Brick']],
                'product_images' => ['500' => 'http://example.com/cover.jpg'],
                'merchandising_summary' => 'A desert planet...',
                'series' => [['title' => 'Dune Saga']],
                'release_date' => '2007-01-01',
                'runtime_length_min' => 1260,
            ]
        ];

        $url = 'https://api.audible.com/1.0/catalog/products/' . $asin . '*';
        Http::fake([
            $url => Http::response($mockResponse, 200)
        ]);

        $service = new AudibleService();
        $details = $service->performGetBookDetails($asin);

        $this->assertIsArray($details);
        $this->assertEquals($asin, $details['id']);
        $this->assertEquals('Dune', $details['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testPerformGetBookDetailsNotFound()
    {
        $asin = 'NOT_FOUND';
        $url = 'https://api.audible.com/1.0/catalog/products/' . $asin . '*';
        Http::fake([
            $url => Http::response(['product' => null], 200)
        ]);

        $service = new AudibleService();
        $details = $service->performGetBookDetails($asin);

        $this->assertNull($details);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testPerformGetBookDetailsApiError()
    {
        $asin = 'ERROR_ASIN';
        $url = 'https://api.audible.com/1.0/catalog/products/' . $asin . '*';
        Http::fake([
            $url => Http::response(null, 500)
        ]);

        $this->loggerMock->shouldReceive('error')->once();

        $service = new AudibleService();
        $details = $service->performGetBookDetails($asin);

        $this->assertNull($details);
    }
}
