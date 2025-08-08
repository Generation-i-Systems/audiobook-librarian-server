<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\BookDirectoryParser;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookDirectoryParserTest extends TestCase
{
    protected string $testDataPath;

    protected BookDirectoryParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a local temporary directory for testing
        $this->testDataPath = storage_path('framework/testing/book_parser');

        // Create the directory if it doesn't exist
        if (!File::exists($this->testDataPath)) {
            File::makeDirectory($this->testDataPath, 0755, true);
        }

        $this->parser = new BookDirectoryParser();

        // Create test directory structure
        $this->createTestDirectoryStructure();
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists($this->testDataPath)) {
            File::deleteDirectory($this->testDataPath);
        }

        // Clean up error handlers to prevent risky tests
        $this->cleanupErrorHandlers();

        parent::tearDown();
    }

    /**
     * Clean up any error handlers that might be registered during tests.
     */
    protected function cleanupErrorHandlers(): void
    {
        // Reset error handlers to PHP defaults
        restore_error_handler();
        restore_exception_handler();

        // Call multiple times to ensure all stacked handlers are removed
        restore_error_handler();
        restore_exception_handler();

        // Force garbage collection to clean up any resources
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    protected function createTestDirectoryStructure(): void
    {
        // Create test directories and files
        $structure = [
            'Fantasy' => [
                'Brandon Sanderson' => [
                    'The Stormlight Archive' => [
                        'The Way of Kings.m4b' => '',
                        'Words of Radiance.mp3' => '',
                        'Oathbringer (Michael Kramer).m4b' => '',
                        'Rhythm of War [Graphic Audio].mp3' => '',
                    ],
                    'Mistborn' => [
                        'Mistborn 1 - The Final Empire.m4b' => '',
                    ],
                ],
                'J.R.R. Tolkien' => [
                    'The Lord of the Rings' => [
                        'The Lord of the Rings 1 - The Fellowship of the Ring.m4b' => '',
                        'The Lord of the Rings 2 - The Two Towers.m4b' => '',
                        'The Lord of the Rings 3 - The Return of the King.m4b' => '',
                    ],
                ],
            ],
            'Science Fiction' => [
                'Andy Weir' => [
                    'The Martian [R.C. Bray].m4b' => '',
                    'Project Hail Mary (narrated by Ray Porter).m4b' => '',
                ],
            ],
        ];

        $this->createDirectories($this->testDataPath, $structure);
    }

    protected function createDirectories($basePath, $structure): void
    {
        foreach ($structure as $path => $content) {
            $path = $basePath . '/' . $path;

            if (is_array($content)) {
                // It's a directory
                if (!File::exists($path)) {
                    File::makeDirectory($path, 0o755, true);
                }
                $this->createDirectories($path, $content);
            } else {
                // It's a file
                File::put($path, '');
            }
        }
    }

    #[Test]
    public function testParseDirectoryWithDefaultOptions(): void
    {
        $books = $this->parser->parseDirectory($this->testDataPath);

        $this->assertCount(10, $books);

        // Test a few specific books
        $wayOfKings = collect($books)->first(fn ($book) => str_contains($book['title'], 'Way of Kings'));
        $this->assertNotNull($wayOfKings, 'Should find The Way of Kings');
        $this->assertEquals('Brandon Sanderson', $wayOfKings['author'][0]);
        $this->assertEquals('The Stormlight Archive', $wayOfKings['seriesName']);

        $mistborn1 = collect($books)->first(fn ($book) => str_contains($book['title'], 'Final Empire'));
        $this->assertEquals(1, $mistborn1['seriesNumber'], 'Should parse series number from filename');

        $martian = collect($books)->first(fn ($book) => str_contains($book['title'], 'Martian'));
        $this->assertEquals('R.C. Bray', $martian['narrators'][0], 'Should parse narrator from brackets');

        $hailMary = collect($books)->first(fn ($book) => str_contains($book['title'], 'Project Hail Mary'));
        $this->assertEquals('Ray Porter', $hailMary['narrators'][0], 'Should parse narrator from "narrated by" pattern');
    }

    #[Test]
    public function testParseWithSeriesExtraction(): void
    {
        $books = $this->parser->parseDirectory($this->testDataPath);

        // Check that Lord of the Rings series is correctly parsed with series numbers
        $lotrBooks = collect($books)
            ->filter(fn ($book) => str_contains($book['seriesName'] ?? '', 'Lord of the Rings'))
            ->sortBy('seriesNumber')
            ->values()
            ->all()
        ;

        $this->assertCount(3, $lotrBooks);
        $this->assertEquals(1, $lotrBooks[0]['seriesNumber']);
        $this->assertEquals(2, $lotrBooks[1]['seriesNumber']);
        $this->assertEquals(3, $lotrBooks[2]['seriesNumber']);
    }

    #[Test]
    public function testParseWithEditionExtraction(): void
    {
        $books = $this->parser->parseDirectory($this->testDataPath);

        $rhythmOfWar = collect($books)->first(fn ($book) => str_contains($book['title'] ?? '', 'Rhythm of War'));
        $this->assertEquals('Graphic Audio', $rhythmOfWar['edition']);
    }
}
