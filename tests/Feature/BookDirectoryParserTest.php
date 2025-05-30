<?php

namespace Tests\Feature;

use App\Services\BookDirectoryParser;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookDirectoryParserTest extends TestCase
{
    protected string $testDataPath;
    protected BookDirectoryParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->parser = app(BookDirectoryParser::class);
        $this->testDataPath = storage_path('framework/testing/book_parser');
        
        // Create test directory structure
        $this->createTestDirectoryStructure();
    }
    
    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists($this->testDataPath)) {
            File::deleteDirectory($this->testDataPath);
        }
        
        parent::tearDown();
    }
    
    protected function createTestDirectoryStructure(): void
    {
        $structure = [
            'Fantasy' => [
                'Brandon Sanderson' => [
                    'The Stormlight Archive' => [
                        'The Way of Kings.m4b',
                        'Words of Radiance.mp3',
                        'Oathbringer [Michael Kramer].m4b',
                        'Rhythm of War (Graphic Audio).mp3',
                    ],
                    'Mistborn' => [
                        'Mistborn 1 - The Final Empire.m4b',
                        'Mistborn 2 - The Well of Ascension [Michael Kramer].mp3',
                        'Mistborn 3 - The Hero of Ages.mp3',
                    ],
                ],
                'J.R.R. Tolkien' => [
                    'The Lord of the Rings 1 - The Fellowship of the Ring.mp3',
                    'The Lord of the Rings 2 - The Two Towers.mp3',
                    'The Lord of the Rings 3 - The Return of the King.mp3',
                ],
            ],
            'Science Fiction' => [
                'Andy Weir' => [
                    'The Martian [R.C. Bray].mp3',
                    'Project Hail Mary [narrated by Ray Porter].m4b',
                ],
                'Frank Herbert' => [
                    'Dune [Scott Brick]' => [
                        'Dune.m4b',
                        'Dune Messiah.m4b',
                    ],
                ],
            ],
        ];
        
        $this->createDirectories($this->testDataPath, $structure);
    }
    
    protected function createDirectories(string $basePath, array $structure): void
    {
        foreach ($structure as $name => $content) {
            $path = $basePath . '/' . $name;
            
            if (is_array($content)) {
                // It's a directory
                if (!File::exists($path)) {
                    File::makeDirectory($path, 0755, true);
                }
                $this->createDirectories($path, $content);
            } else {
                // It's a file
                File::put($path, '');
            }
        }
    }
    
    public function test_parse_directory_with_default_options()
    {
        $books = $this->parser->parseDirectory($this->testDataPath);
        
        $this->assertCount(13, $books, 'Should find all 13 test book files');
        
        // Test a few specific books
        $wayOfKings = collect($books)->first(fn($book) => str_contains($book['title'], 'Way of Kings'));
        $this->assertNotNull($wayOfKings, 'Should find The Way of Kings');
        $this->assertEquals('Brandon Sanderson', $wayOfKings['author']);
        $this->assertEquals('The Stormlight Archive', $wayOfKings['series']);
        $this->assertNull($wayOfKings['series_number']);
        
        $mistborn1 = collect($books)->first(fn($book) => str_contains($book['title'], 'Final Empire'));
        $this->assertEquals(1, $mistborn1['series_number'], 'Should parse series number from filename');
        
        $martian = collect($books)->first(fn($book) => str_contains($book['title'], 'Martian'));
        $this->assertEquals('R.C. Bray', $martian['narrator'], 'Should parse narrator from brackets');
        
        $hailMary = collect($books)->first(fn($book) => str_contains($book['title'], 'Project Hail Mary'));
        $this->assertEquals('Ray Porter', $hailMary['narrator'], 'Should parse narrator from "narrated by" pattern');
    }
    
    public function test_parse_with_series_extraction()
    {
        $books = $this->parser->parseDirectory($this->testDataPath);
        
        // Test Lord of the Rings series numbering
        $lotrBooks = collect($books)
            ->filter(fn($book) => str_contains($book['title'] ?? '', 'Lord of the Rings'))
            ->sortBy('series_number');
            
        $this->assertCount(3, $lotrBooks);
        $this->assertEquals(1, $lotrBooks[0]['series_number']);
        $this->assertEquals(2, $lotrBooks[1]['series_number']);
        $this->assertEquals(3, $lotrBooks[2]['series_number']);
    }
    
    public function test_parse_with_edition_extraction()
    {
        $books = $this->parser->parseDirectory($this->testDataPath);
        
        $rhythmOfWar = collect($books)->first(fn($book) => str_contains($book['title'] ?? '', 'Rhythm of War'));
        $this->assertEquals('Graphic Audio', $rhythmOfWar['edition']);
    }
}
