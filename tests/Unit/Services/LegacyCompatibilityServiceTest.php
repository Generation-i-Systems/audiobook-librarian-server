<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\ExternalRead;
use App\Models\Job;
use App\Models\Message;
use App\Models\User;
use App\Services\LegacyCompatibilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LegacyCompatibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function updateJobStatusAndJobExistsByDirectoryPathUsePayloadBackedStorage(): void
    {
        $service = new LegacyCompatibilityService();

        $this->assertTrue($service->updateJobStatus('import-job-1', 'directory_import', 'processing', [
            'directory' => 'incoming/books/title',
            'queued_items' => 1,
        ]));

        $this->assertTrue($service->updateJobStatus('import-job-1', 'directory_import', 'completed', [
            'processed_items' => 2,
        ]));

        $job = Job::query()->firstOrFail();

        $this->assertSame('directory_import', $job->type);
        $this->assertSame('completed', $job->status);
        $this->assertSame('import-job-1', $job->payload['job_id']);
        $this->assertSame('incoming/books/title', $job->payload['directory']);
        $this->assertSame(1, $job->payload['queued_items']);
        $this->assertSame(2, $job->payload['processed_items']);
        $this->assertTrue($service->jobExistsByDirectoryPath('incoming/books/title'));
        $this->assertFalse($service->jobExistsByDirectoryPath('missing/path'));
    }

    #[Test]
    public function followExistsChecksStoredFollowRowsAndLegacyClientMethodsStayNull(): void
    {
        $this->ensureFollowsTableExists();
        $service = new LegacyCompatibilityService();
        $user = User::factory()->create();

        $this->assertFalse($service->followExists((string) $user->id, 'author', '7'));

        DB::table('follows')->insert([
            'user_id' => $user->id,
            'followable_type' => 'author',
            'followable_id' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($service->followExists((string) $user->id, 'author', '7'));
        $this->assertNull($service->getQueueCollection('imports'));
        $this->assertNull($service->getClient());
    }

    #[Test]
    public function linkNonLibraryBooksLinksRecordsAndCreatesUserMessages(): void
    {
        $service = new LegacyCompatibilityService();
        $user = User::factory()->create();
        $book = Book::factory()->create(['title' => 'Linked Book']);
        $author = Author::query()->create(['name' => 'Link Author']);
        $book->authors()->attach($author->id);

        ExternalRead::query()->create([
            'user_id' => $user->id,
            'book_id' => null,
            'title' => 'Linked Book',
            'author' => 'Link Author',
            'origin' => 'external',
            'source' => 'Libby',
        ]);

        $linkedCount = $service->linkNonLibraryBooks();
        $externalRead = ExternalRead::query()->firstOrFail();
        $message = Message::query()->firstOrFail();

        $this->assertSame(1, $linkedCount);
        $this->assertSame($book->id, $externalRead->book_id);
        $this->assertSame($user->id, $message->recipient_id);
        $this->assertSame('book_linked', $message->type);
        $this->assertSame($book->id, $message->payload['book_id']);
    }

    private function ensureFollowsTableExists(): void
    {
        if (Schema::hasTable('follows')) {
            return;
        }

        Schema::create('follows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('followable_type');
            $table->unsignedBigInteger('followable_id');
            $table->timestamps();
        });
    }
}
