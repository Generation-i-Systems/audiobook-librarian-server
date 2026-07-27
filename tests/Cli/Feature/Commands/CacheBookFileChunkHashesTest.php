<?php

declare(strict_types=1);

namespace Tests\Cli\Feature\Commands;

use App\Models\Book;
use App\Models\BookFileChunkHash;
use App\Models\Author;
use App\Services\BookFileChunkHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CacheBookFileChunkHashesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('books');
    }

    public function testCommandCachesSpecificBooksFromArgumentAndOption(): void
    {
        $first = $this->bookWithFile('First', 'Author/First/chapter.mp3', 'first bytes', ['First Author']);
        $second = $this->bookWithFile('Second', 'Author/Second/chapter.mp3', 'second bytes', [
            'Second Author',
            'Co Author',
        ]);
        $this->bookWithFile('Third', 'Author/Third/chapter.mp3', 'third bytes');

        $exit = Artisan::call('books:cache-file-chunk-hashes', [
            'books' => [$first->id],
            '--book' => [$second->id],
        ]);

        $this->assertSame(0, $exit);
        $paths = BookFileChunkHash::query()->pluck('file_path')->all();
        sort($paths);

        $this->assertSame([
            'Author/First/chapter.mp3',
            'Author/Second/chapter.mp3',
        ], $paths);
        $output = Artisan::output();

        $this->assertStringContainsString(
            "Book {$first->id} - First Author - First: 1 files hashed",
            $output
        );
        $this->assertStringContainsString(
            "Book {$second->id} - Second Author, Co Author - Second: 1 files hashed",
            $output
        );
        $this->assertMatchesRegularExpression(
            "/Book {$first->id} - First Author - First: 1 files hashed in (\\d+ms|\\d+\\.\\d{2}s)/",
            $output
        );
    }

    public function testBookOptionSupportsInclusiveRanges(): void
    {
        $first = $this->bookWithFile('First', 'Author/First/chapter.mp3', 'first bytes');
        $second = $this->bookWithFile('Second', 'Author/Second/chapter.mp3', 'second bytes');
        $third = $this->bookWithFile('Third', 'Author/Third/chapter.mp3', 'third bytes');
        $this->bookWithFile('Fourth', 'Author/Fourth/chapter.mp3', 'fourth bytes');

        $exit = Artisan::call('books:cache-file-chunk-hashes', [
            '--book' => [$first->id . '-' . $third->id],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame([
            $first->id,
            $second->id,
            $third->id,
        ], BookFileChunkHash::query()
            ->select('book_id')
            ->distinct()
            ->orderBy('book_id')
            ->pluck('book_id')
            ->all());
    }

    public function testCommandOnlyPrintsNonZeroSecondaryCounts(): void
    {
        $book = $this->bookWithFile('First', 'Author/First/chapter.mp3', 'first bytes', ['First Author']);
        Storage::disk('books')->put('Author/First/second.mp3', 'second bytes');
        app(BookFileChunkHashService::class)->cacheBook($book);

        sleep(1);
        Storage::disk('books')->put('Author/First/chapter.mp3', 'changed first bytes');

        $exit = Artisan::call('books:cache-file-chunk-hashes', [
            'books' => [$book->id],
            '--refresh' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression(
            "/Book {$book->id} - First Author - First: 1 files hashed \\(1 cached\\) in (\\d+ms|\\d+\\.\\d{2}s)/",
            Artisan::output()
        );
    }

    public function testCommandCachesNewestBooksOnly(): void
    {
        $oldest = $this->bookWithFile('Oldest', 'Author/Oldest/chapter.mp3', 'oldest bytes');
        $newest = $this->bookWithFile('Newest', 'Author/Newest/chapter.mp3', 'newest bytes');

        $oldest->forceFill(['created_at' => now()->subDay()])->save();
        $newest->forceFill(['created_at' => now()])->save();

        $exit = Artisan::call('books:cache-file-chunk-hashes', [
            '--newest' => 1,
        ]);

        $this->assertSame(0, $exit);
        $paths = BookFileChunkHash::query()->pluck('file_path')->all();
        sort($paths);

        $this->assertSame([
            'Author/Newest/chapter.mp3',
        ], $paths);
    }

    public function testNewestOptionAdvancesPastAlreadyCachedBooks(): void
    {
        $oldest = $this->bookWithFile('Oldest', 'Author/Oldest/chapter.mp3', 'oldest bytes');
        $middle = $this->bookWithFile('Middle', 'Author/Middle/chapter.mp3', 'middle bytes');
        $newest = $this->bookWithFile('Newest', 'Author/Newest/chapter.mp3', 'newest bytes');

        $oldest->forceFill(['created_at' => now()->subDays(2)])->save();
        $middle->forceFill(['created_at' => now()->subDay()])->save();
        $newest->forceFill(['created_at' => now()])->save();

        $firstExit = Artisan::call('books:cache-file-chunk-hashes', [
            '--newest' => 1,
        ]);
        $secondExit = Artisan::call('books:cache-file-chunk-hashes', [
            '--newest' => 1,
        ]);

        $this->assertSame(0, $firstExit);
        $this->assertSame(0, $secondExit);

        $paths = BookFileChunkHash::query()->pluck('file_path')->all();
        sort($paths);

        $this->assertSame([
            'Author/Middle/chapter.mp3',
            'Author/Newest/chapter.mp3',
        ], $paths);
    }

    public function testNormalModeSkipsCachedBooksUntilRefreshChecksFileMetadata(): void
    {
        $staleNewest = $this->bookWithFile('Newest', 'Author/Newest/chapter.mp3', 'old bytes');
        $uncachedMiddle = $this->bookWithFile('Middle', 'Author/Middle/chapter.mp3', 'middle bytes');

        $uncachedMiddle->forceFill(['created_at' => now()->subDay()])->save();
        $staleNewest->forceFill(['created_at' => now()])->save();

        app(BookFileChunkHashService::class)->cacheBook($staleNewest);
        sleep(1);
        Storage::disk('books')->put('Author/Newest/chapter.mp3', 'new bytes');

        $normalExit = Artisan::call('books:cache-file-chunk-hashes', [
            '--newest' => 1,
        ]);
        $refreshExit = Artisan::call('books:cache-file-chunk-hashes', [
            '--newest' => 1,
            '--refresh' => true,
        ]);

        $this->assertSame(0, $normalExit);
        $this->assertSame(0, $refreshExit);
        $this->assertSame([
            'middle_cached' => true,
            'newest_checksum' => hash('sha256', 'new bytes'),
        ], [
            'middle_cached' => BookFileChunkHash::query()
                ->where('book_id', $uncachedMiddle->id)
                ->where('file_path', 'Author/Middle/chapter.mp3')
                ->exists(),
            'newest_checksum' => BookFileChunkHash::query()
                ->where('book_id', $staleNewest->id)
                ->where('file_path', 'Author/Newest/chapter.mp3')
                ->value('sha256'),
        ]);
    }

    public function testCommandLoadThresholdSkipsWorkWithoutFailure(): void
    {
        $this->bookWithFile('First', 'Author/First/chapter.mp3', 'first bytes');

        $exit = Artisan::call('books:cache-file-chunk-hashes', [
            '--all' => true,
            '--max-load' => 0,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, BookFileChunkHash::count());
    }

    public function testCommandDoesNotPrintBookLineForMissingDirectory(): void
    {
        $missing = Book::factory()->create([
            'title' => 'Missing',
            'source' => 'test',
            'directory_path' => 'missing/book',
        ]);

        $exit = Artisan::call('books:cache-file-chunk-hashes', [
            '--all' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString("Book {$missing->id}", Artisan::output());
    }

    /**
     * @param array<int, string> $authors
     */
    private function bookWithFile(string $title, string $filePath, string $contents, array $authors = []): Book
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

        Storage::disk('books')->put($filePath, $contents);

        return $book;
    }
}
