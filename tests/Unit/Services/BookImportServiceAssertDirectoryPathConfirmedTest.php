<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Tests\TestCase;

class BookImportServiceAssertDirectoryPathConfirmedTest extends TestCase
{
    private BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookImportService(
            app(GenreMappingService::class),
            app(SourceTrashService::class)
        );
    }

    private function invoke(array $metadata, string $sourcePath, bool $isAutoMode): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('assertDirectoryPathConfirmed');
        $method->setAccessible(true);
        $method->invoke($this->service, $metadata, $sourcePath, $isAutoMode);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNotThrowWhenCustomDirectoryPathIsSet(): void
    {
        $this->invoke(['custom_directory_path' => 'Fantasy/Anthology/My Big Fat Supernatural Honeymoon'], '/media/download/Book', false);
        $this->addToAssertionCount(1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function throwsWhenCustomDirectoryPathMissingAfterInteractiveConfirmation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("user confirmation");

        $this->invoke([], '/media/download/My Big Fat Supernatural Honeymoon Anthology', false);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function throwsWhenCustomDirectoryPathMissingInAutoMode(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("auto-mode processing");

        $this->invoke([], '/media/download/Book', true);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function throwsWhenCustomDirectoryPathIsEmptyString(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invoke(['custom_directory_path' => ''], '/media/download/Book', false);
    }
}
