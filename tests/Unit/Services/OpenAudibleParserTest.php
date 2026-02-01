<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\OpenAudibleParser;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OpenAudibleParserTest extends TestCase
{
    private OpenAudibleParser $parser;
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new OpenAudibleParser(new \App\Services\GenreMappingService());
        $this->testDir = storage_path('testing/openaudible_parser');

        File::makeDirectory($this->testDir, 0755, true, true);
        File::makeDirectory($this->testDir . '/books', 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }

        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseChaptersReturnsEmptyArrayWhenNoChapters(): void
    {
        $result = $this->parser->parseChapters([]);

        $this->assertEmpty($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseChaptersNormalizesChapterData(): void
    {
        $rawChapters = [
            [
                'start_offset_ms' => 0,
                'length_ms' => 36129,
                'title' => 'Opening Credits',
                'start_offset_sec' => 0,
            ],
            [
                'start_offset_ms' => 36129,
                'length_ms' => 1470287,
                'title' => "Chapter 1\t",  // With trailing tab
                'start_offset_sec' => 36,
            ],
        ];

        $result = $this->parser->parseChapters($rawChapters);

        $this->assertCount(2, $result);

        // First chapter
        $this->assertEquals('Opening Credits', $result[0]['title']);
        $this->assertEquals(0, $result[0]['start_time_ms']);
        $this->assertEquals(36129, $result[0]['length_ms']);
        $this->assertEquals(0, $result[0]['start_time_sec']);

        // Second chapter - trailing tab should be trimmed
        $this->assertEquals('Chapter 1', $result[1]['title']);
        $this->assertEquals(36129, $result[1]['start_time_ms']);
        $this->assertEquals(1470287, $result[1]['length_ms']);
        $this->assertEquals(36, $result[1]['start_time_sec']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseChaptersGeneratesDefaultTitleWhenMissing(): void
    {
        $rawChapters = [
            [
                'start_offset_ms' => 0,
                'length_ms' => 1000,
                'start_offset_sec' => 0,
            ],
            [
                'start_offset_ms' => 1000,
                'length_ms' => 2000,
                'start_offset_sec' => 1,
            ],
        ];

        $result = $this->parser->parseChapters($rawChapters);

        $this->assertEquals('Chapter 1', $result[0]['title']);
        $this->assertEquals('Chapter 2', $result[1]['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseAndMapGenreMapsFantasyCorrectly(): void
    {
        $genre = 'Science Fiction & Fantasy:Fantasy:Dragons & Mythical Creatures';

        $result = $this->parser->parseAndMapGenre($genre);

        $this->assertEquals('Fantasy', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseAndMapGenreMapsScienceFictionCorrectly(): void
    {
        $genre = 'Science Fiction & Fantasy:Science Fiction:Space Opera';

        $result = $this->parser->parseAndMapGenre($genre);

        $this->assertEquals('Science Fiction', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseAndMapGenreMapsLitRPGCorrectly(): void
    {
        // LitRPG is often tagged under various categories
        $genre = 'Literature & Fiction:Action & Adventure:LitRPG';

        $result = $this->parser->parseAndMapGenre($genre);

        $this->assertEquals('LitRPG', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseAndMapGenreMapsRomanceCorrectly(): void
    {
        $genre = 'Romance:Contemporary:New Adult';

        $result = $this->parser->parseAndMapGenre($genre);

        $this->assertEquals('Romance', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseAndMapGenreMapsMysteryThrillerCorrectly(): void
    {
        // "Mystery" alone maps to General Fiction, but "thriller" maps to Action
        $genre = 'Mystery, Thriller & Suspense:Thriller:Crime';

        $result = $this->parser->parseAndMapGenre($genre);

        // Crime maps to Action, Thriller also maps to Action
        $this->assertEquals('Action', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseAndMapGenreMapsChildrensToKids(): void
    {
        // Children's with no other specific genre should map to Kids
        $genre = "Children's Audiobooks:Educational";

        $result = $this->parser->parseAndMapGenre($genre);

        $this->assertEquals('Kids', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseAndMapGenreMapsReligionCorrectly(): void
    {
        $genre = 'Religion & Spirituality:Christianity:Devotionals';

        $result = $this->parser->parseAndMapGenre($genre);

        // 'Christianity' maps to 'Church' in high-priority rules
        $this->assertEquals('Church', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseAndMapGenreReturnsOtherForUnmappedGenre(): void
    {
        $genre = 'Some Unknown Category:Subcategory';

        $result = $this->parser->parseAndMapGenre($genre);

        $this->assertEquals('Other', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testParseAndMapGenreReturnsOtherForNull(): void
    {
        $result = $this->parser->parseAndMapGenre(null);

        $this->assertEquals('Other', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testNormalizeBookDataIncludesChapters(): void
    {
        $rawBookData = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'genre' => 'Science Fiction & Fantasy:Fantasy',
            'chapters' => [
                [
                    'start_offset_ms' => 0,
                    'length_ms' => 1000,
                    'title' => 'Chapter 1',
                    'start_offset_sec' => 0,
                ],
            ],
        ];

        $result = $this->parser->normalizeBookData($rawBookData);

        $this->assertArrayHasKey('chapters', $result);
        $this->assertCount(1, $result['chapters']);
        $this->assertEquals('Chapter 1', $result['chapters'][0]['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testNormalizeBookDataMapsGenreToLibraryGenre(): void
    {
        $rawBookData = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'genre' => 'Science Fiction & Fantasy:Fantasy:Epic',
        ];

        $result = $this->parser->normalizeBookData($rawBookData);

        // genre should preserve original format for compatibility
        $this->assertEquals('Science Fiction & Fantasy:Fantasy:Epic', $result['genre']);
        // mapped_genre should contain the library-mapped genre
        $this->assertEquals('Fantasy', $result['mapped_genre']);
        // Should preserve original genre
        $this->assertEquals('Science Fiction & Fantasy:Fantasy:Epic', $result['original_genre']);
        // Should include all genres as a list
        $this->assertArrayHasKey('all_genres', $result);
        $this->assertContains('Science Fiction & Fantasy', $result['all_genres']);
        $this->assertContains('Fantasy', $result['all_genres']);
        $this->assertContains('Epic', $result['all_genres']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testNormalizeBookDataSetsSkipEnrichmentFlag(): void
    {
        $rawBookData = [
            'title' => 'Test Book',
            'author' => 'Test Author',
        ];

        $result = $this->parser->normalizeBookData($rawBookData);

        $this->assertTrue($result['skip_enrichment']);
        $this->assertEquals('openaudible', $result['source']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testDetectOpenAudibleDirectoryFindsParentBooks(): void
    {
        // Create books.json in test directory
        File::put($this->testDir . '/books.json', '[]');

        // Test with path inside books directory
        $path = $this->testDir . '/books/test.m4b';

        $result = $this->parser->detectOpenAudibleDirectory($path);

        $this->assertEquals($this->testDir, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testDetectOpenAudibleDirectoryReturnsNullWhenNotFound(): void
    {
        // No books.json in test directory
        $path = $this->testDir . '/books/test.m4b';

        $result = $this->parser->detectOpenAudibleDirectory($path);

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testFindBookByAudioFileFindsMatchingBook(): void
    {
        // Create books.json with test data
        $booksData = [
            [
                'title' => 'Test Book',
                'author' => 'Test Author',
                'filename' => 'Test Book.m4b',
                'genre' => 'Fantasy',
                'chapters' => [
                    ['start_offset_ms' => 0, 'length_ms' => 1000, 'title' => 'Chapter 1'],
                ],
            ],
        ];
        File::put($this->testDir . '/books.json', json_encode($booksData));

        // Load and find book
        $this->parser->loadBooksJson($this->testDir);
        $result = $this->parser->findBookByAudioFile($this->testDir . '/books/Test Book.m4b');

        $this->assertNotNull($result);
        $this->assertEquals('Test Book', $result['title']);
        $this->assertEquals('Test Author', $result['author']);
        $this->assertCount(1, $result['chapters']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testFindBookByAudioFileReturnsNullWhenNotFound(): void
    {
        // Create books.json with test data
        $booksData = [
            [
                'title' => 'Test Book',
                'filename' => 'Test Book.m4b',
            ],
        ];
        File::put($this->testDir . '/books.json', json_encode($booksData));

        // Load and try to find non-existent book
        $this->parser->loadBooksJson($this->testDir);
        $result = $this->parser->findBookByAudioFile($this->testDir . '/books/Unknown Book.m4b');

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testClearCacheResetsIndex(): void
    {
        // Create and load books.json
        File::put($this->testDir . '/books.json', '[]');
        $this->parser->loadBooksJson($this->testDir);

        $this->assertTrue($this->parser->isLoaded());

        // Clear cache
        $this->parser->clearCache();

        $this->assertFalse($this->parser->isLoaded());
        $this->assertNull($this->parser->getLoadedPath());
    }
}
