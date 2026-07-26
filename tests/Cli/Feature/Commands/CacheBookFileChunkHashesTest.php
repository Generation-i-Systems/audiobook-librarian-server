<?php

declare(strict_types=1);

namespace Tests\Cli\Feature\Commands;

use App\Models\Book;
use App\Models\BookFileChunkHash;
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
        $first = $this->bookWithFile('First', 'Author/First/chapter.mp3', 'first bytes');
        $second = $this->bookWithFile('Second', 'Author/Second/chapter.mp3', 'second bytes');
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
            'Author/Newest/librarian.json',
        ], $paths);
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

    private function bookWithFile(string $title, string $filePath, string $contents): Book
    {
        $directoryPath = dirname($filePath);
        $book = Book::factory()->create([
            'title' => $title,
            'source' => 'test',
            'directory_path' => $directoryPath,
        ]);

        Storage::disk('books')->put($filePath, $contents);

        return $book;
    }
}
