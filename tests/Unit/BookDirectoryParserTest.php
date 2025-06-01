<?php

namespace Tests\Unit;

use App\Services\BookDirectoryParser;
use App\Services\BookMetadataService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;
use org\bovigo\vfs\vfsStream;

class BookDirectoryParserTest extends TestCase
{
    private BookDirectoryParser $parser;
    private $root;
    private BookMetadataService|MockObject $mockMetadataService;

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

        // Create the parser with null AudioFileAnalyzer and mocked metadata service
        $this->parser = new BookDirectoryParser(null, $this->mockMetadataService);

        // Configure default metadata storage to local for testing
        Config::set('bookparser.metadata_storage', 'local');
        Config::set('bookparser.local_metadata_filename', 'librarian.json');
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
            "  This is a long description line 3\n";

        // Add more lines to make it larger
        for ($i = 0; $i < 100; $i++) {
            $metadataContent .= "  Additional description line " . ($i + 4) . "\n";
        }

        vfsStream::newFile('large_metadata.abs')
            ->at($this->root)
            ->setContent($metadataContent);

        // Configure the mock to return no metadata, forcing fallback to file
        $this->mockMetadataService->method('generateBookId')
            ->willReturn('large-metadata-123');

        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        $result = $this->parser->readMetadataFile(vfsStream::url('testDir/large_metadata.abs'));

        $this->assertEquals('Large Metadata Test', $result['title']);
        $this->assertEquals(['Test Author'], $result['author']);
        $this->assertStringContainsString('This is a long description line', $result['description']);
        $this->assertGreaterThan(1000, strlen($result['description']));
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
        // Mix of Windows (\r\n) and Unix (\n) line endings
        $metadataContent = "title=Mixed Line Endings\n" .
            "author=Author X\r\n" .
            "series=Test Series\n" .
            "narrator=Narrator Y\r\n";

        vfsStream::newFile('mixed_line_endings.abs')
            ->at($this->root)
            ->setContent($metadataContent);

        // Configure the mock to return no metadata, forcing fallback to file
        $this->mockMetadataService->method('generateBookId')
            ->willReturn('mixed-line-endings-123');

        $this->mockMetadataService->method('loadMetadata')
            ->willReturn([]);

        $result = $this->parser->readMetadataFile(vfsStream::url('testDir/mixed_line_endings.abs'));

        $this->assertEquals('Mixed Line Endings', $result['title']);
        $this->assertEquals(['Author X'], $result['author']);
        $this->assertEquals('Test Series', $result['series']);
        $this->assertEquals('Narrator Y', $result['narrator']);
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
