<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\OpenAudibleParser;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceMultiBookSplitGenericSeriesTest extends TestCase
{
    use RefreshDatabase;

    private string $booksDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->booksDir = sys_get_temp_dir() . '/generic_series_' . uniqid('', true) . '/books';
        File::makeDirectory($this->booksDir, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->booksDir));
        parent::tearDown();
    }

    private function makeService(?OpenAudibleParser $parser = null): BookImportService
    {
        return new BookImportService(
            $this->app->make(GenreMappingService::class),
            $this->app->make(SourceTrashService::class),
            null,
            $parser
        );
    }

    /**
     * @return array{0: array, 1: array, 2: array}
     */
    private function flatArchive(BookImportService $service, string $fileName): array
    {
        $file = $this->booksDir . '/' . $fileName;
        File::put($file, 'fake audio');

        $audiobook = ['path' => $this->booksDir, 'name' => 'books', 'files' => [$file]];
        $multiBookInfo = ['series_name' => 'books', 'numbers' => [1], 'is_flat_archive' => true];

        return [$audiobook, $multiBookInfo, $service->convertFlatFilesToSplitGroups([$file])];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function flatArchiveInGenericContainerUsesOpenAudibleSeriesForEachBook(): void
    {
        $parser = \Mockery::mock(OpenAudibleParser::class);
        $parser->shouldReceive('detectOpenAudibleDirectory')->andReturn(dirname($this->booksDir));
        $parser->shouldReceive('findBookByAudioFile')->andReturn([
            'title' => 'All Fired Up',
            'series' => 'Hometown Heroes',
            'series_name' => 'Hometown Heroes',
            'series_number' => '1',
            'series_sequence' => '1',
        ]);

        $service = $this->makeService($parser);
        [$audiobook, $multiBookInfo, $splitGroups] = $this->flatArchive($service, 'All Fired Up.m4b');

        $books = $service->processMultiBookSplit($audiobook, $multiBookInfo, $splitGroups, ['title' => 'books']);

        $this->assertCount(1, $books);
        $this->assertSame('Hometown Heroes', $books[0]['metadata']['series']);
        $this->assertEquals(1, $books[0]['metadata']['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function flatArchiveInGenericContainerNeverUsesContainerNameAsSeries(): void
    {
        $parser = \Mockery::mock(OpenAudibleParser::class);
        $parser->shouldReceive('detectOpenAudibleDirectory')->andReturn(null);

        $service = $this->makeService($parser);
        [$audiobook, $multiBookInfo, $splitGroups] = $this->flatArchive($service, 'Some Standalone.m4b');

        $infoMessages = [];
        $books = $service->processMultiBookSplit(
            $audiobook,
            $multiBookInfo,
            $splitGroups,
            ['title' => 'books'],
            function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            },
            fn (array $virtualAudiobook, array $bookMetadata) => null
        );

        $this->assertCount(1, $books);
        $this->assertContains('📖 Processing Book 1 with 1 files', $infoMessages);
        $this->assertEmpty($books[0]['metadata']['series'] ?? null);
        $this->assertEmpty($books[0]['metadata']['series_number'] ?? null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractMetadataFromFileTagsParsesParentheticalSeriesFromTitleTag(): void
    {
        $tags = ['x.m4b' => [
            'title' => 'All Fired Up - A Small Town Second Chance Firefighter Romance (Hometown Heroes, Book 1)',
            'album' => 'All Fired Up',
            'artist' => 'Lili Valente',
        ]];

        $metadata = $this->makeService()->extractMetadataFromFileTags($tags);

        $this->assertSame('All Fired Up', $metadata['title']);
        $this->assertSame('Hometown Heroes', $metadata['series']);
        $this->assertSame(1, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function postProcessAIResultDropsGenericContainerDirectoryAsSeries(): void
    {
        $service = $this->makeService();

        $result = $service->postProcessAIResult(
            ['title' => 'Solo Book', 'author' => ['Some Author'], 'series' => 'books', 'series_number' => 3],
            ['path' => '/lib/books/Solo Book', 'name' => 'Solo Book']
        );

        $this->assertEmpty($result['series'] ?? null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function flatArchiveBookTakesNarratorFromItsOpenAudibleRecord(): void
    {
        $parser = \Mockery::mock(OpenAudibleParser::class);
        $parser->shouldReceive('detectOpenAudibleDirectory')->andReturn(dirname($this->booksDir));
        $parser->shouldReceive('findBookByAudioFile')->andReturn([
            'title' => 'One Last Thing',
            'series' => null,
            'narrator' => 'CJ Mission, Rebecca Gibel',
            'narrated_by' => 'CJ Mission, Rebecca Gibel',
        ]);

        $service = $this->makeService($parser);
        [$audiobook, $multiBookInfo, $splitGroups] = $this->flatArchive($service, 'One Last Thing.m4b');

        $books = $service->processMultiBookSplit($audiobook, $multiBookInfo, $splitGroups, ['title' => 'books']);

        $this->assertSame(['CJ Mission', 'Rebecca Gibel'], $books[0]['metadata']['narrator']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractMetadataFromFileTagsUsesComposerAsNarratorFallback(): void
    {
        $tags = ['x.m4b' => ['album' => 'One Last Thing', 'artist' => 'Susan Henshaw', 'composer' => 'CJ Mission, Rebecca Gibel']];

        $metadata = $this->makeService()->extractMetadataFromFileTags($tags);

        $this->assertSame(['CJ Mission', 'Rebecca Gibel'], $metadata['narrator']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractMetadataFromFileTagsPrefersNarratorTagOverComposer(): void
    {
        $tags = ['x.m4b' => ['album' => 'A', 'narrator' => 'Real Narrator', 'composer' => 'Some Composer']];

        $metadata = $this->makeService()->extractMetadataFromFileTags($tags);

        $this->assertSame(['Real Narrator'], $metadata['narrator']);
    }

    private function partialService(OpenAudibleParser $parser, ?string $picture): BookImportService
    {
        $service = \Mockery::mock(
            BookImportService::class,
            [
                $this->app->make(GenreMappingService::class),
                $this->app->make(SourceTrashService::class),
                null,
                $parser,
            ]
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('extractEmbeddedPicture')->andReturn($picture);

        return $service;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function splitBookUsesItsOwnEmbeddedCoverNotTheWholeBatchCover(): void
    {
        $parser = \Mockery::mock(OpenAudibleParser::class);
        $parser->shouldReceive('detectOpenAudibleDirectory')->andReturn(null);

        $service = $this->partialService($parser, 'OWN-COVER-BYTES');
        [$audiobook, $multiBookInfo, $splitGroups] = $this->flatArchive($service, 'Runaway Bride.m4b');

        $batch = [
            'title' => 'books',
            'cover_data' => 'WRONG-BOOK-COVER',
            'cover_path' => '/other/cover.jpg',
            'cover_url' => 'http://example.test/wrong.jpg',
            'cover_sources' => [['type' => 'embedded', 'data' => 'WRONG-BOOK-COVER', 'label' => 'x']],
        ];
        $books = $service->processMultiBookSplit($audiobook, $multiBookInfo, $splitGroups, $batch);

        $this->assertSame('OWN-COVER-BYTES', $books[0]['metadata']['cover_data']);
        $this->assertArrayNotHasKey('cover_url', $books[0]['metadata']);
        $this->assertSame('OWN-COVER-BYTES', $books[0]['metadata']['cover_sources'][0]['data']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function splitBookWithoutOwnCoverGetsNoCoverFromAnotherBook(): void
    {
        $parser = \Mockery::mock(OpenAudibleParser::class);
        $parser->shouldReceive('detectOpenAudibleDirectory')->andReturn(null);

        $service = $this->partialService($parser, null);
        [$audiobook, $multiBookInfo, $splitGroups] = $this->flatArchive($service, 'No Cover.m4b');

        $books = $service->processMultiBookSplit($audiobook, $multiBookInfo, $splitGroups, [
            'title' => 'books',
            'cover_data' => 'WRONG-BOOK-COVER',
        ]);

        $this->assertEmpty($books[0]['metadata']['cover_data'] ?? null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function splitBookDoesNotInheritFirstFilesOpenAudibleFields(): void
    {
        $parser = \Mockery::mock(OpenAudibleParser::class);
        $parser->shouldReceive('detectOpenAudibleDirectory')->andReturn(null);

        $service = $this->partialService($parser, null);
        [$audiobook, $multiBookInfo, $splitGroups] = $this->flatArchive($service, 'Other Book.m4b');

        $books = $service->processMultiBookSplit($audiobook, $multiBookInfo, $splitGroups, [
            'title' => 'books',
            'source' => 'openaudible',
            'publisher' => 'FIRST BOOK PUBLISHER',
            'genre' => ['Romantic Suspense'],
            'asin' => 'B000FIRST',
        ]);

        $this->assertArrayNotHasKey('publisher', $books[0]['metadata']);
        $this->assertArrayNotHasKey('asin', $books[0]['metadata']);
        $this->assertArrayNotHasKey('genre', $books[0]['metadata']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function perBookAiNeverReplacesOpenAudibleNarratorButFillsAGap(): void
    {
        $parser = \Mockery::mock(OpenAudibleParser::class);
        $parser->shouldReceive('detectOpenAudibleDirectory')->andReturn(dirname($this->booksDir));
        $parser->shouldReceive('findBookByAudioFile')->andReturnUsing(
            fn (string $file) => str_contains($file, 'With Narrator')
                ? ['title' => 'With Narrator', 'narrator' => 'OA Narrator', 'series' => null]
                : ['title' => 'No Narrator', 'series' => null]
        );

        $service = $this->makeService($parser);
        $withNarrator = $this->booksDir . '/With Narrator.m4b';
        $noNarrator = $this->booksDir . '/No Narrator.m4b';
        File::put($withNarrator, 'x');
        File::put($noNarrator, 'x');
        $audiobook = ['path' => $this->booksDir, 'name' => 'books', 'files' => [$withNarrator, $noNarrator]];
        $multiBookInfo = ['series_name' => 'books', 'numbers' => [1, 2], 'is_flat_archive' => true];
        $splitGroups = $service->convertFlatFilesToSplitGroups([$withNarrator, $noNarrator]);

        $ai = \Mockery::mock(\App\Services\AIBookProcessor::class);
        $ai->shouldReceive('processBookDirectory')->andReturn(['author' => ['AI Author'], 'narrator' => ['AI Narrator']]);

        $books = $service->processMultiBookSplit(
            $audiobook,
            $multiBookInfo,
            $splitGroups,
            ['title' => 'books'],
            null,
            null,
            $ai
        );

        $byTitle = [];
        foreach ($books as $book) {
            $byTitle[$book['metadata']['title']] = $book['metadata'];
        }

        $this->assertSame(['OA Narrator'], $byTitle['With Narrator']['narrator']);
        $this->assertSame(['AI Narrator'], $byTitle['No Narrator']['narrator']);
    }
}
