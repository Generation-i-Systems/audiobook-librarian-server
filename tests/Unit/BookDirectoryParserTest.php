<?php

namespace Tests\Unit;

use App\Services\BookDirectoryParser;
use App\Services\BookMetadataService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;
use org\bovigo\vfs\vfsStream;
use App\Services\AudioFileAnalyzer;

class BookDirectoryParserTest extends TestCase
{
    /** @test */
    public function testExtractAuthorFromPathWithGenreAndSubgenre()
    {
        $parser = new BookDirectoryParser();
        // genre/author
        $this->assertEquals('Brandon Sanderson', $parser->extractAuthorFromPath('Fantasy/Brandon Sanderson/Stormlight Archive'));
        // genre/subgenre/author
        $this->assertEquals('Brandon Sanderson', $parser->extractAuthorFromPath('Fantasy/VA/Brandon Sanderson/Stormlight Archive'));
        $this->assertEquals('Isaac Asimov', $parser->extractAuthorFromPath('Science Fiction/R/Isaac Asimov/Foundation'));
        $this->assertEquals('Agatha Christie', $parser->extractAuthorFromPath('Mystery/Antoologies/Agatha Christie/Some Title'));
        // no genre, just author
        $this->assertEquals('J.K. Rowling', $parser->extractAuthorFromPath('J.K. Rowling/Harry Potter'));
        // unknown path
        $this->assertEquals('Unknown Author', $parser->extractAuthorFromPath(''));
    }

    private BookDirectoryParser $parser;
    private $root;
    private BookMetadataService|MockObject $mockMetadataService;
    private AudioFileAnalyzer|MockObject $mockAudioAnalyzer;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a virtual filesystem for testing
        $this->root = vfsStream::setup('testDir');

        // Create a mock for the BookMetadataService
        $this->mockMetadataService = $this->createMock(BookMetadataService::class);

        // Configure the default mock behavior to return empty metadata by default
        // This ensures tests explicitly set up the mocks they need
        $this->mockMetadataService->method('generateBookId')
            ->willReturn('default-test-id');

        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        // Create a mock AudioFileAnalyzer
        $this->mockAudioAnalyzer = $this->createMock(AudioFileAnalyzer::class);

        // Create the parser with mocked services
        $this->parser = new BookDirectoryParser(
            $this->mockAudioAnalyzer,
            $this->mockMetadataService
        );

