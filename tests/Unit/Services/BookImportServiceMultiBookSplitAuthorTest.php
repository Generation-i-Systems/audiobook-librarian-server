<?php

namespace Tests\Unit\Services;

use App\Services\AIBookProcessor;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceMultiBookSplitAuthorTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    private string $parentDir;

    protected function setUp(): void
    {
        parent::setUp();

        $genreMappingService = $this->app->make(GenreMappingService::class);
        $sourceTrashService = $this->app->make(SourceTrashService::class);
        $this->service = new BookImportService($genreMappingService, $sourceTrashService);

        $this->parentDir = sys_get_temp_dir() . '/multi_book_author_' . uniqid('', true);
        File::makeDirectory($this->parentDir, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->parentDir);
        parent::tearDown();
    }

    /**
     * Regression test: a multi-book split must not attribute one book's author to
     * another. Previously, every split-out book inherited the single whole-batch AI
     * guess (sampled from an arbitrary subset of the folder's files), which could
     * belong to a different book than the one being built. Each split book must now
     * get its own author via a fresh, per-book AI call using only its own file.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function processMultiBookSplitUsesPerBookAiResultInsteadOfWholeBatchGuess(): void
    {
        $book4Path = $this->parentDir . "/A Sinner's Saint - De Bellis Crime Family, Book 4.mp3";
        $book5Path = $this->parentDir . "/A Sinner's Truth - De Bellis Crime Family, Book 5.mp3";
        File::put($book4Path, 'fake audio');
        File::put($book5Path, 'fake audio');

        $audiobook = [
            'path' => $this->parentDir,
            'files' => [$book4Path, $book5Path],
        ];

        $multiBookInfo = [
            'series_name' => 'De Bellis Crime Family',
            'numbers' => [4, 5],
        ];

        $splitGroups = $this->service->analyzeMultiBookFiles($audiobook, $multiBookInfo);

        // The whole-batch AI guess (as if sampled from a different book's files
        // entirely) — must not end up as either split book's author.
        $wholeBatchAiMetadata = [
            'title' => 'De Bellis Crime Family',
            'author' => ['Wrong Whole-Batch Author'],
            'narrator' => ['Wrong Whole-Batch Narrator'],
        ];

        $aiProcessor = \Mockery::mock(AIBookProcessor::class);
        $aiProcessor->shouldReceive('processBookDirectory')
            ->once()
            ->withArgs(fn ($directoryPath, $fileNames) => $fileNames === [basename($book4Path)])
            ->andReturn(['author' => ['Kylie Kent'], 'narrator' => []]);
        $aiProcessor->shouldReceive('processBookDirectory')
            ->once()
            ->withArgs(fn ($directoryPath, $fileNames) => $fileNames === [basename($book5Path)])
            ->andReturn(['author' => ['Kylie Kent'], 'narrator' => ['Some Narrator']]);

        $books = $this->service->processMultiBookSplit(
            $audiobook,
            $multiBookInfo,
            $splitGroups,
            $wholeBatchAiMetadata,
            null,
            null,
            $aiProcessor
        );

        $this->assertCount(2, $books);

        foreach ($books as $book) {
            $this->assertSame(['Kylie Kent'], $book['metadata']['author']);
            $this->assertNotSame(['Wrong Whole-Batch Author'], $book['metadata']['author']);
        }
    }
}
