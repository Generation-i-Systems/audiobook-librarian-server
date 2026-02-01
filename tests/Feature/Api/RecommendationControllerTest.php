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
        /** @var User $user */
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $this->user = $user;
        /** @var User $recipient */
        $recipient = User::factory()->create();
        $this->recipient = $recipient;
        /** @var Book $book */
        $book = Book::factory()->create();
        $this->book = $book;
        \Laravel\Sanctum\Sanctum::actingAs($this->user);
    }

    public function test_can_send_a_book_recommendation(): void
    {
        /** @var \App\Models\Book $book */
        $book = $this->book;
        /** @var \App\Models\User $recipient */
        $recipient = $this->recipient;

        $response = $this->postJson('/api/v1/recommendations/' . $book->id, [
            'recipient_id' => $recipient->id,
            'message' => 'You should really listen to this!',
        ])->assertStatus(201);

        $response->assertJson(['message' => 'Recommendation sent successfully.']);

        $this->assertDatabaseHas('user_recommendations', [
            'sender_id' => $this->user->id,
            'recipient_id' => $recipient->id,
            'book_id' => $book->id,
            'message' => 'You should really listen to this!',
        ]);
    }

    public function test_cannot_recommend_a_book_to_self(): void
    {
        /** @var \App\Models\Book $book */
        $book = $this->book;

        $this->postJson('/api/v1/recommendations/' . $book->id, [
            'recipient_id' => $this->user->id,
            'message' => 'Check this out!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('recipient_id');
    }

    public function test_cannot_send_duplicate_unacknowledged_recommendation(): void
    {
        /** @var \App\Models\Book $book */
        $book = $this->book;
        /** @var \App\Models\User $recipient */
        $recipient = $this->recipient;

        UserRecommendation::factory()->create([
            'sender_id' => $this->user->id,
            'recipient_id' => $recipient->id,
            'book_id' => $book->id,
            'acknowledged_at' => null,
        ]);

        $this->postJson('/api/v1/recommendations/' . $book->id, [
            'recipient_id' => $recipient->id,
        ])->assertStatus(409)
            ->assertJson(['message' => 'You have already sent this user an unacknowledged recommendation for this book.']);
    }

    public function test_can_send_recommendation_if_previous_one_was_acknowledged(): void
    {
        /** @var \App\Models\Book $book */
        $book = $this->book;
        /** @var \App\Models\User $recipient */
        $recipient = $this->recipient;

        UserRecommendation::factory()->create([
            'sender_id' => $this->user->id,
            'recipient_id' => $recipient->id,
            'book_id' => $book->id,
            'acknowledged_at' => now(),
        ]);

        $this->postJson('/api/v1/recommendations/' . $book->id, [
            'recipient_id' => $recipient->id,
        ])->assertStatus(201);
    }

    public function test_can_view_unacknowledged_recommendations_in_inbox(): void
    {
        /** @var \App\Models\Book $book2 */
        $book2 = Book::factory()->create();

        /** @var \App\Models\Book $book */
        $book = $this->book;
        /** @var \App\Models\User $recipient */
        $recipient = $this->recipient;

        // Recommendation 1 (unacknowledged, should appear)
        UserRecommendation::factory()->create([
            'sender_id' => $recipient->id, // Sent from recipient to user
            'recipient_id' => $this->user->id,
            'book_id' => $book->id,
            'acknowledged_at' => null,
            'created_at' => now()->subDay(),
        ]);

        // Recommendation 2 (acknowledged, should not appear)
        UserRecommendation::factory()->create([
            'sender_id' => $recipient->id,
            'recipient_id' => $this->user->id,
            'book_id' => $book2->id,
            'acknowledged_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/recommendations/inbox')
            ->assertStatus(200);

        $response->assertJsonCount(1);
        $response->assertJsonPath('0.bookId', $book->id);
        $response->assertJsonPath('0.sender.id', $recipient->id);
    }

    public function test_can_acknowledge_a_recommendation(): void
    {
        /** @var \App\Models\Book $book */
        $book = $this->book;
        /** @var \App\Models\User $recipient */
        $recipient = $this->recipient;

        /** @var UserRecommendation $recommendation */
        $recommendation = UserRecommendation::factory()->create([
            'sender_id' => $recipient->id,
            'recipient_id' => $this->user->id,
            'book_id' => $book->id,
            'acknowledged_at' => null,
        ]);

        $response = $this->postJson('/api/v1/recommendations/' . $recommendation->id . '/acknowledge')
            ->assertStatus(200);

        $response->assertJson(['message' => 'Recommendation acknowledged.']);

        $this->assertNotNull($recommendation->fresh()->acknowledged_at);
    }

    public function test_cannot_acknowledge_recommendation_not_addressed_to_user(): void
    {
        /** @var \App\Models\Book $book */
        $book = $this->book;
        /** @var \App\Models\User $recipient */
        $recipient = $this->recipient;

        /** @var \App\Models\User $otherUser */
        $otherUser = User::factory()->create();
        /** @var UserRecommendation $recommendation */
        $recommendation = UserRecommendation::factory()->create([
            'sender_id' => $recipient->id,
            'recipient_id' => $otherUser->id,
            'book_id' => $book->id,
            'acknowledged_at' => null,
        ]);

        $this->postJson('/api/v1/recommendations/' . $recommendation->id . '/acknowledge')
            ->assertStatus(403);

        $this->assertNull($recommendation->fresh()->acknowledged_at);
    }
}
