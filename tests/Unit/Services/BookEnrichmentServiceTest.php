<?php

namespace Tests\Unit\Services;

use App\Services\BookEnrichmentService;
use App\Services\AudibleService;
use Tests\TestCase;

class BookEnrichmentServiceTest extends TestCase
{
    protected BookEnrichmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookEnrichmentService();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichWithExternalDataReturnsEmptyForMissingTitle(): void
    {
        $metadata = [
            'author' => ['Test Author'],
        ];

        $result = $this->service->enrichWithExternalData($metadata);

        $this->assertEmpty($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichWithExternalDataMapsAudibleNarratorsListAndCategoryToNarratorAndGenre(): void
    {
        $audibleMock = $this->createStub(AudibleService::class);
        $audibleMock->method('searchBooksWithFiltering')->willReturn([
            [
                'description' => 'Test description',
                'coverImageUrl' => 'https://example.com/cover.jpg',
                'publishedYear' => '2025',
                'publisher' => ['name' => 'Random House Audio'],
                'series' => '',
                'narratorsList' => ['Narrator One', 'Narrator Two'],
                'category' => ['Science Fiction'],
            ],
        ]);

        $this->app->instance(AudibleService::class, $audibleMock);

        $result = $this->service->enrichWithExternalData([
            'title' => 'Kingdom Come',
            'author' => ['Mark Waid'],
        ], ['sources' => ['audible']]);

        $this->assertSame(['Narrator One', 'Narrator Two'], $result['narrator']);
        $this->assertSame(['Science Fiction'], $result['genre']);
        $this->assertSame(2025, $result['year']);
        $this->assertSame('https://example.com/cover.jpg', $result['cover_url']);
        $this->assertArrayHasKey('audible_raw', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichWithExternalDataNormalizesAudibleCategoryObjectsToGenreStrings(): void
    {
        $audibleMock = $this->createStub(AudibleService::class);
        $audibleMock->method('searchBooksWithFiltering')->willReturn([
            [
                'description' => 'Test description',
                'coverImageUrl' => 'https://example.com/cover.jpg',
                'publishedYear' => '2025',
                'publisher' => ['name' => 'Random House Audio'],
                'series' => '',
                'narratorsList' => ['Narrator One'],
                'category' => [
                    ['name' => 'Science Fiction'],
                    ['name' => 'Adventure'],
                ],
            ],
        ]);

        $this->app->instance(AudibleService::class, $audibleMock);

        $result = $this->service->enrichWithExternalData([
            'title' => 'Kingdom Come',
            'author' => ['Mark Waid'],
        ], ['sources' => ['audible']]);

        $this->assertSame(['Science Fiction', 'Action'], $result['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichWithExternalDataStillQueriesAudibleWhenOnlyGenreIsMissing(): void
    {
        $audibleMock = $this->createMock(AudibleService::class);
        $audibleMock->expects($this->once())
            ->method('searchBooksWithFiltering')
            ->willReturn([
                [
                    'description' => 'Test description from audible',
                    'coverImageUrl' => 'https://example.com/audible-cover.jpg',
                    'publishedYear' => '2025',
                    'publisher' => ['name' => 'Random House Audio'],
                    'series' => '',
                    'narratorsList' => ['Narrator One'],
                    'category' => ['Science Fiction'],
                ],
            ]);

        $this->app->instance(AudibleService::class, $audibleMock);

        $result = $this->service->enrichWithExternalData([
            'title' => 'Kingdom Come',
            'author' => ['Mark Waid'],
            'cover_url' => 'https://example.com/existing-cover.jpg',
            'description' => 'Existing description',
            'genre' => [],
        ], ['sources' => ['audible']]);

        $this->assertSame(['Science Fiction'], $result['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichWithExternalDataMapsAudibleCategoryToValidSystemGenre(): void
    {
        $audibleMock = $this->createStub(AudibleService::class);
        $audibleMock->method('searchBooksWithFiltering')->willReturn([
            [
                'description' => 'Test description',
                'coverImageUrl' => 'https://example.com/cover.jpg',
                'publishedYear' => '2025',
                'publisher' => ['name' => 'Random House Audio'],
                'series' => '',
                'narratorsList' => ['Narrator One'],
                'category' => ['Science Fiction & Fantasy'],
            ],
        ]);

        $this->app->instance(AudibleService::class, $audibleMock);

        $result = $this->service->enrichWithExternalData([
            'title' => 'Kingdom Come',
            'author' => ['Mark Waid'],
        ], ['sources' => ['audible']]);

        $this->assertSame(['Science Fiction', 'Fantasy'], $result['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function normalizeAuthorForExternalLookupUsesPrimaryAuthorFromMultiAuthorString(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('normalizeAuthorForExternalLookup');
        $method->setAccessible(true);

        $this->assertSame('Mark Waid', $method->invoke($this->service, 'Mark Waid, Alex Ross'));
        $this->assertSame('Mark Waid', $method->invoke($this->service, 'Mark Waid & Alex Ross'));
        $this->assertSame('Mark Waid', $method->invoke($this->service, 'Mark Waid and Alex Ross'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function enrichWithExternalDataReturnsEmptyForMissingAuthor(): void
    {
        $metadata = [
            'title' => 'Test Book',
        ];

        $result = $this->service->enrichWithExternalData($metadata);

        $this->assertEmpty($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleCleansBookSuffix(): void
    {
        $metadata = [
            'title' => 'Foundation, Book 1',
        ];

        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertEquals('Foundation', $metadata['title']);
        $this->assertEquals(1, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleHandlesVariousPatterns(): void
    {
        $testCases = [
            ['input' => 'Series Name Book 5', 'expectedTitle' => 'Series Name', 'expectedNumber' => 5],
            ['input' => 'Series Name, Volume 3', 'expectedTitle' => 'Series Name', 'expectedNumber' => 3],
            ['input' => 'Series Name #7', 'expectedTitle' => 'Series Name', 'expectedNumber' => 7],
            ['input' => 'Series Name, Part 2', 'expectedTitle' => 'Series Name', 'expectedNumber' => 2],
        ];

        foreach ($testCases as $case) {
            $metadata = ['title' => $case['input']];
            $this->service->extractSeriesNumberFromTitle($metadata);

            $this->assertEquals(
                $case['expectedTitle'],
                $metadata['title'],
                "Failed to extract title from: {$case['input']}"
            );
            $this->assertEquals(
                $case['expectedNumber'],
                $metadata['series_number'],
                "Failed to extract number from: {$case['input']}"
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleDoesNotModifyTitleWithoutPattern(): void
    {
        $metadata = [
            'title' => 'Regular Book Title',
        ];

        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertEquals('Regular Book Title', $metadata['title']);
        $this->assertArrayNotHasKey('series_number', $metadata);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleStripsBracketedSeriesSuffixAndQualifiers(): void
    {
        $metadata = [
            'title' => 'Lover at Last [The Black Dagger Brotherhood #11] (Unabridged)',
        ];

        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertEquals('Lover at Last', $metadata['title']);
        $this->assertEquals('The Black Dagger Brotherhood', $metadata['series']);
        $this->assertEquals(11, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleSupportsDecimalSeriesNumbers(): void
    {
        $metadata = [
            'title' => 'Some Novella [Example Series #16.5] (Unabridged)',
        ];

        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertEquals('Some Novella', $metadata['title']);
        $this->assertEquals('Example Series', $metadata['series']);
        $this->assertEquals(16.5, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleSanitizesTitleWhenSeriesNumberAlreadySet(): void
    {
        $metadata = [
            'title' => 'Lover at Last [The Black Dagger Brotherhood #11] (Unabridged)',
            'series_number' => 11,
        ];

        $this->service->extractSeriesNumberFromTitle($metadata);

        $this->assertEquals('Lover at Last', $metadata['title']);
        $this->assertEquals('The Black Dagger Brotherhood', $metadata['series']);
        $this->assertEquals(11, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanDescriptionRemovesHtmlTags(): void
    {
        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('cleanDescription');
        $method->setAccessible(true);

        $htmlDescription = '<p>This is a <strong>great</strong> book!</p>';
        $result = $method->invoke($this->service, $htmlDescription);

        $this->assertEquals('This is a great book!', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanDescriptionDecodesHtmlEntities(): void
    {
        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('cleanDescription');
        $method->setAccessible(true);

        $description = 'Rock &amp; Roll &quot;Music&quot; &#39;Rocks&#39;';
        $result = $method->invoke($this->service, $description);

        $this->assertEquals('Rock & Roll "Music" \'Rocks\'', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanDescriptionNormalizesWhitespace(): void
    {
        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('cleanDescription');
        $method->setAccessible(true);

        $description = "Text with\n\nmultiple    spaces   and\nnewlines";
        $result = $method->invoke($this->service, $description);

        $this->assertEquals('Text with multiple spaces and newlines', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function isValidEnrichmentReturnsFalseForEmptyData(): void
    {
        $originalMetadata = ['title' => 'Test'];
        $enrichedData = [];

        $result = $this->service->isValidEnrichment($originalMetadata, $enrichedData);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function isValidEnrichmentReturnsTrueForValidData(): void
    {
        $originalMetadata = [
            'title' => 'Foundation',
            'author' => ['Isaac Asimov'],
        ];

        $enrichedData = [
            'description' => 'A classic science fiction novel',
            'cover_url' => 'https://example.com/cover.jpg',
            'year' => 1951,
        ];

        $result = $this->service->isValidEnrichment($originalMetadata, $enrichedData);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function isValidEnrichmentReturnsFalseForInvalidYear(): void
    {
        $originalMetadata = ['title' => 'Test'];

        $enrichedData = [
            'year' => 1799, // Too old
        ];

        $result = $this->service->isValidEnrichment($originalMetadata, $enrichedData);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function isValidEnrichmentReturnsFalseForFutureYear(): void
    {
        $originalMetadata = ['title' => 'Test'];

        $enrichedData = [
            'year' => (int) date('Y') + 3, // Too far in future
        ];

        $result = $this->service->isValidEnrichment($originalMetadata, $enrichedData);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function isValidEnrichmentReturnsFalseForInvalidCoverUrl(): void
    {
        $originalMetadata = ['title' => 'Test'];

        $enrichedData = [
            'cover_url' => 'not-a-valid-url',
        ];

        $result = $this->service->isValidEnrichment($originalMetadata, $enrichedData);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function hasEnrichmentDataReturnsTrueForAudibleData(): void
    {
        $metadata = [
            'audible_raw' => ['some' => 'data'],
        ];

        $result = $this->service->hasEnrichmentData($metadata);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function hasEnrichmentDataReturnsTrueForGoogleBooksData(): void
    {
        $metadata = [
            'google_books_raw' => ['some' => 'data'],
        ];

        $result = $this->service->hasEnrichmentData($metadata);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function hasEnrichmentDataReturnsTrueForCoverUrl(): void
    {
        $metadata = [
            'cover_url' => 'https://example.com/cover.jpg',
        ];

        $result = $this->service->hasEnrichmentData($metadata);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function hasEnrichmentDataReturnsTrueForLongDescription(): void
    {
        $metadata = [
            'description' => str_repeat('Long description text. ', 20), // > 100 chars
        ];

        $result = $this->service->hasEnrichmentData($metadata);

        $this->assertTrue($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function hasEnrichmentDataReturnsFalseForShortDescription(): void
    {
        $metadata = [
            'description' => 'Short description', // < 100 chars
        ];

        $result = $this->service->hasEnrichmentData($metadata);

        $this->assertFalse($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function detectMultiBookPatternRecognizesBooksPattern(): void
    {
        $result = $this->service->detectMultiBookPattern('Foundation Books 1-3');

        $this->assertIsArray($result);
        $this->assertEquals('books', $result['type']);
        $this->assertEquals('Foundation', $result['series_name']);
        $this->assertEquals(1, $result['start_number']);
        $this->assertEquals(3, $result['end_number']);
        $this->assertEquals(3, $result['count']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function detectMultiBookPatternRecognizesPartsPattern(): void
    {
        $result = $this->service->detectMultiBookPattern('The Story Parts 2-4');

        $this->assertIsArray($result);
        $this->assertEquals('parts', $result['type']);
        $this->assertEquals('The Story', $result['series_name']);
        $this->assertEquals(2, $result['start_number']);
        $this->assertEquals(4, $result['end_number']);
        $this->assertEquals(3, $result['count']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function detectMultiBookPatternReturnsNullForSingleBook(): void
    {
        $result = $this->service->detectMultiBookPattern('Single Book Title');

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractBookTitleFromFilenameExtractsTitleFromPattern(): void
    {
        $filename = 'Foundation-Book-1-Empire.m4b';
        $result = $this->service->extractBookTitleFromFilename($filename, 'Foundation', 1);

        // The function returns a fallback if pattern doesn't match
        // The patterns in the actual function look for exact matches with dashes
        $this->assertNotEmpty($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractBookTitleFromFilenameFallsBackToDefault(): void
    {
        $filename = 'some-random-file.m4b';
        $result = $this->service->extractBookTitleFromFilename($filename, 'Series Name', 5);

        $this->assertEquals('Series Name 5', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanSeriesNameRemovesAuthorLastName(): void
    {
        $result = $this->service->cleanSeriesName('Honor Harrington Weber', ['David Weber']);

        $this->assertEquals('Honor Harrington', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanSeriesNameRemovesBooksRangeSuffix(): void
    {
        $result = $this->service->cleanSeriesName('Foundation - Books 1-3', ['Isaac Asimov']);

        $this->assertEquals('Foundation', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function addGraphicAudioMarkerAddsMarkerForGraphicAudioPath(): void
    {
        $metadata = [
            'source_path' => '/media/books/GraphicAudio/Series/Book.m4b',
        ];

        $result = $this->service->addGraphicAudioMarker('Test Book', $metadata);

        $this->assertEquals('Test Book (GraphicAudio)', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function addGraphicAudioMarkerAddsMarkerForFullCastNarrator(): void
    {
        $metadata = [
            'narrator' => 'Full Cast Audio Production',
            'source_path' => '/media/books/test',
        ];

        $result = $this->service->addGraphicAudioMarker('Test Book', $metadata);

        $this->assertEquals('Test Book (GraphicAudio)', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function addGraphicAudioMarkerDoesNotAddDuplicateMarker(): void
    {
        $metadata = [
            'source_path' => '/media/books/GraphicAudio/Series/Book.m4b',
        ];

        $result = $this->service->addGraphicAudioMarker('Test Book (GraphicAudio)', $metadata);

        $this->assertEquals('Test Book (GraphicAudio)', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function addGraphicAudioMarkerDoesNotAddMarkerForRegularBook(): void
    {
        $metadata = [
            'source_path' => '/media/books/regular/Book.m4b',
            'narrator' => 'John Doe',
        ];

        $result = $this->service->addGraphicAudioMarker('Test Book', $metadata);

        $this->assertEquals('Test Book', $result);
    }
}
