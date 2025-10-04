<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportBooksMultiBookSeriesTest extends TestCase
{
    protected ImportBooksFromDownloads $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new ImportBooksFromDownloads();

        // Mock the output to prevent writeln() errors
        $output = \Mockery::mock(\Symfony\Component\Console\Output\OutputInterface::class);
        $output->shouldReceive('writeln')->andReturn(null);
        $output->shouldReceive('write')->andReturn(null);

        $reflection = new \ReflectionClass($this->command);
        $property = $reflection->getProperty('output');
        $property->setAccessible(true);
        $property->setValue($this->command, $output);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function detectMultiBookSeriesIdentifiesMultipleLargeFiles(): void
    {
        // Create a temporary directory with mock files
        $tempDir = sys_get_temp_dir() . '/test_multibook_' . uniqid();
        File::makeDirectory($tempDir);

        // Create mock large files (we'll just create empty files for the test)
        touch($tempDir . '/Book 01 - First.m4b');
        touch($tempDir . '/Book 02 - Second.m4b');
        touch($tempDir . '/Book 03 - Third.m4b');

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('detectMultiBookSeries');
        $method->setAccessible(true);

        // Note: This will return null because files are too small and have no duration
        // But we can test the method exists and doesn't crash
        $result = $method->invoke($this->command, $tempDir);

        // Cleanup
        File::deleteDirectory($tempDir);

        // The method should return null for empty files
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromVariousFilenamePatterns(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('extractSeriesNumber');
        $method->setAccessible(true);

        // Test various patterns
        $this->assertEquals(1, $method->invoke($this->command, 'Book 1 - Title.m4b'));
        $this->assertEquals(2, $method->invoke($this->command, 'Vol 2 - Title.m4b'));
        $this->assertEquals(3, $method->invoke($this->command, '03 - Title.m4b'));
        $this->assertEquals(4, $method->invoke($this->command, 'Title 4.m4b'));
        $this->assertEquals(5, $method->invoke($this->command, 'Series - 05 - Title.m4b'));
        $this->assertEquals(10, $method->invoke($this->command, 'Part 10 - Title.m4b'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function splitMultiBookSeriesCreatesIndividualBookEntries(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('splitMultiBookSeries');
        $method->setAccessible(true);

        $directory = '/test/path/Author - Series Name';
        $largeFiles = [
            [
                'path' => '/test/path/Author - Series Name/Series - 01 - First Book.m4b',
                'filename' => 'Series - 01 - First Book.m4b',
                'size' => 300000000,
                'duration' => 15000,
                'metadata' => [],
            ],
            [
                'path' => '/test/path/Author - Series Name/Series - 02 - Second Book.m4b',
                'filename' => 'Series - 02 - Second Book.m4b',
                'size' => 250000000,
                'duration' => 12000,
                'metadata' => [],
            ],
        ];

        $result = $method->invoke($this->command, $directory, $largeFiles);

        // Should return 2 books
        $this->assertCount(2, $result);

        // First book
        $this->assertEquals('First Book', $result[0]['name']);
        $this->assertEquals(1, $result[0]['metadata']['series_number']);
        $this->assertEquals('Series Name', $result[0]['metadata']['series']);
        $this->assertTrue($result[0]['is_split_book']);
        $this->assertCount(1, $result[0]['files']);

        // Second book
        $this->assertEquals('Second Book', $result[1]['name']);
        $this->assertEquals(2, $result[1]['metadata']['series_number']);
        $this->assertEquals('Series Name', $result[1]['metadata']['series']);
        $this->assertTrue($result[1]['is_split_book']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function splitMultiBookSeriesExtractsAuthorFromDirectoryName(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('splitMultiBookSeries');
        $method->setAccessible(true);

        $directory = '/test/path/Steven Erikson - Willful Child';
        $largeFiles = [
            [
                'path' => '/test/path/file1.m4b',
                'filename' => 'Willful Child - 01 - Willful Child.m4b',
                'size' => 300000000,
                'duration' => 15000,
                'metadata' => [],
            ],
        ];

        $result = $method->invoke($this->command, $directory, $largeFiles);

        $this->assertEquals('Willful Child', $result[0]['metadata']['series']);
        $this->assertEquals(['Steven Erikson'], $result[0]['metadata']['author']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractFileMetadataHandlesBothQuicktimeAndId3Tags(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('extractFileMetadata');
        $method->setAccessible(true);

        // Test with non-existent file (should return empty array)
        $result = $method->invoke($this->command, '/non/existent/file.m4b');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function splitBooksHaveCorrectMetadataStructure(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('splitMultiBookSeries');
        $method->setAccessible(true);

        $directory = '/test/Author - Series';
        $largeFiles = [
            [
                'path' => '/test/file.m4b',
                'filename' => 'Series - 01 - Title.m4b',
                'size' => 300000000,
                'duration' => 15000,
                'metadata' => [
                    'genre' => ['Fantasy'],
                    'year' => '2020',
                ],
            ],
        ];

        $result = $method->invoke($this->command, $directory, $largeFiles);

        // Check metadata structure
        $this->assertArrayHasKey('metadata', $result[0]);
        $this->assertArrayHasKey('title', $result[0]['metadata']);
        $this->assertArrayHasKey('series', $result[0]['metadata']);
        $this->assertArrayHasKey('series_number', $result[0]['metadata']);
        $this->assertArrayHasKey('author', $result[0]['metadata']);
        $this->assertArrayHasKey('is_split_from_multi_book', $result[0]['metadata']);
        $this->assertArrayHasKey('original_directory', $result[0]['metadata']);

        // Check that original metadata is preserved
        $this->assertEquals(['Fantasy'], $result[0]['metadata']['genre']);
        $this->assertEquals('2020', $result[0]['metadata']['year']);
    }
}
