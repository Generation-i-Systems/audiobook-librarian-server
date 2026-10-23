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

    /**
     * Regression test: a whole-batch ISBN (read from whichever file the upfront scan
     * sampled) was copied onto every split book, so findExistingBook() matched each new
     * book to the first one by ISBN and overwrote that single record four times. When a
     * per-book AI result exists, the parent folder's AI result must not be used at all.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function processMultiBookSplitIgnoresWholeBatchAiResultWhenPerBookAiRuns(): void
    {
        $book1Path = $this->parentDir . '/Charlie Higson - Young Bond Book 1 - Blood Fever.m4b';
        $book2Path = $this->parentDir . '/Charlie Higson - Young Bond Book 2 - By Royal Command.m4b';
        File::put($book1Path, 'fake audio');
        File::put($book2Path, 'fake audio');

        $audiobook = ['path' => $this->parentDir, 'files' => [$book1Path, $book2Path]];
        $multiBookInfo = ['series_name' => 'Young Bond', 'numbers' => [1, 2]];
        $splitGroups = $this->service->analyzeMultiBookFiles($audiobook, $multiBookInfo);

        $wholeBatchAiMetadata = [
            'title' => 'Young Bond',
            'isbn' => '9781445892832',
            'asin' => 'B007GE1XTU',
            'description' => 'Whole-batch description of By Royal Command',
            'year' => 2012,
            'publisher' => 'Whole-Batch Publisher',
        ];
        $book2Publisher = 'Book Two Publisher';
        $book1Isbn = '9781111111111';

        $aiProcessor = \Mockery::mock(AIBookProcessor::class);
        $aiProcessor->shouldReceive('processBookDirectory')
            ->withArgs(fn ($directoryPath, $fileNames) => $fileNames === [basename($book1Path)])
            ->andReturn(['author' => ['Charlie Higson'], 'isbn' => $book1Isbn]);
        $aiProcessor->shouldReceive('processBookDirectory')
            ->withArgs(fn ($directoryPath, $fileNames) => $fileNames === [basename($book2Path)])
            ->andReturn(['author' => ['Charlie Higson'], 'publisher' => $book2Publisher]);

        $books = $this->service->processMultiBookSplit(
            $audiobook,
            $multiBookInfo,
            $splitGroups,
            $wholeBatchAiMetadata,
            null,
            null,
            $aiProcessor
        );

        $identity = array_map(static fn (array $book): array => [
            'isbn' => $book['metadata']['isbn'] ?? null,
            'asin' => $book['metadata']['asin'] ?? null,
            'description' => $book['metadata']['description'] ?? null,
            'year' => $book['metadata']['year'] ?? null,
            'publisher' => $book['metadata']['publisher'] ?? null,
        ], $books);

        $this->assertSame([
            ['isbn' => $book1Isbn, 'asin' => null, 'description' => null, 'year' => null, 'publisher' => null],
            ['isbn' => null, 'asin' => null, 'description' => null, 'year' => null, 'publisher' => $book2Publisher],
        ], $identity);
    }
}
