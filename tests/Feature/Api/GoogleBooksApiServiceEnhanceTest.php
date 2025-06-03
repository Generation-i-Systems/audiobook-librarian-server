<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Services\GoogleBooksApiService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;

class GoogleBooksApiServiceEnhanceTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/gbapi_enhance_' . uniqid();
        mkdir($this->testDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $fs = new Filesystem();
            $fs->deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function coverImageIsDownloadedAndPathIsSet()
    {
        $service = new GoogleBooksApiService();
        $coverUrl = 'https://via.placeholder.com/150';
        $book = [
            'title' => 'Test Book',
            'authors' => [['author' => ['name' => 'Test Author']]],
            'directory_path' => $this->testDir,
        ];
        $enriched = [
            'id' => 'test_id',
            'title' => 'Test Book',
            'authors' => [['author' => ['name' => 'Test Author']]],
            'cover_image_url' => $coverUrl,
        ];
        // Simulate the merge logic
        $merged = [
            'googlebooks_id' => $enriched['id'],
            'title' => $enriched['title'],
            'cover_image' => $enriched['cover_image_url'],
            'authors' => $enriched['authors'],
        ];
        // Actually call the searchAndMerge logic
        $result = $service->searchAndMerge([
            ...$book,
            // force cover_image_url through the mock
        ]);
        $coverPath = $this->testDir . '/cover.jpg';
        $this->assertTrue(file_exists($coverPath) || file_exists($this->testDir . '/cover.png'));
        $this->assertArrayHasKey('cover_image', $result);
        $this->assertStringContainsString($this->testDir, $result['cover_image']);
    }
}
