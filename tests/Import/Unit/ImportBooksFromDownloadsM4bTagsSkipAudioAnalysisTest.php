<?php

namespace Tests\Import\Unit\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use App\Services\AIBookProcessor;
use Mockery;
use Tests\TestCase;

class ImportBooksFromDownloadsM4bTagsSkipAudioAnalysisTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function handleLowConfidenceMetadataSkipsAudioAnalysisWhenM4bTagsProvideCriticalFields(): void
    {
        $command = new ImportBooksFromDownloadsM4bTagsSkipAudioAnalysisTestDouble();

        /** @var AIBookProcessor $aiProcessor */
        $aiProcessor = Mockery::mock(AIBookProcessor::class);
        // @phpstan-ignore-next-line
        $aiProcessor->shouldReceive('extractFileTags')
            ->once()
            ->andReturn([
                'album' => 'My Book Title',
                'artist' => 'Some Author',
                'genre' => 'Science Fiction',
                'year' => '2020',
                'narrator' => 'Great Narrator',
            ]);

        // Inject via reflection to ensure we access the protected parent property
        $reflection = new \ReflectionClass($command);
        $property = $reflection->getProperty('aiProcessor');
        $property->setAccessible(true);
        $property->setValue($command, $aiProcessor);

        $audiobook = [
            'path' => '/tmp/book',
            'name' => 'dir-name',
            'files' => [
                '/tmp/book/book.m4b',
            ],
        ];

        $aiMetadata = ['confidence' => 0];

        $shouldSkipBook = $command->exposeHandleLowConfidenceMetadata($audiobook, $aiMetadata);

        $this->assertFalse($shouldSkipBook);
        $this->assertFalse($command->audioAnalysisCalled);

        $this->assertSame('My Book Title', $aiMetadata['title']);
        $this->assertSame(['Some Author'], $aiMetadata['author']);
        $this->assertSame(['Science Fiction'], $aiMetadata['genre']);
        $this->assertSame(2020, $aiMetadata['year']);
        $this->assertSame(['Great Narrator'], $aiMetadata['narrator']);
    }
}

class ImportBooksFromDownloadsM4bTagsSkipAudioAnalysisTestDouble extends ImportBooksFromDownloads
{
    public bool $audioAnalysisCalled = false;

    public function __construct()
    {
        parent::__construct(null);
    }

    public function exposeHandleLowConfidenceMetadata(array $audiobook, ?array &$aiMetadata): bool
    {
        return $this->handleLowConfidenceMetadata($audiobook, $aiMetadata);
    }

    public function option($key = null): mixed
    {
        if ($key === 'min-confidence') {
            return '80';
        }

        if ($key === 'force-audio') {
            return false;
        }

        if ($key === 'auto') {
            return false;
        }

        return null;
    }

    protected function processWithAudioAnalysis(array $audiobook): ?array
    {
        $this->audioAnalysisCalled = true;

        return null;
    }
}
