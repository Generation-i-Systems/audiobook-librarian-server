<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Jobs\EmbedBookJob;
use App\Models\Book;
use App\Models\BookEmbedding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackfillBookEmbeddingsTest extends TestCase
{
    use RefreshDatabase;

    public function testDispatchesJobOnlyForBooksMissingAnEmbedding(): void
    {
        Queue::fake();

        $embedded = Book::factory()->create();
        BookEmbedding::create([
            'book_id' => $embedded->id,
            'content_hash' => 'existing-hash',
            'embedded_at' => now(),
        ]);
        $notEmbedded = Book::factory()->create();

        // Book::factory()->create() above also dispatches EmbedBookJob via BookObserver;
        // re-fake to discard those and isolate the command's own dispatches.
        Queue::fake();

        $this->artisan('books:backfill-embeddings')->assertSuccessful();

        Queue::assertPushed(EmbedBookJob::class, 1);
        Queue::assertPushed(function (EmbedBookJob $job) use ($notEmbedded): bool {
            return $this->jobTargetsBook($job, $notEmbedded->id) && !$this->jobIsForced($job);
        });
    }

    public function testForceFlagDispatchesForEveryBook(): void
    {
        Queue::fake();

        $embedded = Book::factory()->create();
        BookEmbedding::create([
            'book_id' => $embedded->id,
            'content_hash' => 'existing-hash',
            'embedded_at' => now(),
        ]);
        Book::factory()->create();

        // Discard the setup-phase dispatches triggered by BookObserver (see above).
        Queue::fake();

        $this->artisan('books:backfill-embeddings', ['--force' => true])->assertSuccessful();

        Queue::assertPushed(EmbedBookJob::class, 2);
        Queue::assertPushed(fn (EmbedBookJob $job) => $this->jobIsForced($job));
    }

    private function jobTargetsBook(EmbedBookJob $job, int $bookId): bool
    {
        return $this->readProtected($job, 'bookId') === $bookId;
    }

    private function jobIsForced(EmbedBookJob $job): bool
    {
        return $this->readProtected($job, 'force') === true;
    }

    private function readProtected(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        return $reflection->getValue($object);
    }
}
