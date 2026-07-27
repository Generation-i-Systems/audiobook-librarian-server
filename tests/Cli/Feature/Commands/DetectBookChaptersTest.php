<?php

declare(strict_types=1);

namespace Tests\Cli\Feature\Commands;

use App\Models\Author;
use App\Models\Book;
use App\Services\ChapterDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DetectBookChaptersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('books');
    }

    public function testCommandDetectsChaptersForSpecificBooks(): void
    {
        $book = $this->bookWithFile('Detected Book', 'Author/Detected/chapter.m4b', ['Chapter Author']);

        $this->mock(ChapterDetectionService::class, function ($mock) use ($book): void {
            $mock->shouldReceive('detectForDirectory')
                ->once()
                ->with($book->directory_path)
                ->andReturn([
                    [
                        'title' => 'Opening',
                        'start' => 0.0,
                        'duration' => 60.0,
                        'file' => 'chapter.m4b',
                    ],
                ]);
        });

        $exit = Artisan::call('books:detect-chapters', [
            'books' => [$book->id],
        ]);

        $json = $this->libraryJson($book);
        $book->refresh()->load('chapters');

        $this->assertSame(0, $exit);
        $this->assertSame([
            [
                'chapter_number' => 1,
                'title' => 'Opening',
                'start_seconds' => 0.0,
                'file_name' => 'chapter.m4b',
                'duration' => 60,
                'source' => 'embedded',
            ],
        ], $book->chapters->map(fn ($chapter): array => [
            'chapter_number' => $chapter->chapter_number,
            'title' => $chapter->title,
            'start_seconds' => $chapter->start_seconds,
            'file_name' => $chapter->file_name,
            'duration' => $chapter->duration,
            'source' => $chapter->source,
        ])->all());
        $this->assertSame([
            [
                'title' => 'Opening',
                'start' => 0,
                'duration' => 60,
                'file' => 'chapter.m4b',
            ],
        ], $json['chapters']);
        $this->assertStringContainsString(
            "Book {$book->id} - Chapter Author - Detected Book: 1 chapters available",
            Artisan::output()
        );
    }

    public function testBookOptionSupportsInclusiveRanges(): void
    {
        $first = $this->bookWithFile('First', 'Author/First/chapter.m4b');
        $second = $this->bookWithFile('Second', 'Author/Second/chapter.m4b');
        $third = $this->bookWithFile('Third', 'Author/Third/chapter.m4b');
        $this->bookWithFile('Fourth', 'Author/Fourth/chapter.m4b');

        $this->mock(ChapterDetectionService::class, function ($mock): void {
            $mock->shouldReceive('detectForDirectory')
                ->times(3)
                ->andReturn([
                    [
                        'title' => 'Opening',
                        'start' => 0.0,
                        'duration' => 60.0,
                        'file' => 'chapter.m4b',
                    ],
                ]);
        });

        $exit = Artisan::call('books:detect-chapters', [
            '--book' => [$first->id . '-' . $third->id],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame([
            $first->id,
            $second->id,
            $third->id,
        ], Book::query()
            ->whereHas('chapters')
            ->orderBy('id')
            ->pluck('id')
            ->all());
    }

    public function testCommandSkipsBooksThatAlreadyHaveChaptersUnlessRefreshIsUsed(): void
    {
        $book = $this->bookWithFile('Existing Book', 'Author/Existing/chapter.m4b', ['Chapter Author']);
        Storage::disk('books')->put($book->directory_path . '/librarian.json', json_encode([
            'title' => 'Existing Book',
            'chapters' => [
                [
                    'title' => 'Cached',
                    'start' => 10,
                    'duration' => 30,
                    'file' => 'chapter.m4b',
                ],
            ],
        ]));

        $this->mock(ChapterDetectionService::class, function ($mock) use ($book): void {
            $mock->shouldReceive('detectForDirectory')
                ->once()
                ->with($book->directory_path)
                ->andReturn([
                    [
                        'title' => 'Refreshed',
                        'start' => 0.0,
                        'duration' => 45.0,
                        'file' => 'chapter.m4b',
                    ],
                ]);
        });

        $normalExit = Artisan::call('books:detect-chapters', [
            'books' => [$book->id],
        ]);
        $afterNormalRun = $this->libraryJson($book);
        $book->refresh()->load('chapters');
        $chaptersAfterNormalRun = $book->chapters;

        $refreshExit = Artisan::call('books:detect-chapters', [
            'books' => [$book->id],
            '--refresh' => true,
        ]);
        $afterRefreshRun = $this->libraryJson($book);

        $this->assertSame(0, $normalExit);
        $this->assertSame(0, $refreshExit);
        $this->assertSame('Cached', $afterNormalRun['chapters'][0]['title']);
        $this->assertSame('Cached', $chaptersAfterNormalRun->first()->title);
        $this->assertSame('librarian_json', $chaptersAfterNormalRun->first()->source);
        $this->assertSame('Refreshed', $afterRefreshRun['chapters'][0]['title']);
    }

    public function testCommandRequiresASelection(): void
    {
        $exit = Artisan::call('books:detect-chapters');

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Select books with book ids, --book, --newest, or --all.', Artisan::output());
    }

    /**
     * @param array<int, string> $authors
     */
    private function bookWithFile(string $title, string $filePath, array $authors = []): Book
    {
        $directoryPath = dirname($filePath);
        $book = Book::factory()->create([
            'title' => $title,
            'source' => 'test',
            'directory_path' => $directoryPath,
        ]);

        foreach ($authors as $authorName) {
            $author = Author::query()->create(['name' => $authorName]);
            $book->authors()->attach($author->id);
        }

        Storage::disk('books')->put($filePath, 'audio bytes');

        return $book;
    }

    /**
     * @return array<string, mixed>
     */
    private function libraryJson(Book $book): array
    {
        $json = Storage::disk('books')->get($book->directory_path . '/librarian.json');
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