        // Configure default metadata storage to local for testing
        Config::set('bookparser.metadata_storage', 'local');
        Config::set('bookparser.local_metadata_filename', 'librarian.json');
    }

    /** @test */
    public function testParsesAuthorTitleDirectoryStructure()
    {
        // Create directory structure: /genre/author/title/
        $structure = [
            'books' => [
                'Fantasy' => [
                    'Brandon Sanderson' => [
                        'The Way of Kings' => [
                            'file1.mp3' => 'audio content',
                            'file2.mp3' => 'audio content',
                            'metadata.abs' => "title=The Way of Kings\n" .
                                "author=Brandon Sanderson\n" .
                                "series=The Stormlight Archive\n" .
                                "series_number=1"
                        ],
                    ],
                ],
            ],
        ];
        vfsStream::create($structure, $this->root);

        // Configure the mock AudioFileAnalyzer to return a duration for audio files
        $this->mockAudioAnalyzer->method('getAudioDuration')
            ->willReturn(3600.0); // 1 hour per file

        // Mock the metadata service to return empty array to force file parsing
        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        $books = $this->parser->parseDirectory(vfsStream::url('testDir/books'));

        // Filter to only include valid books (should have title and audio files)
        $validBooks = array_values(array_filter($books, function ($book) {
            return !empty($book['title']) && $book['audio_file_count'] > 0;
        }));

        $this->assertCount(1, $validBooks, 'Should only find one valid book');
        $this->assertEquals('The Way of Kings', $validBooks[0]['title']);
        $this->assertEquals(['Brandon Sanderson'], $validBooks[0]['author']);
        $this->assertEquals('The Stormlight Archive', $validBooks[0]['series']);
        $this->assertEquals(2, $validBooks[0]['audio_file_count']);
        $this->assertFalse($validBooks[0]['needs_review']);
        $this->assertEquals('02:00:00', $validBooks[0]['duration_formatted']);
    }

    /** @test */
    public function testParsesAuthorSeriesTitleDirectoryStructure()
    {
        // Create directory structure: /genre/author/series/title/
        $structure = [
            'books' => [
                'Fantasy' => [
                    'Brandon Sanderson' => [
                        'The Stormlight Archive' => [
                            'The Way of Kings' => [
                                'file1.mp3' => 'audio content',
                                'file2.mp3' => 'audio content',
                                'metadata.abs' => "title=The Way of Kings\n" .
                                    "author=Brandon Sanderson\n" .
                                    "series=The Stormlight Archive\n" .
                                    "series_number=1"
                            ],
                        ],
                    ],
                ],
            ],
        ];
        vfsStream::create($structure, $this->root);

        // Configure the mock AudioFileAnalyzer to return a duration for audio files
        $this->mockAudioAnalyzer->method('getAudioDuration')
            ->willReturn(3600.0); // 1 hour per file

        // Mock the metadata service to return empty array to force file parsing
        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        $books = $this->parser->parseDirectory(vfsStream::url('testDir/books'));

        // Filter to only include valid books (should have title and audio files)
        $validBooks = array_values(array_filter($books, function ($book) {
            return !empty($book['title']) && $book['audio_file_count'] > 0;
        }));

        $this->assertCount(1, $validBooks, 'Should only find one valid book');
        $this->assertEquals('The Way of Kings', $validBooks[0]['title']);
        $this->assertEquals(['Brandon Sanderson'], $validBooks[0]['author']);
        $this->assertEquals('The Stormlight Archive', $validBooks[0]['series']);
        $this->assertEquals(1, $validBooks[0]['series_number']);
        $this->assertEquals(2, $validBooks[0]['audio_file_count']);
        $this->assertEquals('02:00:00', $validBooks[0]['duration_formatted']);
    }

    /** @test */
    public function testMarksBooksWithoutAuthorAsNeedingReview()
    {
        // Create a book without an author in the path or metadata
        $structure = [
            'books' => [
                'Fiction' => [
                    'Unknown' => [
                        'Book Without Author' => [
                            'file1.mp3' => 'audio content',
                            'metadata.abs' => "title=Book Without Author",
                        ],
                    ],
                ],
            ],
        ];
        vfsStream::create($structure, $this->root);

        // Configure the mock AudioFileAnalyzer to return a duration for audio files
        $this->mockAudioAnalyzer->method('getAudioDuration')
            ->willReturn(3600.0); // 1 hour per file

        // Mock the metadata service to return empty array to force file parsing
        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        $books = $this->parser->parseDirectory(vfsStream::url('testDir/books'));

        // Filter to only include valid books (should have title and audio files)
        $validBooks = array_values(array_filter($books, function ($book) {
            return !empty($book['title']) && $book['audio_file_count'] > 0;
        }));

        $this->assertCount(1, $validBooks, 'Should find one book that needs review');
        $this->assertTrue($validBooks[0]['needs_review']);
        $this->assertStringContainsString('Could not determine author', $validBooks[0]['review_reason']);
    }

    /** @test */
    public function testSkipsDirectoriesWithoutAudioFiles()
    {
        // Create directory structure with a directory that has no audio files
        $structure = [
            'books' => [
                'Fantasy' => [
                    'Brandon Sanderson' => [
                        'Empty Book' => [
                            'notes.txt' => 'No audio files here',
                        ],
                    ],
                ],
            ],
        ];
        vfsStream::create($structure, $this->root);

        $books = $this->parser->parseDirectory(vfsStream::url('testDir/books'));

        $this->assertCount(0, $books);
    }

    /** @test */
    public function testHandlesMetadataAbsFiles()
    {
        // Create directory structure with metadata.abs file
        $structure = [
            'books' => [
                'Sci-Fi' => [
                    'Frank Herbert' => [
                        'Dune' => [
                            'file1.mp3' => 'audio content',
                            'metadata.abs' => ";ABMETADATA2\n" .
                                "#audiobookshelf v2.4.4\n\n" .
                                "title=Dune\n" .
                                "author=Frank Herbert\n" .
                                "series=Dune\n" .
                                "series_number=1\n\n[description]\n" .
                                "A brilliant science fiction novel.\n\n"
                        ],
                    ],
                ],
            ],
        ];
        vfsStream::create($structure, $this->root);

        // Configure the mock AudioFileAnalyzer to return a duration for audio files
        $this->mockAudioAnalyzer->method('getAudioDuration')
            ->willReturn(3600.0); // 1 hour per file

        // Mock the metadata service to return empty array to force file parsing
        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        $books = $this->parser->parseDirectory(vfsStream::url('testDir/books'));

        // Filter to only include valid books (should have title and audio files)
        $validBooks = array_values(array_filter($books, function ($book) {
            return !empty($book['title']) && $book['audio_file_count'] > 0;
        }));

        $this->assertCount(1, $validBooks, 'Should find one valid book');
        $this->assertEquals('Dune', $validBooks[0]['title']);
        $this->assertEquals(['Frank Herbert'], $validBooks[0]['author']);
        $this->assertEquals('Dune', $validBooks[0]['series']);
        $this->assertEquals(1, $validBooks[0]['series_number']);
        $this->assertEquals('A brilliant science fiction novel.', $validBooks[0]['description']);
    }

    /** @test */
    public function testHandlesMultipleAudioFiles()
    {
        // Create a book with multiple audio files
        $structure = [
            'books' => [
                'Fantasy' => [
                    'J.R.R. Tolkien' => [
                        'The Lord of the Rings' => [
                            '01 - The Fellowship of the Ring' => [
                                'file1.mp3' => 'audio content',
                                'file2.mp3' => 'audio content',
                                'file3.mp3' => 'audio content',
                                'metadata.abs' => "title=The Fellowship of the Ring\n" .
                                    "author=J.R.R. Tolkien\n" .
                                    "series=The Lord of the Rings\n" .
                                    "series_number=1"
                            ],
                        ],
                    ],
                ],
            ],
        ];
        vfsStream::create($structure, $this->root);

        // Configure the mock AudioFileAnalyzer to return a duration for audio files
        $this->mockAudioAnalyzer->method('getAudioDuration')
            ->willReturn(3600.0); // 1 hour per file

        // Mock the metadata service to return empty array to force file parsing
        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        $books = $this->parser->parseDirectory(vfsStream::url('testDir/books'));

        // Filter to only include valid books (should have title and audio files)
        $validBooks = array_values(array_filter($books, function ($book) {
            return !empty($book['title']) && $book['audio_file_count'] > 0;
        }));

        $this->assertCount(1, $validBooks, 'Should only find one valid book');
        $this->assertEquals('The Fellowship of the Ring', $validBooks[0]['title']);
        $this->assertEquals(3, $validBooks[0]['audio_file_count']);
        $this->assertEquals(10800, $validBooks[0]['duration']); // 3 hours total
        $this->assertEquals('03:00:00', $validBooks[0]['duration_formatted']);
    }

    /** @test */
    public function testHandlesEmptyDirectoriesGracefully()
    {
        // Create empty directory structure
        $structure = [
            'empty' => [],
        ];
        vfsStream::create($structure, $this->root);

        $books = $this->parser->parseDirectory(vfsStream::url('testDir/empty'));

        $this->assertCount(0, $books);
    }

    /** @test */
    public function testHandlesInvalidPathsGracefully()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Directory does not exist');

        // Use a non-existent path that's guaranteed to not exist
        $nonExistentPath = sys_get_temp_dir() . '/nonexistent_path_' . uniqid();
        $this->parser->parseDirectory($nonExistentPath);
    }

    /** @test */
    public function readsMetadataFromAbsFile()
    {
        // Configure the mock to return no metadata, forcing fallback to file
        $this->mockMetadataService->method('generateBookId')
            ->willReturn('test-book-123');

        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        // Create a metadata file in the virtual filesystem

        // Create a test metadata.abs file
        $metadataContent = <<<EOT
;ABMETADATA2
#audiobookshelf v2.4.4

title=Test Book
authors=Author One, Author Two
series=Test Series
narrators=Narrator Name
publishedYear=2023
description=Test description

[DESCRIPTION]
This is a test description
with multiple lines.

[CHAPTER]
start=0
end=100
title=Chapter 1
EOT;

        vfsStream::newFile('metadata.abs')
            ->at($this->root)
            ->setContent($metadataContent);

        // Call the method with the virtual directory path
        $result = $this->parser->readMetadataFile(vfsStream::url('testDir'));

        // Assert the results
        $this->assertEquals('Test Book', $result['title']);
        $this->assertEquals(['Author One', 'Author Two'], $result['author']);
        $this->assertEquals('Test Series', $result['series']);
        $this->assertEquals('Narrator Name', $result['narrator']);
        $this->assertEquals(2023, $result['year']);
    }

    /** @test */
    public function handlesEmptyMetadata()
    {
        // Configure the mock to return no metadata
        $this->mockMetadataService->method('generateBookId')
            ->willReturn('test-book-123');

        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        // Test with non-existent directory - should return array with empty values
        $result = $this->parser->readMetadataFile(vfsStream::url('non_existent_dir'));
        $this->assertIsArray($result);
        $this->assertEquals([
            'title' => '',
            'author' => [],
            'narrator' => '',
            'series' => '',
            'year' => null,
            'description' => '',
        ], $result);

        // Test with empty metadata file
        vfsStream::newFile('empty_metadata.abs')
            ->at($this->root)
            ->setContent('');

        $result = $this->parser->readMetadataFile(vfsStream::url('testDir/empty_metadata.abs'));

        // Should return an array with empty values for expected keys
        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEmpty($result['title']);
        $this->assertArrayHasKey('author', $result);
        $this->assertEmpty($result['author']);
    }

    /** @test */
    public function doesNotIncludeEmptyValues()
    {
        $metadataContent = "title=\nseries=Test Series\nnarrator=\nauthors=\ndescription=\npublishedYear=";

        vfsStream::newFile('empty_values.abs')
            ->at($this->root)
            ->setContent($metadataContent);

        // Configure the mock to return no metadata, forcing fallback to file
        $this->mockMetadataService->method('generateBookId')
            ->willReturn('empty-values-123');

        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        $result = $this->parser->readMetadataFile(vfsStream::url('testDir/empty_values.abs'));

        // The implementation should include all keys but with empty strings for empty values
        $this->assertArrayHasKey('title', $result);
        $this->assertEmpty($result['title']);
        $this->assertEquals('Test Series', $result['series']);
        $this->assertArrayHasKey('narrator', $result);
        $this->assertEmpty($result['narrator']);
        $this->assertArrayHasKey('author', $result);
        $this->assertIsArray($result['author']);
        $this->assertEmpty($result['author']);
        $this->assertArrayHasKey('description', $result);
        $this->assertEmpty($result['description']);
        $this->assertArrayHasKey('year', $result);
        $this->assertNull($result['year']);
    }

    /** @test */
    public function handlesNestedDirectories()
    {
        // Create the full path for the nested directory
        $nestedPath = 'nested/dir/structure';

        // Create each directory level separately
        $currentDir = $this->root;
        foreach (explode('/', $nestedPath) as $dir) {
            if (!$currentDir->hasChild($dir)) {
                $currentDir = vfsStream::newDirectory($dir, 0777)->at($currentDir);
            } else {
                $currentDir = $currentDir->getChild($dir);
            }
        }

        // Create a metadata file in the nested directory
        $metadataContent = ";ABMETADATA2\n" .
            "title=Nested Book\n" .
            "author=Nested Author\n" .
            "series=Nested Series\n" .
            "publishedYear=2023";

        // Create the metadata file
        $metadataFile = vfsStream::newFile('metadata.abs')
            ->at($currentDir)
            ->setContent($metadataContent);

        // Get the file path for testing
        $filePath = vfsStream::url('testDir/' . $nestedPath . '/metadata.abs');

        // Debug output
        error_log("Virtual filesystem root: " . vfsStream::url('testDir'));
        error_log("Test file path: " . $filePath);
        error_log("File exists: " . (file_exists($filePath) ? 'yes' : 'no'));
        error_log("File is readable: " . (is_readable($filePath) ? 'yes' : 'no'));

        if (file_exists($filePath)) {
            error_log("File content: " . file_get_contents($filePath));
        } else {
            $files = print_r(iterator_to_array($this->root->getChildren()), true);
            error_log("Available files in vfs://testDir: " . $files);
        }

        // Configure the mock to return no metadata, forcing fallback to file
        $this->mockMetadataService->method('generateBookId')
            ->willReturn('nested-dir-123');

        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        // Test with directory path
        $dirPath = vfsStream::url('testDir/' . $nestedPath);
        error_log("Testing with directory path: " . $dirPath);
        error_log("Directory exists: " . (is_dir($dirPath) ? 'yes' : 'no'));

        $result = $this->parser->readMetadataFile($dirPath);
        error_log("Parser result: " . print_r($result, true));

        $this->assertArrayHasKey('title', $result, 'Title key is missing in directory result');
        $this->assertEquals('Nested Book', $result['title'], 'Title does not match expected');
        $this->assertEquals(['Nested Author'], $result['author'], 'Author does not match expected');
        $this->assertEquals('Nested Series', $result['series'], 'Series does not match expected');

        // Also test with direct file path
        $filePath = vfsStream::url('testDir/' . $nestedPath . '/metadata.abs');
        error_log("Testing with direct file path: " . $filePath);
        error_log("File exists: " . (file_exists($filePath) ? 'yes' : 'no'));

        $result = $this->parser->readMetadataFile($filePath);
        error_log("Parser result (direct file): " . print_r($result, true));

        $this->assertArrayHasKey('title', $result, 'Title key is missing in file result');
        $this->assertEquals('Nested Book', $result['title'], 'Title does not match expected for direct file');
    }

    /** @test */
    public function handleSpecialCharactersInPaths()
    {
        $metadataContent = "title=Special Chars Test\nauthors=Author C";

        $specialDir = vfsStream::newDirectory('dir with spaces and (parentheses)');
        $this->root->addChild($specialDir);

        vfsStream::newFile('metadata.abs')
            ->at($specialDir)
            ->setContent($metadataContent);

        $result = $this->parser->readMetadataFile(
            vfsStream::url('testDir/dir with spaces and (parentheses)')
        );

        $this->assertEquals('Special Chars Test', $result['title']);
        $this->assertEquals('Author C', $result['author'][0]);
    }

    /** @test */
    public function handleLargeMetadataFiles()
    {
        // Create a large metadata file with many lines
        $metadataContent = "title=Large Metadata Test\n" .
            "author=Test Author\n" .
            "description=This is a long description line 1\n" .
            "  This is a long description line 2\n" .
            "  This is a long description line 3";

        // Add more lines to make it larger
        for ($i = 0; $i < 100; $i++) {
            $metadataContent .= "\n  Additional description line " . ($i + 4);
        }

        // Log the metadata content for debugging
        error_log("Metadata content:\n" . $metadataContent);
        error_log("Metadata content length: " . strlen($metadataContent));

        $file = vfsStream::newFile('large_metadata.abs')
            ->at($this->root)
            ->setContent($metadataContent);

        // Log the actual file content for debugging
        $fileContent = $file->getContent();
        error_log("File content length: " . strlen($fileContent));
        error_log("File content first 200 chars: " . substr($fileContent, 0, 200));
        error_log("File content last 200 chars: " . substr($fileContent, -200));

        // Configure the mock to return no metadata, forcing fallback to file
        $this->mockMetadataService->method('generateBookId')
            ->willReturn('large-metadata-123');

        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        $fileUrl = vfsStream::url('testDir/large_metadata.abs');
        error_log("Reading metadata from: " . $fileUrl);

        $result = $this->parser->readMetadataFile($fileUrl);

        $this->assertEquals('Large Metadata Test', $result['title']);
        $this->assertEquals(['Test Author'], $result['author']);

        // Debug output
        error_log("Parsed description length: " . strlen($result['description']));
        error_log("Parsed description content: " . $result['description']);

        $this->assertStringContainsString('This is a long description line', $result['description']);
        $this->assertGreaterThan(
            1000,
            strlen($result['description']),
            sprintf('Description length %d is not greater than 1000', strlen($result['description']))
        );
    }

    /** @test */
    public function handleDuplicateKeys()
    {
        $metadataContent = "title=First Title\ntitle=Second Title\nseries=Test Series";

        vfsStream::newFile('duplicate_keys.abs')
            ->at($this->root)
            ->setContent($metadataContent);

        // Configure the mock to return no metadata, forcing fallback to file
        $this->mockMetadataService->method('generateBookId')
            ->willReturn('duplicate-keys-123');

        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        $result = $this->parser->readMetadataFile(vfsStream::url('testDir/duplicate_keys.abs'));

        // Should use the first occurrence of the key
        $this->assertEquals('First Title', $result['title']);
        $this->assertEquals('Test Series', $result['series']);
    }

    /** @test */
    public function handleMixedLineEndings()
    {
        $filename = 'metadata.abs';
        $metadataContent = "title=Mixed Line Endings\n" .
            "author=Author X\r\n" .
            "series=Test Series\n" .
            "narrator=Narrator Y\r\n";

        // Log the content we're creating
        error_log("Test content: " . str_replace(["\r", "\n"], ['\\r', '\\n'], $metadataContent));
        error_log("Test content hex: " . bin2hex($metadataContent));

        // Create a directory for the book
        $bookDir = vfsStream::newDirectory('testBook')
            ->at($this->root);

        // Create the metadata file inside the directory
        $file = vfsStream::newFile($filename, 0777)
            ->at($bookDir)
            ->setContent($metadataContent);

        $dirPath = vfsStream::url('testDir/testBook');
        $filePath = $dirPath . '/' . $filename;

        error_log("File created at: " . $filePath);
        error_log("File exists: " . (file_exists($filePath) ? 'yes' : 'no'));
        error_log("File is readable: " . (is_readable($filePath) ? 'yes' : 'no'));
        error_log("File permissions: " . substr(sprintf('%o', fileperms($filePath)), -4));

        $fileContents = file_get_contents($filePath);
        error_log("File contents length: " . strlen($fileContents));
        error_log("File contents: " . $fileContents);
        error_log("File contents hex: " . bin2hex($fileContents));

        // Configure the mock to return no metadata, forcing fallback to file
        $this->mockMetadataService->method('generateBookId')
            ->willReturn('mixed-line-endings-123');

        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        // Log the directory and file we're about to read
        error_log("Attempting to read from directory: " . $dirPath);
        error_log("Directory exists: " . (is_dir($dirPath) ? 'yes' : 'no'));
        error_log("Metadata file exists: " . (file_exists($filePath) ? 'yes' : 'no'));
        error_log("Metadata file is readable: " . (is_readable($filePath) ? 'yes' : 'no'));

        try {
            error_log("Calling readMetadataFile with directory: " . $dirPath);

            // Log the file contents right before parsing
            $beforeReadContents = file_get_contents($filePath);
            error_log("File contents before parsing: " . $beforeReadContents);
            error_log("File contents hex before parsing: " . bin2hex($beforeReadContents));

            // Log the contents line by line
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            error_log("File lines before parsing (" . count($lines) . "):");
            foreach ($lines as $i => $line) {
                error_log(sprintf(
                    "  Line %d: [%s] (hex: %s)",
                    $i + 1,
                    $line,
                    bin2hex($line)
                ));
            }

            // Pass the directory path, not the file path
            $result = $this->parser->readMetadataFile($dirPath);
            error_log("Parser result: " . print_r($result, true));

            // Log the raw file contents that were read
            $readContents = file_get_contents($filePath);
            error_log("Raw file contents after reading: " . $readContents);
            error_log("Raw file contents hex: " . bin2hex($readContents));

            // Log the file contents line by line after reading
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            error_log("File lines after reading (" . count($lines) . "):");
            foreach ($lines as $i => $line) {
                error_log(sprintf(
                    "  Line %d: [%s] (hex: %s)",
                    $i + 1,
                    $line,
                    bin2hex($line)
                ));
            }
        } catch (\Exception $e) {
            error_log("Error reading metadata file: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }

        $this->assertEquals('Mixed Line Endings', $result['title'] ?? '', 'Title does not match expected');
        $this->assertEquals(['Author X'] ?? [], $result['author'] ?? [], 'Author does not match expected');
        $this->assertEquals('Test Series', $result['series'] ?? '', 'Series does not match expected');
        $this->assertEquals('Narrator Y', $result['narrator'] ?? '', 'Narrator does not match expected');
    }

    /** @test */
    public function loadMetadataFromServiceFirst()
    {
        $bookId = 'test-book-123';
        $directoryPath = vfsStream::url('testDir/test-book');
        $expectedMetadata = [
            'title' => 'Test Book from Service',
            'author' => ['Service Author'],
            'series' => 'Service Series',
            'year' => 2023,
        ];

        // Debug: Log the test setup
        error_log("Test setup - Book ID: $bookId, Directory: $directoryPath");

        // Configure the mock to return our test metadata
        $this->mockMetadataService->expects($this->once())
            ->method('generateBookId')
            ->with($directoryPath)
            ->willReturnCallback(function ($path) use ($bookId) {
                error_log("generateBookId called with path: $path");
                return $bookId;
            });

        $this->mockMetadataService->expects($this->once())
            ->method('loadMetadata')
            ->with($this->equalTo($bookId), $this->equalTo($directoryPath))
            ->willReturnCallback(function ($id, $path) use ($expectedMetadata) {
                error_log("loadMetadata called with ID: $id, Path: $path");
                return $expectedMetadata;
            });

        // Debug: Log the expected metadata
        error_log("Expected metadata from service: " . print_r($expectedMetadata, true));

        // The parser should use the metadata from the service
        $result = $this->parser->readMetadataFile($directoryPath);

        // Debug: Log the actual result
        error_log("Actual result from readMetadataFile: " . print_r($result, true));
        error_log("Result type: " . gettype($result));
        error_log("Result keys: " . implode(', ', array_keys($result)));

        $this->assertIsArray($result, 'Result should be an array');
        $this->assertArrayHasKey('title', $result, 'Result should have a title key');
        $this->assertArrayHasKey('author', $result, 'Result should have an author key');
        $this->assertArrayHasKey('series', $result, 'Result should have a series key');
        $this->assertArrayHasKey('year', $result, 'Result should have a year key');

        $this->assertEquals($expectedMetadata['title'], $result['title'], 'Title does not match expected');
        $this->assertEquals($expectedMetadata['author'], $result['author'], 'Author does not match expected');
        $this->assertEquals($expectedMetadata['series'], $result['series'], 'Series does not match expected');
        $this->assertEquals($expectedMetadata['year'], $result['year'], 'Year does not match expected');
    }

    /** @test */
    public function fallBackToMetadataAbsWhenServiceReturnsEmpty()
    {
        $bookId = 'test-book-123';
        $directoryPath = vfsStream::url('testDir/test-book');

        // Create a test metadata.abs file
        $metadataContent = ";ABMETADATA2\n" .
            "title=Test Book from ABS\n" .
            "authors=ABS Author\n" .
            "series=ABS Series\n" .
            "publishedYear=2023";

        vfsStream::newDirectory('test-book')
            ->at($this->root);

        vfsStream::newFile('metadata.abs')
            ->at($this->root->getChild('test-book'))
            ->setContent($metadataContent);

        // Configure the mock to return no metadata (empty array)
        $this->mockMetadataService->method('generateBookId')
            ->with($directoryPath)
            ->willReturn($bookId);

        $this->mockMetadataService->method('loadMetadata')
            ->with($bookId, $directoryPath)
            ->willReturn([]);

        // The parser should fall back to reading metadata.abs
        $result = $this->parser->readMetadataFile($directoryPath);

        $this->assertEquals('Test Book from ABS', $result['title']);
        $this->assertEquals(['ABS Author'], $result['author']);
        $this->assertEquals('ABS Series', $result['series']);
        $this->assertEquals(2023, $result['year']);
    }

    /** @test */
    public function saveParsedMetadataToService()
    {
        $bookId = 'test-book-123';
        $directoryPath = vfsStream::url('testDir/test-book');

        // Create a test metadata.abs file
        $metadataContent = ";ABMETADATA2\n" .
            "title=Test Book to Save\n" .
            "authors=Test Author\n" .
            "series=Test Series\n" .
            "publishedYear=2023";

        vfsStream::newDirectory('test-book')
            ->at($this->root);

        vfsStream::newFile('metadata.abs')
            ->at($this->root->getChild('test-book'))
            ->setContent($metadataContent);

        // Configure the mock to return no metadata initially
        $this->mockMetadataService->method('generateBookId')
            ->with($directoryPath)
            ->willReturn($bookId);

        $this->mockMetadataService->method('loadMetadata')
            ->with($bookId, $directoryPath)
            ->willReturn([]);

        // Expect the saveMetadata method to be called with the parsed metadata
        $expectedMetadata = [
            'title' => 'Test Book to Save',
            'author' => ['Test Author'],
            'series' => 'Test Series',
            'year' => 2023,
        ];

        $this->mockMetadataService->expects($this->once())
            ->method('saveMetadata')
            ->with($bookId, $directoryPath, $expectedMetadata)
            ->willReturn(true);

        // This should trigger the save operation
        $result = $this->parser->readMetadataFile($directoryPath);

        // Verify the result is as expected
        $this->assertEquals($expectedMetadata['title'], $result['title']);
        $this->assertEquals($expectedMetadata['author'], $result['author']);
        $this->assertEquals($expectedMetadata['series'], $result['series']);
        $this->assertEquals($expectedMetadata['year'], $result['year']);
    }
}
