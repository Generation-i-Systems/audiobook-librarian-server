<?php

namespace Tests\Api\Feature;

use App\Models\Book;
use App\Models\BookProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class ProgressControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user without running the problematic seeders
        $this->user = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        // Create a Sanctum token for API authentication
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        // Use Sanctum::actingAs for API tests
        Sanctum::actingAs($this->user);
    }

    public function test_get_progress_returns_empty_for_new_book()
    {
        $book = Book::factory()->create();

        // Use Bearer token for API authentication
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Acting-As-Test' => '1',
        ])
            ->getJson("/api/v1/books/{$book->id}/progress?device_id=test-device");

        $response->assertStatus(200)
            ->assertJson([
                'book_id' => $book->id,
                'device_id' => 'test-device',
                'current_position_seconds' => 0,
                'progress_percentage' => 0.00,
                'completed' => false,
            ]);
    }

    public function test_update_progress_creates_new_record()
    {
        $book = Book::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Acting-As-Test' => '1',
        ])
            ->putJson("/api/v1/books/{$book->id}/progress", [
                'device_id' => 'test-device',
                'current_position_seconds' => 1800, // 30 minutes
                'total_duration_seconds' => 7200,   // 2 hours
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Progress updated successfully',
                'data' => [
                    'book_id' => $book->id,
                    'device_id' => 'test-device',
                    'current_position_seconds' => 1800,
                    'total_duration_seconds' => 7200,
                    'progress_percentage' => 25.00,
                    'completed' => false,
                ],
            ]);

        $this->assertDatabaseHas('book_progress', [
            'book_id' => $book->id,
            'device_id' => 'test-device',
            'current_position_seconds' => 1800,
            'progress_percentage' => 25.00,
        ]);
    }

    public function test_update_progress_marks_completed_at_95_percent()
    {
        $book = Book::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Acting-As-Test' => '1',
        ])
            ->putJson("/api/v1/books/{$book->id}/progress", [
                'device_id' => 'test-device',
                'current_position_seconds' => 6840, // 95% of 2 hours
                'total_duration_seconds' => 7200,
            ]);

        $response->assertStatus(200);

        $progress = BookProgress::where('book_id', $book->id)->first();
        $this->assertTrue($progress->completed);
        $this->assertNotNull($progress->completed_at);
    }

    public function test_mark_completed_sets_book_as_finished()
    {
        $book = Book::factory()->create();

        $progress = BookProgress::create([
            'book_id' => $book->id,
            'user_id' => $this->user->id,
            'device_id' => 'test-device',
            'current_position_seconds' => 3600,
            'total_duration_seconds' => 7200,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Acting-As-Test' => '1',
        ])
            ->postJson("/api/v1/books/{$book->id}/progress/complete", [
                'device_id' => 'test-device',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Book marked as completed',
                'data' => [
                    'completed' => true,
                    'progress_percentage' => 100.00,
                ],
            ]);

        $progress->refresh();
        $this->assertTrue($progress->completed);
        $this->assertEquals(100.00, $progress->progress_percentage);
        $this->assertNotNull($progress->completed_at);
    }

    public function test_get_device_progress_returns_recent_books()
    {
        $book1 = Book::factory()->create();
        $book2 = Book::factory()->create();
        $book3 = Book::factory()->create();

        // Book 1 - Listened recently
        BookProgress::create([
            'book_id' => $book1->id,
            'user_id' => $this->user->id,
            'device_id' => 'test-device',
            'current_position_seconds' => 1800,
            'last_listened_at' => now(),
        ]);

        // Book 2 - Listened yesterday
        BookProgress::create([
            'book_id' => $book2->id,
            'user_id' => $this->user->id,
            'device_id' => 'test-device',
            'current_position_seconds' => 3600,
            'last_listened_at' => now()->subDay(),
        ]);

        // Book 3 - Different device
        BookProgress::create([
            'book_id' => $book3->id,
            'user_id' => $this->user->id,
            'device_id' => 'other-device',
            'current_position_seconds' => 7200,
            'last_listened_at' => now()->subDays(2),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson("/api/v1/progress/device?device_id=test-device");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'count' => 3,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'book_id',
                        'book' => [
                            'id',
                            'title',
                            'cover_image',
                        ],
                        'current_position_seconds',
                        'progress_percentage',
                        'formatted_progress',
                    ],
                ],
                'count',
            ]);
    }

    public function test_reset_progress_deletes_record()
    {
        $book = Book::factory()->create();

        $progress = BookProgress::create([
            'book_id' => $book->id,
            'user_id' => $this->user->id,
            'device_id' => 'test-device',
            'current_position_seconds' => 3600,
            'last_listened_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Acting-As-Test' => '1',
        ])
            ->deleteJson("/api/v1/books/{$book->id}/progress", [
                'device_id' => 'test-device',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Progress reset successfully',
            ]);

        $this->assertDatabaseMissing('book_progress', [
            'id' => $progress->id,
        ]);
    }

    public function test_validation_errors_for_invalid_input()
    {
        $book = Book::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Acting-As-Test' => '1',
        ])
            ->putJson("/api/v1/books/{$book->id}/progress", [
                'device_id' => '', // Required field empty
                'current_position_seconds' => -1, // Invalid negative value
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'error',
                'message',
                'errors' => [
                    'device_id',
                    'current_position_seconds',
                ],
            ]);
    }
}
