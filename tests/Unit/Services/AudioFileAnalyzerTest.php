<?php

namespace Tests\Unit\Services;

use App\Services\AudioFileAnalyzer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AudioFileAnalyzerTest extends TestCase
{
    protected AudioFileAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new AudioFileAnalyzer();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function analyzeDirectoryReturnsNullForNonExistentDirectory(): void
    {
        $result = $this->analyzer->analyzeDirectory('/non/existent/path');

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function analyzeDirectoryReturnsNullForDirectoryWithoutAudioFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_audio_' . uniqid();
        File::makeDirectory($tempDir);

        // Create a non-audio file
        file_put_contents($tempDir . '/test.txt', 'test content');

        $result = $this->analyzer->analyzeDirectory($tempDir);

        File::deleteDirectory($tempDir);

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function formatDurationFormatsSecondsCorrectly(): void
    {
        $this->assertEquals('00:00:30', $this->analyzer->formatDuration(30));
        $this->assertEquals('00:01:00', $this->analyzer->formatDuration(60));
        $this->assertEquals('01:00:00', $this->analyzer->formatDuration(3600));
        $this->assertEquals('01:30:45', $this->analyzer->formatDuration(5445));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAudioDurationReturnsNullForNonExistentFile(): void
    {
        $result = $this->analyzer->getAudioDuration('/non/existent/file.mp3');

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAudioDurationReturnsNullForNonAudioFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'test content');

        $result = $this->analyzer->getAudioDuration($tempFile);

        unlink($tempFile);

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getDirectoryAudioDurationReturnsZeroForEmptyDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_audio_' . uniqid();
        File::makeDirectory($tempDir);

        $result = $this->analyzer->getDirectoryAudioDuration($tempDir);

        File::deleteDirectory($tempDir);

        $this->assertEquals(0, $result['total_seconds']);
        $this->assertEquals('00:00:00', $result['formatted']);
        $this->assertEquals(0, $result['file_count']);
    }
}
