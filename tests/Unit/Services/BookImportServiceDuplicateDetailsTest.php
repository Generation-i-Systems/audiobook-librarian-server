<?php

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Publisher;
use App\Models\Series;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceDuplicateDetailsTest extends TestCase
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function buildExistingBookDetailsMetadataFillsInFullDataAboutTheExistingBook(): void
    {
        $publisher = Publisher::create(['name' => 'Blackstone Publishing']);
        $book = Book::create([
            'title' => 'Ghost Chrysalis',
            'directory_path' => 'LitRPG/Plum Parrot/Cyber Dreams/02 Ghost Chrysalis',
            'language' => 'en',
            'release_date' => '2023-01-01',
            'description' => 'A cyberpunk LitRPG story.',
            'publisher_id' => $publisher->id,
        ]);
        $author = Author::create(['name' => 'Plum Parrot']);
        $narrator = Narrator::create(['name' => 'James Anderson Foster']);
        $genre = Genre::create(['name' => 'LitRPG']);
        $series = Series::create(['name' => 'Cyber Dreams']);
        $book->authors()->attach($author->id);
        $book->narrators()->attach($narrator->id);
        $book->genres()->attach($genre->id, ['is_primary' => true]);
        $book->series()->attach($series->id, ['series_number' => 2]);

        $details = $this->service->buildExistingBookDetailsMetadata($book, '/media/audiobooks/books/LitRPG/Plum Parrot/Cyber Dreams/02 Ghost Chrysalis');

        $this->assertSame('Ghost Chrysalis', $details['title']);
        $this->assertSame(['Plum Parrot'], $details['author']);
        $this->assertSame(['James Anderson Foster'], $details['narrator']);
        $this->assertSame('Cyber Dreams', $details['series']);
        $this->assertEquals(2, $details['series_number']);
        $this->assertSame('2023', $details['year']);
        $this->assertSame(['LitRPG'], $details['genre']);
        $this->assertSame('Blackstone Publishing', $details['publisher']);
        $this->assertSame('A cyberpunk LitRPG story.', $details['description']);
        $this->assertSame(100, $details['confidence']);
        $this->assertSame('/media/audiobooks/books/LitRPG/Plum Parrot/Cyber Dreams/02 Ghost Chrysalis', $details['source_path']);
        $this->assertSame('LitRPG/Plum Parrot/Cyber Dreams/02 Ghost Chrysalis', $details['directory_path']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function buildExistingBookDetailsMetadataHandlesABookWithNoRelations(): void
    {
        $book = Book::create([
            'title' => 'Standalone Book',
            'directory_path' => 'Fiction/Unknown/Standalone Book',
            'language' => 'en',
        ]);

        $details = $this->service->buildExistingBookDetailsMetadata($book);

        $this->assertSame('Standalone Book', $details['title']);
        $this->assertSame([], $details['author']);
        $this->assertSame([], $details['narrator']);
        $this->assertSame('', $details['series']);
        $this->assertSame('', $details['publisher']);
        $this->assertSame('Fiction/Unknown/Standalone Book', $details['source_path']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findAudioFilesInDirectoryReturnsOnlyAudioFilesSortedByName(): void
    {
        $dir = sys_get_temp_dir() . '/test_audio_' . uniqid();
        File::makeDirectory($dir, 0775, true);
        File::put($dir . '/02 Track.mp3', 'a');
        File::put($dir . '/01 Track.m4b', 'b');
        File::put($dir . '/cover.jpg', 'c');
        File::put($dir . '/notes.txt', 'd');

        $files = $this->service->findAudioFilesInDirectory($dir);

        $this->assertCount(2, $files);
        $this->assertStringContainsString('01 Track.m4b', $files[0]);
        $this->assertStringContainsString('02 Track.mp3', $files[1]);

        File::deleteDirectory($dir);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findAudioFilesInDirectoryReturnsEmptyForMissingDirectory(): void
    {
        $files = $this->service->findAudioFilesInDirectory('/nonexistent/path/' . uniqid());

        $this->assertSame([], $files);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function listDirectoryContentsPrintsEachFileWithSize(): void
    {
        $dir = sys_get_temp_dir() . '/test_list_' . uniqid();
        File::makeDirectory($dir, 0775, true);
        File::put($dir . '/track.mp3', str_repeat('a', 2048));

        $lines = [];
        $this->service->listDirectoryContents($dir, function ($line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertTrue(collect($lines)->contains(fn ($line) => str_contains($line, 'track.mp3') && str_contains($line, 'KB')));

        File::deleteDirectory($dir);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function listDirectoryContentsReportsEmptyDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/test_empty_' . uniqid();
        File::makeDirectory($dir, 0775, true);

        $lines = [];
        $this->service->listDirectoryContents($dir, function ($line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertTrue(collect($lines)->contains(fn ($line) => str_contains($line, 'empty directory')));

        File::deleteDirectory($dir);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function listDirectoryContentsReportsMissingPath(): void
    {
        $lines = [];
        $this->service->listDirectoryContents('/nonexistent/path/' . uniqid(), function ($line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertTrue(collect($lines)->contains(fn ($line) => str_contains($line, 'Not found')));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function playAudioFilesReportsWhenThereIsNothingToPlay(): void
    {
        $lines = [];
        $this->service->playAudioFiles([], function ($line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertTrue(collect($lines)->contains(fn ($line) => str_contains($line, 'No audio files found')));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function selectDuplicateActionHandlesPlayAndListOptionsThenReturnsTheFinalChoice(): void
    {
        $dir = sys_get_temp_dir() . '/test_dup_' . uniqid();
        File::makeDirectory($dir, 0775, true);
        File::put($dir . '/track.mp3', 'audio');

        $responses = ['l', 'x', '1'];
        $uiService = new class ($responses) {
            public array $responses;

            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function select(string $question, array $options, string $default): string
            {
                return array_shift($this->responses) ?? $default;
            }
        };

        $lines = [];
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('selectDuplicateAction');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->service,
            $uiService,
            'Choose an action',
            ['1' => 'Skip', '2' => 'Replace'],
            '1',
            [$dir . '/track.mp3'],
            $dir,
            $dir,
            function ($line) use (&$lines) {
                $lines[] = $line;
            }
        );

        $this->assertSame('1', $result);
        $this->assertTrue(collect($lines)->contains(fn ($line) => str_contains($line, 'track.mp3')));
        $this->assertGreaterThanOrEqual(2, count($lines));

        File::deleteDirectory($dir);
    }
}
