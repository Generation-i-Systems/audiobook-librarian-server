<?php

namespace Tests\Import\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceMoveNonAudioFilesToDirectoryTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function moveNonAudioFilesToDirectoryMovesCoversAndJsonButNotAudio(): void
    {
        $service = new BookImportServiceMoveNonAudioFilesToDirectoryTestDouble(app(GenreMappingService::class), app(\App\Services\SourceTrashService::class));

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('moveNonAudioFilesToDirectory');
        $method->setAccessible(true);

        $coverFile = new class () {
            public function getPathname(): string
            {
                return '/src/cover_audible.jpg';
            }

            public function getFilename(): string
            {
                return 'cover_audible.jpg';
            }
        };

        $jsonFile = new class () {
            public function getPathname(): string
            {
                return '/src/librarian.json';
            }

            public function getFilename(): string
            {
                return 'librarian.json';
            }
        };

        $audioFile = new class () {
            public function getPathname(): string
            {
                return '/src/book.m4b';
            }

            public function getFilename(): string
            {
                return 'book.m4b';
            }
        };

        File::shouldReceive('isDirectory')->with('/src')->andReturn(true);
        File::shouldReceive('isDirectory')->with('/dst')->andReturn(true);

        File::shouldReceive('files')->with('/src')->andReturn([$coverFile, $jsonFile, $audioFile]);

        File::shouldReceive('move')->once()->with('/src/cover_audible.jpg', '/dst/cover_audible.jpg');
        File::shouldReceive('move')->once()->with('/src/librarian.json', '/dst/librarian.json');
        File::shouldReceive('move')->never()->with('/src/book.m4b', '/dst/book.m4b');

        File::shouldReceive('allFiles')->with('/src')->andReturn([]);
        File::shouldReceive('deleteDirectory')->once()->with('/src');

        $method->invoke($service, '/src', '/dst');
    }
}

class BookImportServiceMoveNonAudioFilesToDirectoryTestDouble extends BookImportService
{
    protected function setDirectoryOwnership(string $path): void
    {
    }

    protected function setFileOwnership(string $path): void
    {
    }
}
