<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Services\MySqlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MySqlServiceQueueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function addBookToQueueCreatesQueueStatusRowWithIncrementedOrder(): void
    {
        $service = new MySqlService();

        $user = User::factory()->create();
        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();

        $this->assertTrue($service->addBookToQueue((string) $user->id, (string) $bookA->id));
        $this->assertTrue($service->addBookToQueue((string) $user->id, (string) $bookB->id));

        $rows = UserBookStatus::query()
            ->where('user_id', $user->id)
            ->where('status', 'queue')
            ->orderBy('order')
            ->get();

        $this->assertCount(2, $rows);
        $firstRow = $rows->first();
        $lastRow = $rows->last();

        $this->assertNotNull($firstRow);
        $this->assertNotNull($lastRow);

        $this->assertSame($bookA->id, $firstRow->book_id);
        $this->assertSame(0, $firstRow->order);
        $this->assertSame($bookB->id, $lastRow->book_id);
        $this->assertSame(1, $lastRow->order);
    }

    #[Test]
    public function addBookToQueueIsIdempotentForSameBook(): void
    {
        $service = new MySqlService();

        $user = User::factory()->create();
        $book = Book::factory()->create();

        $this->assertTrue($service->addBookToQueue((string) $user->id, (string) $book->id));
        $this->assertTrue($service->addBookToQueue((string) $user->id, (string) $book->id));

        $count = UserBookStatus::query()
            ->where('user_id', $user->id)
            ->where('status', 'queue')
            ->where('book_id', $book->id)
            ->count();

        $this->assertSame(1, $count);
    }

    #[Test]
    public function removeBookFromQueueDeletesQueueStatusRow(): void
    {
        $service = new MySqlService();

        $user = User::factory()->create();
        $book = Book::factory()->create();

        UserBookStatus::query()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'order' => 0,
            'status' => 'queue',
        ]);

        $this->assertTrue($service->removeBookFromQueue((string) $user->id, (string) $book->id));

        $this->assertDatabaseMissing('user_book_status', [
            'user_id' => $user->id,
            'book_id' => $book->id,
            'status' => 'queue',
        ]);
    }

    #[Test]
    public function updateBookQueueUpsertsRowsAndRemovesMissingOnes(): void
    {
        $service = new MySqlService();

        $user = User::factory()->create();
        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();
        $bookC = Book::factory()->create();

        UserBookStatus::query()->create([
            'user_id' => $user->id,
            'book_id' => $bookA->id,
            'order' => 0,
            'status' => 'queue',
        ]);

        UserBookStatus::query()->create([
            'user_id' => $user->id,
            'book_id' => $bookB->id,
            'order' => 1,
            'status' => 'queue',
        ]);

        $this->assertTrue($service->updateBookQueue((string) $user->id, [(string) $bookC->id]));

        $this->assertDatabaseMissing('user_book_status', [
            'user_id' => $user->id,
            'book_id' => $bookA->id,
            'status' => 'queue',
        ]);

        $this->assertDatabaseMissing('user_book_status', [
            'user_id' => $user->id,
            'book_id' => $bookB->id,
            'status' => 'queue',
        ]);

        $this->assertDatabaseHas('user_book_status', [
            'user_id' => $user->id,
            'book_id' => $bookC->id,
            'status' => 'queue',
            'order' => 0,
        ]);
    }
}
