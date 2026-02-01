<?php

namespace Tests\Cli\Unit\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use App\Services\BookImportService;
use App\Services\CoverImageAnalysisService;
use Mockery;
use Tests\TestCase;

class ImportBooksFromDownloadsTextOnWhiteCoverTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function isTextOnWhiteCoverDelegatesToImportService(): void
    {
        $importService = Mockery::mock(BookImportService::class);
        $coverAnalysisService = Mockery::mock(CoverImageAnalysisService::class);

        $command = new ImportBooksFromDownloadsTextOnWhiteCoverTestDouble($importService, $coverAnalysisService);

        $imagePath = '/tmp/cover.jpg';

        $importService->shouldReceive('isTextOnWhiteCover')
            ->once()
            ->with($imagePath, $coverAnalysisService)
            ->andReturn(true);

        $result = $command->exposeIsTextOnWhiteCover($imagePath);

        $this->assertTrue($result);
    }
}

class ImportBooksFromDownloadsTextOnWhiteCoverTestDouble extends ImportBooksFromDownloads
{
    private readonly BookImportService $testImportService;

    public function __construct(BookImportService $importService, CoverImageAnalysisService $coverAnalysisService)
    {
        parent::__construct(null);

        $this->testImportService = $importService;
        $this->coverAnalysisService = $coverAnalysisService;
    }

    protected function getImportService(): BookImportService
    {
        return $this->testImportService;
    }

    public function exposeIsTextOnWhiteCover(string $imagePath): bool
    {
        return $this->isTextOnWhiteCover($imagePath);
    }
}
