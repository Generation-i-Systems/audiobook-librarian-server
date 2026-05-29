<?php

namespace Tests\Unit\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Admin\ImportFileController;
use App\Services\BookImportService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportFileControllerMoveSelectedHelpersTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function normalizeSourcePathForMoveSelectedUnwrapsSingleRootDirectory(): void
    {
        $controller = new ImportFileControllerMoveSelectedHelpersTestDouble(
            $this->createStub(DocumentStoreServiceInterface::class),
            $this->createStub(BookImportService::class)
        );

        File::shouldReceive('isDirectory')->with('/src')->andReturn(true);
        File::shouldReceive('files')->with('/src')->andReturn([]);
        File::shouldReceive('directories')->with('/src')->andReturn(['/src/01 Title']);

        File::shouldReceive('isDirectory')->with('/src/01 Title')->andReturn(true);
        File::shouldReceive('files')->with('/src/01 Title')->andReturn(['/src/01 Title/book.m4b']);
        File::shouldReceive('directories')->with('/src/01 Title')->andReturn([]);

        $result = $controller->exposeNormalizeSourcePathForMoveSelected('/src');
        $this->assertSame('/src/01 Title', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function moveDirectoryContentsToTargetMovesFilesIntoDestWithoutNesting(): void
    {
        $controller = new ImportFileControllerMoveSelectedHelpersTestDouble(
            $this->createStub(DocumentStoreServiceInterface::class),
            $this->createStub(BookImportService::class)
        );

        $file1 = new class () {
            public function getPathname(): string
            {
                return '/src/a/book.m4b';
            }
        };

        $file2 = new class () {
            public function getPathname(): string
            {
                return '/src/cover.jpg';
            }
        };

        File::shouldReceive('isDirectory')->with('/dest')->andReturn(true);
        File::shouldReceive('allFiles')->with('/src')->andReturn([$file1, $file2]);

        File::shouldReceive('isDirectory')->with('/dest/a')->andReturn(false);
        File::shouldReceive('makeDirectory')->with('/dest/a', 0775, true);
        File::shouldReceive('exists')->with('/dest/a/book.m4b')->andReturn(false);
        File::shouldReceive('move')->with('/src/a/book.m4b', '/dest/a/book.m4b');

        File::shouldReceive('isDirectory')->with('/dest/.')->andReturn(true);
        File::shouldReceive('exists')->with('/dest/cover.jpg')->andReturn(false);
        File::shouldReceive('move')->with('/src/cover.jpg', '/dest/cover.jpg');

        File::shouldReceive('allFiles')->with('/src')->andReturn([]);
        File::shouldReceive('deleteDirectory')->with('/src');

        $controller->exposeMoveDirectoryContentsToTarget('/src', '/dest');

        $this->addToAssertionCount(1);
    }
}

class ImportFileControllerMoveSelectedHelpersTestDouble extends ImportFileController
{
    public function exposeNormalizeSourcePathForMoveSelected(string $sourcePath): string
    {
        return $this->normalizeSourcePathForMoveSelected($sourcePath);
    }

    public function exposeMoveDirectoryContentsToTarget(string $sourceDir, string $destDir): void
    {
        $this->moveDirectoryContentsToTarget($sourceDir, $destDir);
    }
}
