<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceSourceCleanupTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanupSourceDirectoryRemovesSingleFile(): void
    {
        $service = app(BookImportService::class);

        $baseDir = sys_get_temp_dir() . '/cleanup_file_' . uniqid('', true);
        File::makeDirectory($baseDir, 0755, true);

        $filePath = $baseDir . '/book.m4b';
        file_put_contents($filePath, 'dummy data');

        $logMessages = [];
        $metadata = ['path' => $filePath];

        $service->cleanupSourceDirectory(
            $metadata,
            true,
            false,
            function (string $message) use (&$logMessages): void {
                $logMessages[] = $message;
            }
        );

        // With SourceTrashService, files are moved to trash instead of deleted
        // The source file should be moved to trash
        $this->assertFileDoesNotExist($filePath);
        $this->assertNotEmpty($logMessages);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanupSourceDirectoryRemovesNestedDirectories(): void
    {
        $service = app(BookImportService::class);

        // Create a wrapper directory to safely test parent directory scanning
        $wrapperDir = sys_get_temp_dir() . '/cleanup_wrapper_' . uniqid('', true);
        $baseDir = $wrapperDir . '/cleanup_dir_' . uniqid('', true);
        $nestedDir = $baseDir . '/disc1';

        File::makeDirectory($nestedDir, 0755, true);

        $filePath = $nestedDir . '/track1.m4b';
        file_put_contents($filePath, 'dummy data');

        $metadata = ['path' => $baseDir];

        $service->cleanupSourceDirectory($metadata, false, false);

        // With SourceTrashService, directories are moved to trash instead of deleted
        // The source directory should be moved to trash
        $this->assertFalse(File::exists($filePath));
        $this->assertFalse(File::exists($nestedDir));

        // Cleanup wrapper
        File::deleteDirectory($wrapperDir);
    }
}
