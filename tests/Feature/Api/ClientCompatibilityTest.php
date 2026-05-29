<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Bookmark;
use App\Models\BookProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'library-user',
        ]);
        $this->book = Book::factory()->create();

        Sanctum::actingAs($this->user, ['*']);
    }

    #[Test]
    public function it_can_delete_bookmark_by_id_only()
    {
        $bookmark = Bookmark::factory()->create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
        ]);

        $response = $this->deleteJson("/api/v1/bookmarks/{$bookmark->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('bookmarks', ['id' => $bookmark->id]);
    }

    #[Test]
    public function it_can_mark_book_completed_using_alias_route()
    {
        // Setup initial progress manually
        $progress = new BookProgress();
        $progress->user_id = $this->user->id;
        $progress->book_id = $this->book->id;
        $progress->device_id = (string) $this->user->id; // Default logic uses user ID if not provided
        $progress->completed = false;
        $progress->progress_percentage = 50;
        $progress->save();

        $response = $this->postJson("/api/v1/progress/{$this->book->id}/mark-completed");

        $response->assertStatus(200)
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.progress_percentage', '100.00');

        $this->assertDatabaseHas('book_progress', [
            'id' => $progress->id,
            'completed' => 1,
            'progress_percentage' => 100,
        ]);
    }

    #[Test]
    public function it_can_get_device_progress_using_alias_route()
    {
        $deviceId = 'test-device-123';

        // Setup progress for this device manually
        $progress = new BookProgress();
        $progress->user_id = $this->user->id;
        $progress->book_id = $this->book->id;
        $progress->device_id = $deviceId;
        $progress->completed = false;
        $progress->progress_percentage = 30;
        $progress->save();

        $response = $this->getJson("/api/v1/progress/device/{$deviceId}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.book_id', $this->book->id);
    }
}
