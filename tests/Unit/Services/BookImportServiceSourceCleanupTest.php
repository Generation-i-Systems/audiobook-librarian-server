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

        $this->assertFileDoesNotExist($filePath);
        $this->assertFalse(File::exists($baseDir));
        $this->assertNotEmpty($logMessages);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanupSourceDirectoryRemovesNestedDirectories(): void
    {
        $service = app(BookImportService::class);

        $baseDir = sys_get_temp_dir() . '/cleanup_dir_' . uniqid('', true);
        $nestedDir = $baseDir . '/disc1';
        File::makeDirectory($nestedDir, 0755, true);

        $filePath = $nestedDir . '/track1.m4b';
        file_put_contents($filePath, 'dummy data');

        $metadata = ['path' => $baseDir];

        $service->cleanupSourceDirectory($metadata, false, false);

        $this->assertFalse(File::exists($filePath));
        $this->assertFalse(File::exists($nestedDir));
        $this->assertFalse(File::exists($baseDir));
    }
}
