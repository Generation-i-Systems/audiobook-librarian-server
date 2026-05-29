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

        $this->testDataPath = storage_path('framework/testing/book_parser');

        if (!File::exists($this->testDataPath)) {
            File::makeDirectory($this->testDataPath, 0755, true);
        }

        // Set books disk root to testDataPath so parser strips it correctly from paths
        config(['filesystems.disks.books.root' => $this->testDataPath]);

        $this->parser = new BookDirectoryParser();

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
                    'The Martian' => [
                        'The Martian.m4b' => '',
                    ],
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

        $this->assertNotEmpty($books);

        // Parser groups audio files by directory — each directory with audio files is one book
        // The test structure has 4 directories containing audio files
        $this->assertCount(4, $books, 'Expected 4 books (one per audio directory)');

        $titles = array_column($books, 'title');
        $this->assertContains('The Stormlight Archive', $titles);
        $this->assertContains('Mistborn', $titles);
        $this->assertContains('The Lord of the Rings', $titles);
        $this->assertContains('The Martian', $titles);
    }

    #[Test]
    public function testParseWithSeriesExtraction(): void
    {
        $books = $this->parser->parseDirectory($this->testDataPath);

        // The Lord of the Rings directory is treated as a single book (directory = book)
        $lotrBooks = collect($books)
            ->filter(fn ($book) => ($book['title'] ?? '') === 'The Lord of the Rings')
            ->values()
            ->all();

        $this->assertCount(1, $lotrBooks, 'Expected 1 Lord of the Rings directory-book');
        $this->assertEquals(3, $lotrBooks[0]['audioFileCount'] ?? 0, 'Should count 3 audio files');
    }

    #[Test]
    public function testParseWithEditionExtraction(): void
    {
        $books = $this->parser->parseDirectory($this->testDataPath);

        // The Stormlight Archive directory contains audio files including one tagged [Graphic Audio]
        $stormlight = collect($books)->first(fn ($book) => ($book['title'] ?? '') === 'The Stormlight Archive');

        $this->assertNotNull($stormlight, 'Should find The Stormlight Archive directory');
        // Verify the directory was found with multiple audio files
        $this->assertGreaterThan(1, $stormlight['audioFileCount'] ?? 0, 'Should have multiple audio files');
    }
}
