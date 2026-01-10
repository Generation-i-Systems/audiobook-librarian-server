<?php

namespace Tests\Import\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceNormalizeSourcePathForImportTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function normalizeSourcePathForImportUnwrapsSingleRootDirectory(): void
    {
        $service = new BookImportService(app(GenreMappingService::class), app(\App\Services\SourceTrashService::class));

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('normalizeSourcePathForImport');
        $method->setAccessible(true);

        File::shouldReceive('isDirectory')
            ->with('/downloads/book')
            ->andReturn(true);
        File::shouldReceive('files')
            ->with('/downloads/book')
            ->andReturn([]);
        File::shouldReceive('directories')
            ->with('/downloads/book')
            ->andReturn(['/downloads/book/01 The Court of the Dead']);

        File::shouldReceive('isDirectory')
            ->with('/downloads/book/01 The Court of the Dead')
            ->andReturn(true);
        File::shouldReceive('files')
            ->with('/downloads/book/01 The Court of the Dead')
            ->andReturn(['/downloads/book/01 The Court of the Dead/file.m4b']);
        File::shouldReceive('directories')
            ->with('/downloads/book/01 The Court of the Dead')
            ->andReturn([]);

        $result = $method->invoke($service, '/downloads/book');
        $this->assertSame('/downloads/book/01 The Court of the Dead', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function normalizeSourcePathForImportDoesNotUnwrapWhenFilesExistAtRoot(): void
    {
        $service = new BookImportService(app(GenreMappingService::class), app(\App\Services\SourceTrashService::class));

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('normalizeSourcePathForImport');
        $method->setAccessible(true);

        File::shouldReceive('isDirectory')
            ->with('/downloads/book')
            ->andReturn(true);
        File::shouldReceive('files')
            ->with('/downloads/book')
            ->andReturn(['/downloads/book/file.m4b']);
        File::shouldReceive('directories')
            ->with('/downloads/book')
            ->andReturn(['/downloads/book/01 The Court of the Dead']);

        $result = $method->invoke($service, '/downloads/book');
        $this->assertSame('/downloads/book', $result);
    }
}
