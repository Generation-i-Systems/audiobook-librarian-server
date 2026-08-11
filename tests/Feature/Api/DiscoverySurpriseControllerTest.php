<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use App\Models\User;
use App\Models\UserBookStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiscoverySurpriseControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now(), 'role' => 'library-user']);
        Sanctum::actingAs($this->user);
    }

    public function testReturnsNullWhenNoUnreadBooksExist(): void
    {
        $response = $this->getJson('/api/v1/discovery/surprise');

        $response->assertOk()->assertJson(['data' => null]);
    }

    public function testPrefersFirstInSeriesBookInAFavoredGenre(): void
    {
        $favoredGenre = Genre::factory()->create(['name' => 'Fantasy']);
        $otherGenre = Genre::factory()->create(['name' => 'Romance']);

        $completedBook = Book::factory()->create();
        $completedBook->genres()->attach($favoredGenre->id);
        UserBookStatus::factory()->create(['user_id' => $this->user->id, 'book_id' => $completedBook->id, 'status' => 'completed', 'finished_at' => now()]);

        $series = Series::factory()->create();
        $seriesBook1 = Book::factory()->create();
        $seriesBook1->genres()->attach($favoredGenre->id);
        $series->books()->attach($seriesBook1->id, ['series_number' => '1']);

        $seriesBook2 = Book::factory()->create();
        $seriesBook2->genres()->attach($favoredGenre->id);
        $series->books()->attach($seriesBook2->id, ['series_number' => '2']);

        $wrongGenreBook = Book::factory()->create();
        $wrongGenreBook->genres()->attach($otherGenre->id);

        $response = $this->getJson('/api/v1/discovery/surprise');

        $response->assertOk();
        $this->assertSame($seriesBook1->id, $response->json('data.id'));
    }

    public function testFallsBackToAnyFirstInSeriesBookWhenNoFavoredGenreMatch(): void
    {
        $favoredGenre = Genre::factory()->create();
        $completedBook = Book::factory()->create();
        $completedBook->genres()->attach($favoredGenre->id);
        UserBookStatus::factory()->create(['user_id' => $this->user->id, 'book_id' => $completedBook->id, 'status' => 'completed', 'finished_at' => now()]);

        $otherGenre = Genre::factory()->create();
        $standaloneBook = Book::factory()->create();
        $standaloneBook->genres()->attach($otherGenre->id);

        $response = $this->getJson('/api/v1/discovery/surprise');

        $response->assertOk();
        $this->assertSame($standaloneBook->id, $response->json('data.id'));
    }

    public function testExcludesBooksTheUserHasAlreadyEngagedWith(): void
    {
        $engagedBook = Book::factory()->create();
        UserBookStatus::factory()->create(['user_id' => $this->user->id, 'book_id' => $engagedBook->id, 'status' => 'queue']);

        $unreadBook = Book::factory()->create();

        $response = $this->getJson('/api/v1/discovery/surprise');

        $response->assertOk();
        $this->assertSame($unreadBook->id, $response->json('data.id'));
    }
}
