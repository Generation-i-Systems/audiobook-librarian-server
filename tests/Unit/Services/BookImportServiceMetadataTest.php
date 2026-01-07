<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $genreMappingService = $this->app->make(GenreMappingService::class);
        $this->service = new BookImportService($genreMappingService);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReusesDirectoryWithOnlyLibrarianJson(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Create target directory with only librarian.json
            $targetDir = $tempDir . '/target';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/librarian.json', '{"title": "Test Book"}');

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir, $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReusesDirectoryWithOnlyCoverImage(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Create target directory with only cover image
            $targetDir = $tempDir . '/target';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/cover.jpg', 'cover content');

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir, $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCreatesSuffixedDirectoryWhenTargetHasAudioFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Create target directory with audio files (conflict)
            $targetDir = $tempDir . '/target';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/track01.mp3', 'existing audio');

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir . '_01', $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReusesDirectoryWithMixedMetadataFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Create target directory with multiple metadata files
            $targetDir = $tempDir . '/target';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/librarian.json', '{"title": "Test"}');
            file_put_contents($targetDir . '/cover.jpg', 'cover content');
            file_put_contents($targetDir . '/cover.png', 'png cover');

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir, $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCreatesSuffixedDirectoryForNonMetadataFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Create target directory with non-metadata file
            $targetDir = $tempDir . '/target';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/readme.txt', 'not metadata');

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir . '_01', $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsOriginalWhenDirectoryDoesNotExist(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Don't create the target directory
            $targetDir = $tempDir . '/nonexistent';

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir, $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }
}
