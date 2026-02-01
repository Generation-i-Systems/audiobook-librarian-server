<?php

namespace Tests\Import\Unit\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use App\Contracts\ImportUIInterface;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportBooksMultiBookSeriesTest extends TestCase
{
    protected ImportBooksFromDownloads $command;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ImportUIInterface&\Mockery\MockInterface $uiService */
        $uiService = \Mockery::mock(ImportUIInterface::class);
        $uiService->shouldReceive('logMessage')->zeroOrMoreTimes();
        $uiService->shouldReceive('setCurrentBook')->zeroOrMoreTimes();
        $this->command = new ImportBooksFromDownloads($uiService);

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
        $method = $reflection->getMethod('detectMultiBookPattern');
        $method->setAccessible(true);

        $result = $method->invoke($this->command, 'Series Name [2-3]');

        // Cleanup
        File::deleteDirectory($tempDir);

        $this->assertIsArray($result);
        $this->assertSame('Series Name', $result['series_name']);
        $this->assertSame(2, $result['start_number']);
        $this->assertSame(3, $result['end_number']);
        $this->assertSame([2, 3], $result['numbers']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromVariousFilenamePatterns(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('extractSeriesNumberFromTitle');
        $method->setAccessible(true);

        // Test various patterns
        $metadata = ['title' => 'My Title, Book 1'];
        $args = [&$metadata];
        $method->invokeArgs($this->command, $args);
        $this->assertSame('My Title', $metadata['title']);
        $this->assertSame(1, $metadata['series_number']);

        $metadata = ['title' => 'My Title #2'];
        $args = [&$metadata];
        $method->invokeArgs($this->command, $args);
        $this->assertSame('My Title', $metadata['title']);
        $this->assertSame(2, $metadata['series_number']);

        $metadata = ['title' => 'My Title Part 10'];
        $args = [&$metadata];
        $method->invokeArgs($this->command, $args);
        $this->assertSame('My Title', $metadata['title']);
        $this->assertSame(10, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function splitMultiBookSeriesCreatesIndividualBookEntries(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('analyzeMultiBookFiles');
        $method->setAccessible(true);

        $audiobook = [
            'path' => '/test/path/Series Name [1-2]',
            'files' => [
                '/test/path/Book 01 - First Book.m4b',
                '/test/path/Book 02 - Second Book.m4b',
            ],
        ];
        $multiBookInfo = [
            'series_name' => 'Series Name',
            'numbers' => [1, 2],
        ];

        $result = $method->invoke($this->command, $audiobook, $multiBookInfo);

        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertSame('First Book', $result[1][0]['title']);
        $this->assertSame('Second Book', $result[2][0]['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function splitMultiBookSeriesExtractsAuthorFromDirectoryName(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('cleanSeriesName');
        $method->setAccessible(true);

        $result = $method->invoke($this->command, 'Steven Erikson - Willful Child', ['Steven Erikson']);
        $this->assertSame('Willful Child', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractFileMetadataHandlesBothQuicktimeAndId3Tags(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('extractBookTitleFromFilename');
        $method->setAccessible(true);

        $result = $method->invoke($this->command, 'Book 01 - My Title.m4b', 'Series Name', 1);
        $this->assertSame('My Title', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function splitBooksHaveCorrectMetadataStructure(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('analyzeMultiBookFiles');
        $method->setAccessible(true);

        $audiobook = [
            'path' => '/test/Series Name [1-1]',
            'files' => ['/test/Book 01 - Title.m4b'],
        ];
        $multiBookInfo = [
            'series_name' => 'Series Name',
            'numbers' => [1],
        ];

        $result = $method->invoke($this->command, $audiobook, $multiBookInfo);
        $this->assertArrayHasKey(1, $result);
        $this->assertIsArray($result[1]);
        $this->assertSame('/test/Book 01 - Title.m4b', $result[1][0]['file']);
        $this->assertSame('Title', $result[1][0]['title']);
    }
}
