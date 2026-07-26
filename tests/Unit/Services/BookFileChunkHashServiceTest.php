<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\BookFileChunkHash;
use App\Services\BookFileChunkHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookFileChunkHashServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookFileChunkHashService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('books');
        $this->service = app(BookFileChunkHashService::class);
    }

    public function testGetOrGenerateCreatesAndReusesFreshChunkHashCache(): void
    {
        $book = Book::factory()->create(['directory_path' => 'Author/Book']);
        Storage::disk('books')->put('Author/Book/chapter.mp3', 'chapter bytes');
        $fullPath = Storage::disk('books')->path('Author/Book/chapter.mp3');

        $first = $this->service->getOrGenerate($book->id, 'Author/Book/chapter.mp3', $fullPath);
        $second = $this->service->getOrGenerate($book->id, 'Author/Book/chapter.mp3', $fullPath);

        $this->assertSame([
            'first_generated' => true,
            'second_generated' => false,
            'record_count' => 1,
            'checksum' => hash('sha256', 'chapter bytes'),
            'chunks' => [
                [
                    'offset' => 0,
                    'size' => strlen('chapter bytes'),
                    'sha256' => hash('sha256', 'chapter bytes'),
                ],
            ],
        ], [
            'first_generated' => $first['generated'],
            'second_generated' => $second['generated'],
            'record_count' => BookFileChunkHash::count(),
            'checksum' => $second['record']->sha256,
            'chunks' => $second['record']->chunks,
        ]);
    }

    public function testGetOrGenerateRefreshesStaleCacheWhenFileChanges(): void
    {
        $book = Book::factory()->create(['directory_path' => 'Author/Book']);
        Storage::disk('books')->put('Author/Book/chapter.mp3', 'old bytes');
        $fullPath = Storage::disk('books')->path('Author/Book/chapter.mp3');

        $first = $this->service->getOrGenerate($book->id, 'Author/Book/chapter.mp3', $fullPath);

        sleep(1);
        Storage::disk('books')->put('Author/Book/chapter.mp3', 'new bytes with a different size');
        $second = $this->service->getOrGenerate($book->id, 'Author/Book/chapter.mp3', $fullPath);

        $this->assertSame([
            'first_generated' => true,
            'second_generated' => true,
            'record_count' => 1,
            'checksum' => hash('sha256', 'new bytes with a different size'),
        ], [
            'first_generated' => $first['generated'],
            'second_generated' => $second['generated'],
            'record_count' => BookFileChunkHash::count(),
            'checksum' => $second['record']->sha256,
        ]);
    }

    public function testCacheBookSkipsLibriVoxBooksAndMissingDirectories(): void
    {
        $librivox = Book::factory()->create([
            'source' => 'librivox',
            'directory_path' => 'remote/book',
        ]);
        $missing = Book::factory()->create([
            'source' => 'test',
            'directory_path' => 'missing/book',
        ]);

        $this->assertSame([
            'generated' => 0,
            'cached' => 0,
            'skipped' => 1,
            'missing' => 0,
        ], $this->service->cacheBook($librivox));

        $this->assertSame([
            'generated' => 0,
            'cached' => 0,
            'skipped' => 0,
            'missing' => 1,
        ], $this->service->cacheBook($missing));
    }
}
