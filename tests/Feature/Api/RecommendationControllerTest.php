<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\User;
use App\Models\UserRecommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $recipient;
    protected Book $book;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $this->recipient = User::factory()->create();
        $this->book = Book::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($this->user);
    }

    public function test_can_send_a_book_recommendation(): void
    {
        $response = $this->postJson('/api/v1/recommendations/' . $this->book->id, [
            'recipient_id' => $this->recipient->id,
            'message' => 'You should really listen to this!',
        ])->assertStatus(201);

        $response->assertJson(['message' => 'Recommendation sent successfully.']);

        $this->assertDatabaseHas('user_recommendations', [
            'sender_id' => $this->user->id,
            'recipient_id' => $this->recipient->id,
            'book_id' => $this->book->id,
            'message' => 'You should really listen to this!',
        ]);
    }

    public function test_cannot_recommend_a_book_to_self(): void
    {
        $this->postJson('/api/v1/recommendations/' . $this->book->id, [
            'recipient_id' => $this->user->id,
            'message' => 'Check this out!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('recipient_id');
    }

    public function test_cannot_send_duplicate_unacknowledged_recommendation(): void
    {
        UserRecommendation::factory()->create([
            'sender_id' => $this->user->id,
            'recipient_id' => $this->recipient->id,
            'book_id' => $this->book->id,
            'acknowledged_at' => null,
        ]);

        $this->postJson('/api/v1/recommendations/' . $this->book->id, [
            'recipient_id' => $this->recipient->id,
        ])->assertStatus(409)
            ->assertJson(['message' => 'You have already sent this user an unacknowledged recommendation for this book.']);
    }

    public function test_can_send_recommendation_if_previous_one_was_acknowledged(): void
    {
        UserRecommendation::factory()->create([
            'sender_id' => $this->user->id,
            'recipient_id' => $this->recipient->id,
            'book_id' => $this->book->id,
            'acknowledged_at' => now(),
        ]);

        $this->postJson('/api/v1/recommendations/' . $this->book->id, [
            'recipient_id' => $this->recipient->id,
        ])->assertStatus(201);
    }

    public function test_can_view_unacknowledged_recommendations_in_inbox(): void
    {
        $book2 = Book::factory()->create();

        // Recommendation 1 (unacknowledged, should appear)
        UserRecommendation::factory()->create([
            'sender_id' => $this->recipient->id, // Sent from recipient to user
            'recipient_id' => $this->user->id,
            'book_id' => $this->book->id,
            'acknowledged_at' => null,
            'created_at' => now()->subDay(),
        ]);

        // Recommendation 2 (acknowledged, should not appear)
        UserRecommendation::factory()->create([
            'sender_id' => $this->recipient->id,
            'recipient_id' => $this->user->id,
            'book_id' => $book2->id,
            'acknowledged_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/recommendations/inbox')
            ->assertStatus(200);

        $response->assertJsonCount(1);
        $response->assertJsonPath('0.bookId', $this->book->id);
        $response->assertJsonPath('0.sender.id', $this->recipient->id);
    }

    public function test_can_acknowledge_a_recommendation(): void
    {
        $recommendation = UserRecommendation::factory()->create([
            'sender_id' => $this->recipient->id,
            'recipient_id' => $this->user->id,
            'book_id' => $this->book->id,
            'acknowledged_at' => null,
        ]);

        $response = $this->postJson('/api/v1/recommendations/' . $recommendation->id . '/acknowledge')
            ->assertStatus(200);

        $response->assertJson(['message' => 'Recommendation acknowledged.']);

        $this->assertNotNull($recommendation->fresh()->acknowledged_at);
    }

    public function test_cannot_acknowledge_recommendation_not_addressed_to_user(): void
    {
        $otherUser = User::factory()->create();
        $recommendation = UserRecommendation::factory()->create([
            'sender_id' => $this->recipient->id,
            'recipient_id' => $otherUser->id,
            'book_id' => $this->book->id,
            'acknowledged_at' => null,
        ]);

        $this->postJson('/api/v1/recommendations/' . $recommendation->id . '/acknowledge')
            ->assertStatus(403);

        $this->assertNull($recommendation->fresh()->acknowledged_at);
    }
}
