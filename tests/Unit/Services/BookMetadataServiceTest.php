<?php

namespace Tests\Unit\Services;

use App\Services\BookMetadataService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookMetadataServiceTest extends TestCase
{
    private BookMetadataService $service;

    private $testDir;

    private array $testMetadata = [
        'title' => 'Test Book',
        'author' => ['Test Author'],
        'series' => 'Test Series',
        'year' => 2023,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Set up virtual filesystem
        vfsStream::setup('testDir');

        // Create a test directory for local storage
        $this->testDir = vfsStream::url('testDir/test_books');

        // Configure the service for testing
        Config::set('bookparser.metadata_storage', 'local');
        Config::set('bookparser.local_metadata_filename', 'librarian.json');

        $mockDocumentStoreService = $this->createStub(\App\Contracts\DocumentStoreServiceInterface::class);
        $this->service = new BookMetadataService($mockDocumentStoreService);
    }

    #[Test]
    public function test_saves_and_loads_metadata_locally()
    {
        $bookId = 'test-book-123';
        $directoryPath = $this->testDir . '/test-book';

        // Create the directory
        if (!File::exists($directoryPath)) {
            File::makeDirectory($directoryPath, 0755, true);
        }

        // Test saving metadata
        $result = $this->service->saveMetadata($bookId, $directoryPath, $this->testMetadata);
        $this->assertTrue($result);

        // Verify the file was created
        $metadataPath = $directoryPath . '/librarian.json';
        $this->assertFileExists($metadataPath);

        // Test loading metadata
        $loadedMetadata = $this->service->loadMetadata($bookId, $directoryPath);

        // Verify the loaded metadata matches what we saved
        $this->assertEquals($this->testMetadata['title'], $loadedMetadata['title']);
        $this->assertEquals($this->testMetadata['author'], $loadedMetadata['author']);
        $this->assertEquals($this->testMetadata['series'], $loadedMetadata['series']);
        $this->assertEquals($this->testMetadata['year'], $loadedMetadata['year']);
    }

    #[Test]
    public function test_handle_missing_metadata_file()
    {
        $bookId = 'non-existent-book';
        $directoryPath = $this->testDir . '/non-existent-book';

        // Test loading non-existent metadata
        $metadata = $this->service->loadMetadata($bookId, $directoryPath);
        $this->assertEmpty($metadata);
    }

    #[Test]
    public function test_generates_consistent_book_ids()
    {
        $path1 = '/path/to/book';
        $path2 = '/path/to/book';
        $path3 = '/different/path';

        $id1 = $this->service->generateBookId($path1);
        $id2 = $this->service->generateBookId($path2);
        $id3 = $this->service->generateBookId($path3);

        // Same path should generate same ID
        $this->assertEquals($id1, $id2);

        // Different paths should generate different IDs
        $this->assertNotEquals($id1, $id3);

        // ID should be a 64-character hex string (SHA-256)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $id1);
    }

    #[Test]
    public function test_handles_invalid_metadata()
    {
        $bookId = 'invalid-metadata-test';
        $directoryPath = $this->testDir . '/invalid-metadata';

        // Create the directory
        if (!File::exists($directoryPath)) {
            File::makeDirectory($directoryPath, 0755, true);
        }

        // Create an invalid JSON file
        $metadataPath = $directoryPath . '/librarian.json';
        file_put_contents($metadataPath, 'invalid-json');

        // Should handle invalid JSON gracefully
        $metadata = $this->service->loadMetadata($bookId, $directoryPath);
        $this->assertEmpty($metadata);
    }

    protected function tearDown(): void
    {
        // Clean up any created files
        if (File::exists($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }

        parent::tearDown();
    }
}
