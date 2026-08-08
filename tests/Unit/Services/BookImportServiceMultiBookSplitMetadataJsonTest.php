<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceMultiBookSplitMetadataJsonTest extends TestCase
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

        $this->parentDir = sys_get_temp_dir() . '/multi_book_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->parentDir);
        parent::tearDown();
    }

    /**
     * Each book already lives in its own "Book N" subfolder alongside its own
     * metadata.json and cover.jpg — the real-world layout this bug came from.
     */
    private function createBookPart(int $number, string $filename, array $chapters = []): string
    {
        $dir = $this->parentDir . '/Book ' . $number;
        File::makeDirectory($dir, 0775, true);

        $audioPath = $dir . '/' . $filename;
        File::put($audioPath, 'fake audio');

        File::put($dir . '/metadata.json', json_encode([
            'title' => "Part {$number} Title",
            'authors' => ['Some Author'],
            'chapters' => $chapters,
        ]));
        File::put($dir . '/cover.jpg', 'fake cover bytes');

        return $audioPath;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findCompanionFilesFindsMetadataJsonAndCoverBesideTheReferenceFile(): void
    {
        $audioPath = $this->createBookPart(1, 'Book_1_Title.m4b');

        $companions = $this->service->findCompanionFiles($audioPath, [$audioPath]);

        sort($companions);
        $expected = [$this->parentDir . '/Book 1/cover.jpg', $this->parentDir . '/Book 1/metadata.json'];
        sort($expected);

        $this->assertSame($expected, $companions);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findCompanionFilesExcludesFilesAlreadyInTheList(): void
    {
        $audioPath = $this->createBookPart(1, 'Book_1_Title.m4b');
        $metadataPath = $this->parentDir . '/Book 1/metadata.json';

        $companions = $this->service->findCompanionFiles($audioPath, [$audioPath, $metadataPath]);

        $this->assertSame([$this->parentDir . '/Book 1/cover.jpg'], $companions);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function processMultiBookSplitMergesEachPartsOwnMetadataJsonIncludingChapters(): void
    {
        $audio1 = $this->createBookPart(1, 'Book_1_Title.m4b', [
            ['start' => 0, 'end' => 10, 'title' => 'Chapter 1'],
        ]);
        $audio2 = $this->createBookPart(2, 'Book_2_Title.m4b', [
            ['start' => 0, 'end' => 20, 'title' => 'Chapter A'],
            ['start' => 20, 'end' => 40, 'title' => 'Chapter B'],
        ]);

        $audiobook = [
            'path' => $this->parentDir,
            'files' => [$audio1, $audio2],
        ];

        $multiBookInfo = [
            'series_name' => 'Test Series',
            'numbers' => [1, 2],
        ];

        $splitGroups = $this->service->analyzeMultiBookFiles($audiobook, $multiBookInfo);

        $books = $this->service->processMultiBookSplit(
            $audiobook,
            $multiBookInfo,
            $splitGroups,
            ['title' => 'AI Guessed Title', 'author' => ['AI Guessed Author']]
        );

        $this->assertCount(2, $books);

        $byTitle = collect($books)->keyBy(fn ($b) => $b['metadata']['title']);

        $part1 = $byTitle['Part 1 Title']['metadata'];
        $this->assertSame(['Some Author'], $part1['author']);
        $this->assertCount(1, $part1['chapters']);
        $this->assertSame('Chapter 1', $part1['chapters'][0]['title']);
        $this->assertSame(100, $part1['confidence']);

        $part2 = $byTitle['Part 2 Title']['metadata'];
        $this->assertCount(2, $part2['chapters']);

        // Companion files must be included in the file list handed to the mover,
        // or they're silently left behind in the source directory.
        $part1Files = $byTitle['Part 1 Title']['audiobook']['multi_book_files_only'];
        $this->assertContains($this->parentDir . '/Book 1/metadata.json', $part1Files);
        $this->assertContains($this->parentDir . '/Book 1/cover.jpg', $part1Files);
    }
}
