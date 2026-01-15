<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Events\BookStatusUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class UserStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Book $book;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        // Factory methods are assumed to exist or Laravel's default factories are used
        $this->user = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $this->book = Book::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    public function test_unauthenticated_users_cannot_access_status_endpoints(): void
    {
        $this->postJson('/api/v1/status/' . $this->book->id . '/set', ['status' => 'queue'], ['Authorization' => ''])->assertStatus(401);
    }

    public function test_can_set_a_book_status_and_creates_a_new_record(): void
    {
        Event::fake();

        $response = $this->postJson('/api/v1/status/' . $this->book->id . '/set', [
            'status' => 'in_progress',
            'status_detail' => ['device' => 'mobile'],
        ], ['Authorization' => 'Bearer ' . $this->token])->assertStatus(200);

        $response->assertJson([
            'message' => 'Book status updated to in_progress.',
        ]);

        $this->assertDatabaseHas('user_book_status', [
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'status' => 'in_progress',
        ]);

        Event::assertDispatched(BookStatusUpdated::class, fn (BookStatusUpdated $event) => $event->status === 'in_progress' && is_null($event->previousStatus));
    }

    public function test_can_update_an_existing_book_status(): void
    {
        Event::fake();

        UserBookStatus::factory()->create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'status' => 'wishlist',
            'order' => 0, // Explicitly set order to 0 for non-queue status
        ]);

        $this->postJson('/api/v1/status/' . $this->book->id . '/set', [
            'status' => 'completed',
        ], ['Authorization' => 'Bearer ' . $this->token])->assertStatus(200);

        $this->assertDatabaseHas('user_book_status', [
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'status' => 'completed',
        ]);

        Event::assertDispatched(BookStatusUpdated::class, fn (BookStatusUpdated $event) => $event->status === 'completed' && $event->previousStatus === 'wishlist');
    }

    public function test_status_update_requires_valid_status_type(): void
    {
        $this->postJson('/api/v1/status/' . $this->book->id . '/set', [
            'status' => 'invalid_status',
        ], ['Authorization' => 'Bearer ' . $this->token])->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_can_list_books_by_status(): void
    {
        $book2 = Book::factory()->create();
        $book3 = Book::factory()->create();

        UserBookStatus::factory()->create(['user_id' => $this->user->id, 'book_id' => $this->book->id, 'status' => 'queue', 'order' => 2]);
        UserBookStatus::factory()->create(['user_id' => $this->user->id, 'book_id' => $book2->id, 'status' => 'queue', 'order' => 1]);
        UserBookStatus::factory()->create(['user_id' => $this->user->id, 'book_id' => $book3->id, 'status' => 'wishlist', 'order' => 0]);

        $response = $this->getJson('/api/v1/status/list/queue', ['Authorization' => 'Bearer ' . $this->token])->assertStatus(200);

        $response->assertJsonCount(2);
        // Assert order is correct (book2 should be first as it has order 1)
        $response->assertJsonPath('0.book_id', $book2->id);
    }

    public function test_can_reorder_queue(): void
    {
        $book2 = Book::factory()->create();

        UserBookStatus::factory()->create(['user_id' => $this->user->id, 'book_id' => $this->book->id, 'status' => 'queue', 'order' => 1]);
        UserBookStatus::factory()->create(['user_id' => $this->user->id, 'book_id' => $book2->id, 'status' => 'queue', 'order' => 2]);

        $this->postJson('/api/v1/status/queue/reorder', [
            'book_orders' => [
                ['book_id' => $this->book->id, 'order' => 2],
                ['book_id' => $book2->id, 'order' => 1],
            ],
        ], ['Authorization' => 'Bearer ' . $this->token])->assertStatus(200)
            ->assertJson(['message' => 'Queue reordered successfully.']);

        $this->assertDatabaseHas('user_book_status', [
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'status' => 'queue',
            'order' => 2,
        ]);
    }
}
