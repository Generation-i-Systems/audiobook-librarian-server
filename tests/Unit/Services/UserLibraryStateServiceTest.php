<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\Bookmark;
use App\Models\ExternalRead;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Services\UserLibraryStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserLibraryStateServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function queueOperationsManageRowsForValidUsersAndBooks(): void
    {
        $service = new UserLibraryStateService();
        $user = User::factory()->create();
        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();

        $this->assertTrue($service->addBookToQueue((string) $user->id, (string) $bookA->id));
        $this->assertTrue($service->addBookToQueue((string) $user->id, (string) $bookB->id));

        $this->assertSame([], $service->getBookQueue('999999'));
        $this->assertCount(2, UserBookStatus::query()->where('user_id', $user->id)->where('status', 'queue')->get());

        $this->assertTrue($service->updateBookQueue((string) $user->id, [(string) $bookB->id]));
        $this->assertCount(1, UserBookStatus::query()->where('user_id', $user->id)->where('status', 'queue')->get());

        $this->assertTrue($service->removeBookFromQueue((string) $user->id, (string) $bookB->id));
        $this->assertCount(0, UserBookStatus::query()->where('user_id', $user->id)->where('status', 'queue')->get());
    }

    #[Test]
    public function bookmarkOperationsCreateUpdateAndDeleteBookmarks(): void
    {
        $service = new UserLibraryStateService();
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $bookmarkId = $service->createBookmark([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'title' => 'Sample',
            'chapter' => '1',
            'position' => 120,
            'notes' => 'Remember this',
        ]);

        $bookmarks = $service->getBookmarks((string) $user->id, (string) $book->id);
        $this->assertCount(1, $bookmarks);
        $this->assertSame('Remember this', $bookmarks[0]['notes']);

        $bookmark = $service->getBookmark($bookmarkId, (string) $user->id, (string) $book->id);
        $this->assertSame('Sample', $bookmark['title']);

        $this->assertTrue($service->updateBookmark($bookmarkId, ['notes' => 'Updated note']));
        $this->assertSame('Updated note', Bookmark::query()->findOrFail($bookmarkId)->notes);

        $this->assertTrue($service->deleteBookmark($bookmarkId, (string) $user->id, (string) $book->id));
        $this->assertSoftDeleted('bookmarks', ['id' => $bookmarkId]);

        $secondBookmarkId = $service->createBookmark([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'title' => 'Second',
            'chapter' => '2',
            'position' => 240,
        ]);

        $this->assertTrue($service->deleteBookmarkById($secondBookmarkId, (string) $user->id));
        $this->assertDatabaseMissing('bookmarks', ['id' => $secondBookmarkId]);
    }

    #[Test]
    public function externalReadOperationsCreateUpdateAndDeleteEntries(): void
    {
        $service = new UserLibraryStateService();
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $externalReadId = $service->createExternalRead([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'origin' => 'external',
            'source' => 'Libby',
            'note' => 'Borrowed copy',
        ]);

        $reads = $service->getExternalReads((string) $user->id, (string) $book->id);
        $this->assertCount(1, $reads);
        $this->assertSame('Libby', $reads[0]['source']);

        $entry = $service->getExternalRead($externalReadId, (string) $user->id, (string) $book->id);
        $this->assertSame('Borrowed copy', $entry['note']);

        $this->assertTrue($service->updateExternalRead($externalReadId, ['note' => 'Finished elsewhere']));
        $this->assertSame('Finished elsewhere', ExternalRead::query()->findOrFail($externalReadId)->note);

        $this->assertTrue($service->deleteExternalRead($externalReadId, (string) $user->id, (string) $book->id));
        $this->assertDatabaseMissing('external_reads', ['id' => $externalReadId]);
    }
}
