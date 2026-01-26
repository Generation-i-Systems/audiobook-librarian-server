<?php

namespace Tests\Import\Unit\Services;

use App\Services\ImportUIService;
use Tests\TestCase;

class ImportUIServiceDirectoryLabelTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function directoryLabelUsesDirectoryPathWhenPresent(): void
    {
        $uiService = new ImportUIServiceDirectoryLabelTestDouble();
        $uiService->setCurrentBook([
            'directory_path' => 'Fantasy/Author/Title',
            'source_path' => '/media/download/Title.m4b',
        ]);

        $this->assertSame('Fantasy/Author/Title', $uiService->exposeDirectoryLabel());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function directoryLabelDoesNotFallbackToSourcePath(): void
    {
        $uiService = new ImportUIServiceDirectoryLabelTestDouble();
        $uiService->setCurrentBook([
            'directory_path' => '',
            'source_path' => '/media/download/Title.m4b',
        ]);

        $this->assertSame('N/A', $uiService->exposeDirectoryLabel());
    }
}

class ImportUIServiceDirectoryLabelTestDouble extends ImportUIService
{
    protected function renderFull(): void
    {
    }

    public function render(): void
    {
    }

    public function table(array $headers, array $rows): void
    {
    }

    public function exposeDirectoryLabel(): string
    {
        return $this->getDirectoryLabel();
    }
}
