<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Tests\TestCase;

class BookImportServiceExtractSeriesNumberBareFallbackTest extends TestCase
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function bareTrailingNumberIsNotStrippedFromStandaloneTitleWithoutKnownSeries(): void
    {
        $metadata = ['title' => 'Fahrenheit 451'];
        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertSame('Fahrenheit 451', $metadata['title']);
        $this->assertArrayNotHasKey('series_number', $metadata);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function bareTrailingNumberIsStrippedWhenSeriesIsAlreadyKnownAndTitleDiffersFromSeries(): void
    {
        $metadata = ['title' => 'The Final Empire 1', 'series' => 'Mistborn'];
        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertSame('The Final Empire', $metadata['title']);
        $this->assertSame(1, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function bareTrailingNumberIsKeptWhenTitleIsJustSeriesNamePlusNumber(): void
    {
        $metadata = ['title' => 'Mistborn 3', 'series' => 'Mistborn'];
        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertSame('Mistborn 3', $metadata['title']);
        $this->assertSame(3, $metadata['series_number']);
    }
}
