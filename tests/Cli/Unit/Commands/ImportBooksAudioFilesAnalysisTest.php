<?php

namespace Tests\Cli\Unit\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use App\Services\ImportUIService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportBooksAudioFilesAnalysisTest extends TestCase
{
    protected ImportBooksFromDownloads $command;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ImportUIService $uiService */
        $uiService = \Mockery::mock(ImportUIService::class);
        $this->command = new ImportBooksFromDownloads($uiService);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function analyzeAudioFilesInBackgroundTracksLargestAndSmallestFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_audio_analysis_' . uniqid();
        File::makeDirectory($tempDir);

        $smallFile = $tempDir . '/small.mp3';
        $mediumFile = $tempDir . '/medium.mp3';
        $largeFile = $tempDir . '/large.mp3';

        $this->createSizedFile($smallFile, 50);
        $this->createSizedFile($mediumFile, 100);
        $this->createSizedFile($largeFile, 150);

        $audiobook = [
            'path' => $tempDir,
            'files' => [$smallFile, $mediumFile, $largeFile],
        ];

        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('analyzeAudioFilesInBackground');
        $method->setAccessible(true);

        $analysis = $method->invoke($this->command, $audiobook);

        $this->assertSame(3, $analysis['total_files']);
        $this->assertSame(3, $analysis['audio_files']);
        $this->assertSame($largeFile, $analysis['largest_file']['path']);
        $this->assertSame(150, $analysis['largest_file']['size']);
        $this->assertSame($smallFile, $analysis['smallest_file']['path']);
        $this->assertSame(50, $analysis['smallest_file']['size']);
        $this->assertSame(300, $analysis['total_size']);
        $this->assertEqualsWithDelta(100.0, (float) $analysis['average_file_size'], 0.00001);

        File::deleteDirectory($tempDir);
    }

    private function createSizedFile(string $path, int $sizeBytes): void
    {
        $handle = fopen($path, 'w');
        $this->assertNotFalse($handle);
        $this->assertTrue(ftruncate($handle, $sizeBytes));
        fclose($handle);
    }
}
