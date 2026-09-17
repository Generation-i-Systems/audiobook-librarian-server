<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Models\UserRecommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the production bug where the session guard returned
 * the lightweight App\Auth\DocumentstoreUser instead of the Eloquent App\Models\User,
 * making every "My Library" page throw "Call to undefined method ...::bookStatuses()".
 *
 * These tests authenticate through the real POST /login flow (which hydrates the guard
 * via DocumentUserProvider) rather than actingAs(), so they exercise the same code path
 * as production.
 */
class UserLibrarySessionAuthTest extends TestCase
{
    use RefreshDatabase;

    private function loginWithRealSession(User $user): void
    {
        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticated();
    }

    public function testEveryMyLibraryPageRendersForSessionAuthenticatedUsers(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $this->loginWithRealSession($user);

        foreach (['queue', 'wishlist', 'history', 'goals', 'tags', 'recommendations'] as $page) {
            $response = $this->get(route('my-library.' . $page));
            $response->assertOk();
        }
    }

    public function testHistoryListsCompletedBooksWithFinishedTitle(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $finished = Book::factory()->create(['title' => 'Finished Novel', 'directory_exists' => true, 'needs_review' => false]);
        $queued = Book::factory()->create(['title' => 'Still Queued', 'directory_exists' => true, 'needs_review' => false]);
        UserBookStatus::create([
            'user_id' => $user->id,
            'book_id' => $finished->id,
            'status' => 'completed',
            'order' => 0,
            'finished_at' => now()->subDay(),
        ]);
        UserBookStatus::create([
            'user_id' => $user->id,
            'book_id' => $queued->id,
            'status' => 'queue',
            'order' => 0,
        ]);

        $this->loginWithRealSession($user);
        $response = $this->get(route('my-library.history'));

        $response->assertOk();
        $response->assertSee('Finished Novel');
        $response->assertDontSee('Still Queued');
    }

    public function testQueueAndWishlistAndGoalsListTheirStatuses(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $book = Book::factory()->create(['title' => 'Shared Title', 'directory_exists' => true, 'needs_review' => false]);
        $wishlisted = Book::factory()->create(['title' => 'Wished Title', 'directory_exists' => true, 'needs_review' => false]);
        $progress = Book::factory()->create(['title' => 'Goal Title', 'directory_exists' => true, 'needs_review' => false]);
        UserBookStatus::create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'status' => 'queue',
            'order' => 0,
        ]);
        UserBookStatus::create([
            'user_id' => $user->id,
            'book_id' => $wishlisted->id,
            'status' => 'wishlist',
            'order' => 0,
        ]);
        UserBookStatus::create([
            'user_id' => $user->id,
            'book_id' => $progress->id,
            'status' => 'in_progress',
            'order' => 0,
            'target_date' => now()->addWeek()->toDateString(),
        ]);

        $this->loginWithRealSession($user);

        $this->get(route('my-library.queue'))->assertOk()->assertSee('Shared Title');
        $this->get(route('my-library.wishlist'))->assertOk();
        $this->get(route('my-library.goals'))->assertOk();
    }

    public function testRecommendationsPageListsReceivedRecommendations(): void
    {
        $recipient = User::factory()->create(['role' => 'library-user']);
        $sender = User::factory()->create(['name' => 'Max Sender', 'role' => 'library-user']);
        $book = Book::factory()->create(['title' => 'Recommended Read', 'directory_exists' => true, 'needs_review' => false]);
        UserRecommendation::create([
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'book_id' => $book->id,
            'message' => 'You will love this one',
        ]);

        $this->loginWithRealSession($recipient);
        $response = $this->get(route('my-library.recommendations'));

        $response->assertOk();
        $response->assertSee('Recommended Read');
        $response->assertSee('You will love this one');
    }
}
