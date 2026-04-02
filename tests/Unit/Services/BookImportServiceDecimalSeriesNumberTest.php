<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Tests\TestCase;

class BookImportServiceDecimalSeriesNumberTest extends TestCase
{
    private BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookImportService(
            app(GenreMappingService::class),
            app(\App\Services\SourceTrashService::class)
        );
    }

    // -------------------------------------------------------------------------
    // extractSeriesNumberFromTitle
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleParsesDecimalBookKeyword(): void
    {
        $metadata = ['title' => 'Inheritance Book 3.5'];
        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertSame('Inheritance', $metadata['title']);
        $this->assertSame(3.5, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleParsesDecimalCommaBook(): void
    {
        $metadata = ['title' => 'Inheritance, Book 3.5'];
        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertSame('Inheritance', $metadata['title']);
        $this->assertSame(3.5, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleParsesDecimalHash(): void
    {
        $metadata = ['title' => 'Inheritance #3.5'];
        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertSame('Inheritance', $metadata['title']);
        $this->assertSame(3.5, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleKeepsIntegerAsInt(): void
    {
        $metadata = ['title' => 'Inheritance Book 3'];
        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertSame('Inheritance', $metadata['title']);
        $this->assertSame(3, $metadata['series_number']);
        $this->assertIsInt($metadata['series_number']);
    }

    // -------------------------------------------------------------------------
    // parseFilenameForMetadata
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFilenameForMetadataParsesDecimalBookKeyword(): void
    {
        $result = $this->service->parseFilenameForMetadata(
            'Inheritance Book 3.5 - The Awakened Kingdom.m4b'
        );

        $this->assertSame('Inheritance', $result['series']);
        $this->assertSame(3.5, $result['series_number']);
        $this->assertSame('The Awakened Kingdom', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFilenameForMetadataParsesDecimalHashSeries(): void
    {
        $result = $this->service->parseFilenameForMetadata(
            'Author Name - Series Name #3.5 - Book Title.m4b'
        );

        $this->assertSame('Series Name', $result['series']);
        $this->assertSame(3.5, $result['series_number']);
        $this->assertSame('Book Title', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseFilenameForMetadataKeepsIntegerSeriesAsInt(): void
    {
        $result = $this->service->parseFilenameForMetadata(
            'Author Name - Series Name Book 3 - Book Title.m4b'
        );

        $this->assertSame(3, $result['series_number']);
        $this->assertIsInt($result['series_number']);
    }

    // -------------------------------------------------------------------------
    // formatSeriesNumberForTitlePrefix (via directory path generation)
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function decimalSeriesNumberFormatsWithLeadingZeroedIntegerPart(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('formatSeriesNumberForTitlePrefix');
        $method->setAccessible(true);

        $this->assertSame('03.5', $method->invoke($this->service, 3.5));
        $this->assertSame('03.5', $method->invoke($this->service, '3.5'));
        $this->assertSame('10.5', $method->invoke($this->service, 10.5));
        $this->assertSame('03', $method->invoke($this->service, 3));
    }
}
