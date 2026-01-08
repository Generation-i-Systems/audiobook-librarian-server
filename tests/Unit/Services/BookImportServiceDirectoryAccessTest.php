<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use org\bovigo\vfs\vfsStream;
use Tests\TestCase;

class BookImportServiceDirectoryAccessTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function processAudiobookDirectoryReturnsNullWhenPathUnreadable(): void
    {
        $root = vfsStream::setup('root');
        $lockedDir = vfsStream::newDirectory('locked', 0000);
        $root->addChild($lockedDir);

        $service = app(BookImportService::class);

        $this->assertNull($service->processAudiobookDirectory($lockedDir->url()));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function scanForAudiobooksSkipsUnreadableDirectories(): void
    {
        $root = vfsStream::setup('root');
        $lockedDir = vfsStream::newDirectory('locked', 0000);
        $root->addChild($lockedDir);

        $service = app(BookImportService::class);
        $messages = [];

        $result = $service->scanForAudiobooks(
            [$lockedDir->url()],
            null,
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        );

        $this->assertSame([], $result);
        $this->assertNotEmpty(
            array_filter(
                $messages,
                static fn (string $message): bool => str_contains($message, 'Skipping inaccessible directory')
            )
        );
    }
}
