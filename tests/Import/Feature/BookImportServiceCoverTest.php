<?php

namespace Tests\Import\Feature;

use App\Services\BookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookImportServiceCoverTest extends TestCase
{
    use RefreshDatabase;

    private BookImportService $service;
    private string $testDirectory;
    private string $relativeDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BookImportService::class);
        $this->relativeDirectory = 'test_books';
        $this->testDirectory = storage_path('app/' . $this->relativeDirectory);

        $this->app['config']->set('app.book_root', storage_path('app'));
        $this->app['config']->set('filesystems.disks.books.root', storage_path('app'));

        // Create test directory
        if (!File::exists($this->testDirectory)) {
            File::makeDirectory($this->testDirectory, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (File::exists($this->testDirectory)) {
            File::deleteDirectory($this->testDirectory);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_downloads_cover_image_from_url(): void
    {
        // Create a real test image file to serve
        $fakeImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $tempFile = tempnam(sys_get_temp_dir(), 'test_cover_') . '.jpg';
        file_put_contents($tempFile, $fakeImageData);

        // Use file:// URL for testing
        $result = $this->service->downloadCoverImage(
            'file://' . $tempFile,
            $this->relativeDirectory,
            'audible'
        );

        $this->assertNotNull($result);
        $this->assertEquals('cover_audible.jpg', $result);
        $this->assertFileExists($this->testDirectory . '/cover_audible.jpg');

        // Cleanup
        @unlink($tempFile);
    }

    #[Test]
    public function it_returns_null_when_download_fails(): void
    {
        // Use non-existent file URL
        $result = $this->service->downloadCoverImage(
            'file:///nonexistent/path/cover.jpg',
            $this->relativeDirectory,
            'audible'
        );

        $this->assertNull($result);
    }

    #[Test]
    public function it_determines_correct_extension_from_url(): void
    {
        $fakeImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $tempFile = tempnam(sys_get_temp_dir(), 'test_cover_') . '.png';
        file_put_contents($tempFile, $fakeImageData);

        $result = $this->service->downloadCoverImage(
            'file://' . $tempFile,
            $this->relativeDirectory,
            'googlebooks'
        );

        $this->assertEquals('cover_googlebooks.png', $result);
        $this->assertFileExists($this->testDirectory . '/cover_googlebooks.png');

        @unlink($tempFile);
    }

    #[Test]
    public function it_defaults_to_jpg_for_unknown_extensions(): void
    {
        $fakeImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $tempFile = tempnam(sys_get_temp_dir(), 'test_cover_');
        file_put_contents($tempFile, $fakeImageData);

        $result = $this->service->downloadCoverImage(
            'file://' . $tempFile,
            $this->relativeDirectory,
            'audible'
        );

        $this->assertEquals('cover_audible.jpg', $result);
        $this->assertFileExists($this->testDirectory . '/cover_audible.jpg');

        @unlink($tempFile);
    }

    #[Test]
    public function it_sets_correct_file_permissions(): void
    {
        $fakeImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $tempFile = tempnam(sys_get_temp_dir(), 'test_cover_') . '.jpg';
        file_put_contents($tempFile, $fakeImageData);

        $result = $this->service->downloadCoverImage(
            'file://' . $tempFile,
            $this->relativeDirectory,
            'audible'
        );

        $filePath = $this->testDirectory . '/' . $result;
        $this->assertFileExists($filePath);

        // Check permissions (0664 = rw-rw-r--)
        $perms = fileperms($filePath) & 0777;
        $this->assertEquals(0664, $perms);

        @unlink($tempFile);
    }
}
