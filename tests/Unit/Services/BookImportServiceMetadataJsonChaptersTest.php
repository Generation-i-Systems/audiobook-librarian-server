<?php

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\Chapter;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use App\Support\ConfirmedBookMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceMetadataJsonChaptersTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $genreMappingService = $this->app->make(GenreMappingService::class);
        $sourceTrashService = $this->app->make(SourceTrashService::class);
        $this->service = new BookImportService($genreMappingService, $sourceTrashService);
    }

    private function writeAudioBookShelfMetadataJson(string $directory, array $chapters): void
    {
        File::makeDirectory($directory, 0775, true);
        File::put($directory . '/metadata.json', json_encode([
            'title' => 'The Realm Dungeon: Primer for the Apocalypse, Book 3',
            'authors' => ['Braided Sky'],
            'narrators' => ['Eliza Summers'],
            'series' => ['Primer For The Apocalypse #3'],
            'genres' => [],
            'publishedYear' => '2025',
            'description' => 'A book about a dungeon.',
            'language' => 'English',
            'abridged' => false,
            'chapters' => $chapters,
        ]));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function readMetadataJsonNormalizesStartEndChaptersAndInfersTheSingleAudioFile(): void
    {
        $dir = sys_get_temp_dir() . '/metadata_json_' . uniqid('', true);
        $this->writeAudioBookShelfMetadataJson($dir, [
            ['start' => 0, 'end' => 17.07898, 'title' => 'Opening Credits', 'id' => 0],
            ['start' => 17.07898, 'end' => 683.083968, 'title' => 'Chapter 1', 'id' => 1],
        ]);
        File::put($dir . '/book.m4b', 'fake audio');

        try {
            $result = $this->service->readMetadataJson($dir);

            $this->assertNotNull($result);
            $this->assertSame('The Realm Dungeon: Primer for the Apocalypse, Book 3', $result['title']);
            $this->assertSame(['Braided Sky'], $result['author']);
            $this->assertSame('Primer For The Apocalypse', $result['series']);
            $this->assertSame('3', $result['series_number']);

            $this->assertCount(2, $result['chapters']);
            $this->assertSame([
                'chapter_number' => 1,
                'title' => 'Opening Credits',
                'start_seconds' => 0.0,
                'duration' => 17.07898,
                'file_name' => 'book.m4b',
            ], $result['chapters'][0]);
            $this->assertSame(2, $result['chapters'][1]['chapter_number']);
            $this->assertSame('Chapter 1', $result['chapters'][1]['title']);
            $this->assertSame('metadata_json', $result['chapters_source']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function readMetadataJsonLeavesFileNameBlankWhenThereIsNotExactlyOneAudioFile(): void
    {
        $dir = sys_get_temp_dir() . '/metadata_json_' . uniqid('', true);
        $this->writeAudioBookShelfMetadataJson($dir, [
            ['start' => 0, 'end' => 10, 'title' => 'Chapter 1'],
        ]);
        File::put($dir . '/part1.m4b', 'fake audio');
        File::put($dir . '/part2.m4b', 'fake audio');

        try {
            $result = $this->service->readMetadataJson($dir);

            $this->assertSame('', $result['chapters'][0]['file_name']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function readMetadataJsonReturnsNoChaptersKeyWhenNoneArePresent(): void
    {
        $dir = sys_get_temp_dir() . '/metadata_json_' . uniqid('', true);
        $this->writeAudioBookShelfMetadataJson($dir, []);

        try {
            $result = $this->service->readMetadataJson($dir);

            $this->assertArrayNotHasKey('chapters', $result);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function persistConfirmedBookSeedsChaptersFromMetadata(): void
    {
        $bookRoot = sys_get_temp_dir() . '/book_root_' . uniqid('', true);
        File::makeDirectory($bookRoot, 0775, true);
        config(['app.book_root' => $bookRoot]);
        config(['filesystems.disks.books.root' => $bookRoot]);

        $metadata = [
            'title' => 'Primer for the Apocalypse',
            'author' => ['Braided Sky'],
            'custom_directory_path' => 'Fantasy/Braided Sky/Primer/03 Primer for the Apocalypse',
            'chapters' => [
                ['chapter_number' => 1, 'title' => 'Opening Credits', 'start_seconds' => 0.0, 'duration' => 17.1, 'file_name' => 'book.m4b'],
                ['chapter_number' => 2, 'title' => 'Chapter 1', 'start_seconds' => 17.1, 'duration' => 666.0, 'file_name' => 'book.m4b'],
            ],
        ];

        $book = $this->service->persistConfirmedBook(
            ConfirmedBookMetadata::fromConfirmed($metadata),
            ['files' => []]
        );

        $this->assertNotNull($book);
        $this->assertCount(2, $book->chapters);
        $firstChapter = Chapter::where('book_id', $book->id)->orderBy('chapter_number')->first();
        $this->assertNotNull($firstChapter);
        $this->assertSame('Opening Credits', $firstChapter->title);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function persistConfirmedBookUpdateDoesNotOverwriteExistingChapters(): void
    {
        $bookRoot = sys_get_temp_dir() . '/book_root_' . uniqid('', true);
        File::makeDirectory($bookRoot, 0775, true);
        config(['app.book_root' => $bookRoot]);
        config(['filesystems.disks.books.root' => $bookRoot]);

        $book = Book::create([
            'title' => 'Existing Book',
            'directory_path' => 'Fantasy/Author/Book',
            'language' => 'en',
        ]);
        $book->chapters()->create([
            'chapter_number' => 1,
            'title' => 'Manually Detected Chapter',
            'file_name' => 'book.m4b',
        ]);

        $this->service->persistConfirmedBookUpdate(
            $book,
            ConfirmedBookMetadata::fromConfirmed([
                'title' => 'Existing Book',
                'chapters' => [
                    ['chapter_number' => 1, 'title' => 'From metadata.json', 'start_seconds' => 0.0, 'duration' => 10.0, 'file_name' => 'book.m4b'],
                ],
            ]),
            ['files' => []]
        );

        $chapter = Chapter::where('book_id', $book->id)->first();
        $this->assertNotNull($chapter);
        $this->assertSame('Manually Detected Chapter', $chapter->title);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function persistConfirmedBookUpdateSeedsChaptersWhenBookHadNone(): void
    {
        $bookRoot = sys_get_temp_dir() . '/book_root_' . uniqid('', true);
        File::makeDirectory($bookRoot, 0775, true);
        config(['app.book_root' => $bookRoot]);
        config(['filesystems.disks.books.root' => $bookRoot]);

        $book = Book::create([
            'title' => 'Existing Book',
            'directory_path' => 'Fantasy/Author/Book',
            'language' => 'en',
        ]);

        $this->service->persistConfirmedBookUpdate(
            $book,
            ConfirmedBookMetadata::fromConfirmed([
                'title' => 'Existing Book',
                'chapters' => [
                    ['chapter_number' => 1, 'title' => 'From metadata.json', 'start_seconds' => 0.0, 'duration' => 10.0, 'file_name' => 'book.m4b'],
                ],
            ]),
            ['files' => []]
        );

        $chapter = Chapter::where('book_id', $book->id)->first();
        $this->assertNotNull($chapter);
        $this->assertSame('From metadata.json', $chapter->title);
    }
}
