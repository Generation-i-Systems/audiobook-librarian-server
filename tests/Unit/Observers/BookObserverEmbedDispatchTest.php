<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Jobs\EmbedBookJob;
use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BookObserverEmbedDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function testCreatingABookDispatchesEmbedBookJob(): void
    {
        Queue::fake();

        $book = Book::factory()->create();

        Queue::assertPushed(EmbedBookJob::class, fn (EmbedBookJob $job) => $this->jobTargetsBook($job, $book->id));
    }

    public function testUpdatingTitleDispatchesEmbedBookJob(): void
    {
        $bookId = Book::factory()->create(['title' => 'Original Title'])->id;
        Queue::fake();

        // Re-fetch: a freshly-created instance's wasRecentlyCreated stays true for
        // its lifetime, which would dispatch regardless of what changed next.
        Book::find($bookId)->update(['title' => 'New Title']);

        Queue::assertPushed(EmbedBookJob::class, fn (EmbedBookJob $job) => $this->jobTargetsBook($job, $bookId));
    }

    public function testUpdatingUnrelatedFieldDoesNotDispatchEmbedBookJob(): void
    {
        $bookId = Book::factory()->create(['audio_file_count' => 5])->id;
        Queue::fake();

        Book::find($bookId)->update(['audio_file_count' => 6]);

        Queue::assertNotPushed(EmbedBookJob::class);
    }

    private function jobTargetsBook(EmbedBookJob $job, int $bookId): bool
    {
        $reflection = new \ReflectionProperty($job, 'bookId');
        $reflection->setAccessible(true);
        return $reflection->getValue($job) === $bookId;
    }
}
