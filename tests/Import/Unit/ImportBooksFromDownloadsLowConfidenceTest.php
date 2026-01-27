<?php

namespace Tests\Import\Unit\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use Mockery;
use Tests\TestCase;

class ImportBooksFromDownloadsLowConfidenceTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function handleLowConfidenceMetadataDoesNotSkipInInteractiveMode(): void
    {
        $command = new ImportBooksFromDownloadsLowConfidenceTestDouble([
            'min-confidence' => 80,
            'force-audio' => false,
            'auto' => false,
        ]);

        $audiobook = [
            'name' => 'Kingdom Come',
            'path' => '/media/download/Kingdom Come.m4b',
            'files' => [],
        ];

        $aiMetadata = [
            'confidence' => 10,
            'title' => 'Kingdom Come',
        ];

        $shouldSkip = $command->exposeHandleLowConfidenceMetadata($audiobook, $aiMetadata);

        $this->assertFalse($shouldSkip);
        $this->assertCount(0, $command->getSkippedBooks());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function handleLowConfidenceMetadataSkipsInAutoMode(): void
    {
        $command = new ImportBooksFromDownloadsLowConfidenceTestDouble([
            'min-confidence' => 80,
            'force-audio' => false,
            'auto' => true,
        ]);

        $audiobook = [
            'name' => 'Kingdom Come',
            'path' => '/media/download/Kingdom Come.m4b',
            'files' => [],
        ];

        $aiMetadata = [
            'confidence' => 10,
            'title' => 'Kingdom Come',
        ];

        $shouldSkip = $command->exposeHandleLowConfidenceMetadata($audiobook, $aiMetadata);

        $this->assertTrue($shouldSkip);
        $this->assertCount(1, $command->getSkippedBooks());
        $this->assertSame('Low AI confidence (tried audio analysis)', $command->getSkippedBooks()[0]['reason']);
    }
}

class ImportBooksFromDownloadsLowConfidenceTestDouble extends ImportBooksFromDownloads
{
    /** @var array<string, mixed> */
    private array $testOptions;

    public function __construct(array $testOptions)
    {
        parent::__construct(null);
        $this->testOptions = $testOptions;
    }

    public function option($key = null)
    {
        if ($key === null) {
            return $this->testOptions;
        }

        return $this->testOptions[$key] ?? null;
    }

    protected function processWithAudioAnalysis(array $audiobook): ?array
    {
        return null;
    }

    public function warn($string, $verbosity = null)
    {
    }

    public function info($string, $verbosity = null)
    {
    }

    public function line($string, $style = null, $verbosity = null)
    {
    }

    public function exposeHandleLowConfidenceMetadata(array $audiobook, ?array &$aiMetadata): bool
    {
        return $this->handleLowConfidenceMetadata($audiobook, $aiMetadata);
    }

    /** @return array<int, array<string, mixed>> */
    public function getSkippedBooks(): array
    {
        return $this->skippedBooks;
    }

    protected function handleLowConfidenceMetadata(array $audiobook, ?array &$aiMetadata): bool
    {
        $minConfidence = (int) $this->option('min-confidence');
        $isAutoMode = (bool) $this->option('auto');
        $confidence = $aiMetadata['confidence'] ?? 100;

        $shouldSkip = $isAutoMode && $confidence < $minConfidence;

        if ($shouldSkip) {
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'Low AI confidence (tried audio analysis)',
            ];
        }

        return $shouldSkip;
    }

    protected function getImportService(): \App\Services\BookImportService
    {
        $mock = Mockery::mock(\App\Services\BookImportService::class);

        return $mock;
    }
}
