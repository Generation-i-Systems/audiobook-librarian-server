<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Services\GoogleBooksApiService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class GoogleBooksApiServiceEnhanceTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/gbapi_enhance_' . uniqid();
        mkdir($this->testDir, 0777, true);

        $this->app['config']->set('filesystems.disks.books.driver', 'local');
        $this->app['config']->set('filesystems.disks.books.root', $this->testDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $fs = new Filesystem();
            $fs->deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function cover_image_is_downloaded_and_path_is_set()
    {
        // Create a partial mock of GoogleBooksApiService to control the performSearch method
        $service = $this->getMockBuilder(GoogleBooksApiService::class)
            ->onlyMethods(['performSearch'])
            ->getMock();

        $coverUrl = 'https://via.placeholder.com/150';
        $relativeDirectoryPath = 'Test Author/Test Book';
        $book = [
            'title' => 'Test Book',
            'authors' => [['author' => ['name' => 'Test Author']]],
            'directoryPath' => $relativeDirectoryPath,
        ];

        // Mock the API response - using flattened format expected by searchAndMerge
        $mockResults = [
            [
                'id' => 'test_id',
                'title' => 'Test Book',
                'authors' => [
                    ['author' => ['name' => 'Test Author']],
                ],
                'cover_image_url' => $coverUrl,
            ],
        ];

        // Configure the mock to return our test data
        $service->expects($this->once())
            ->method('performSearch')
            ->willReturn($mockResults);

        // Mock HTTP facade to simulate successful download
        Http::fake([
            $coverUrl => Http::response('fake image data', 200),
        ]);

        // Call the method we're testing
        $result = $service->searchAndMerge($book);

        // Assertions
        $this->assertNotNull($result, 'searchAndMerge should not return null');
        $this->assertArrayHasKey('coverImage', $result);
        $this->assertSame('cover.jpg', $result['coverImage']);

        // Verify the file was actually created
        $this->assertTrue(
            Storage::disk('books')->exists($relativeDirectoryPath . '/cover.jpg')
        );
    }
}
