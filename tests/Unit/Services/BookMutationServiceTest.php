<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\EmbedBookJob;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Services\BookMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookMutationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function createBookCreatesRelatedRecordsFromNames(): void
    {
        $book = (new BookMutationService())->createBook([
            'title' => 'The Pragmatic Programmer',
            'coverImage' => '/tmp/covers/pragmatic.jpg',
            'authors' => ['Andy Hunt', 'Dave Thomas'],
            'narrators' => ['Narrator One'],
            'genres' => ['Programming'],
            'series' => [
                ['seriesName' => 'Classics', 'number' => '3'],
            ],
            'chapters' => [
                ['chapter_number' => 1, 'file_name' => 'intro.mp3', 'format' => 'mp3', 'duration' => 120, 'size_bytes' => 1024],
            ],
        ]);

        $book->refresh();
        $book->load(['authors', 'narrators', 'genres', 'series', 'chapters']);

        $this->assertSame('pragmatic.jpg', $book->cover_image);
        $this->assertSame(['Andy Hunt', 'Dave Thomas'], $book->authors->pluck('name')->all());
        $this->assertSame(['Narrator One'], $book->narrators->pluck('name')->all());
        $this->assertSame(['Programming'], $book->genres->pluck('name')->all());
        $this->assertSame('Classics', $book->series->first()?->name);
        /** @var \Illuminate\Database\Eloquent\Relations\Pivot&object{series_number: mixed} $pivot */
        $pivot = $book->series->first()->pivot;
        $this->assertSame('3', (string) $pivot->series_number);
        $this->assertSame('intro.mp3', $book->chapters->first()?->file_name);
    }

    #[Test]
    public function updateBookUsesExistingRelationIdsAndReplacesChapters(): void
    {
        $book = Book::factory()->create([
            'title' => 'Original Title',
            'cover_image' => 'old.jpg',
        ]);

        $existingAuthor = Author::query()->create(['name' => 'Existing Author']);
        $existingNarrator = Narrator::query()->create(['name' => 'Existing Narrator']);
        $existingGenre = Genre::query()->create(['name' => 'Existing Genre']);

        $book->chapters()->create(['chapter_number' => 1, 'file_name' => 'old.mp3', 'format' => 'mp3', 'duration' => 60, 'size_bytes' => 1000]);

        Queue::fake();

        $updated = (new BookMutationService())->updateBook((string) $book->id, [
            'title' => 'Updated Title',
            'authors' => [(string) $existingAuthor->id],
            'narrators' => [(string) $existingNarrator->id],
            'genres' => [(string) $existingGenre->id],
            'series_name' => 'Updated Series',
            'chapters' => [
                ['chapter_number' => 2, 'file_name' => 'new.mp3', 'format' => 'mp3', 'duration' => 180, 'size_bytes' => 2000],
            ],
        ]);

        $updated->refresh();
        $updated->load(['authors', 'narrators', 'genres', 'series', 'chapters']);

        $this->assertSame('Updated Title', $updated->title);
        $this->assertSame(['Existing Author'], $updated->authors->pluck('name')->all());
        $this->assertSame(['Existing Narrator'], $updated->narrators->pluck('name')->all());
        $this->assertSame(['Existing Genre'], $updated->genres->pluck('name')->all());
        $this->assertSame('Updated Series', $updated->series->first()?->name);
        $this->assertCount(1, $updated->chapters);
        $this->assertSame('new.mp3', $updated->chapters->first()?->file_name);
        Queue::assertPushed(EmbedBookJob::class, fn (EmbedBookJob $job) => $this->jobTargetsBook($job, $updated->id));
    }

    #[Test]
    public function updateBookReplacesExistingMultiAuthorRelationsWithoutDuplicatePivotRows(): void
    {
        $book = Book::factory()->create();
        $firstAuthor = Author::query()->create(['name' => 'First Author']);
        $secondAuthor = Author::query()->create(['name' => 'Second Author']);
        $removedAuthor = Author::query()->create(['name' => 'Removed Author']);
        $book->authors()->attach([$firstAuthor->id, $secondAuthor->id, $removedAuthor->id]);

        (new BookMutationService())->updateBook((string) $book->id, [
            'authors' => [(string) $firstAuthor->id, (string) $secondAuthor->id],
        ]);

        $book->refresh();
        $book->load('authors');

        $this->assertSame(['First Author', 'Second Author'], $book->authors->pluck('name')->sort()->values()->all());
    }

    private function jobTargetsBook(EmbedBookJob $job, int $bookId): bool
    {
        $reflection = new \ReflectionProperty($job, 'bookId');
        $reflection->setAccessible(true);
        return $reflection->getValue($job) === $bookId;
    }
}
