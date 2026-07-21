<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Genre;
use App\Models\ListeningEvent;
use Illuminate\Support\Str;

class StatisticsOverviewTest extends ApiTestCase
{
    private function createSessionEndEvent(Book $book, int $secondsListened, string $deviceId): ListeningEvent
    {
        $timestampMs = now()->getTimestampMs();

        return ListeningEvent::create([
            'id'           => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'book_id'      => $book->id,
            'event_type'   => 'SESSION_END',
            'timestamp_ms' => $timestampMs,
            'position_ms'  => 0,
            'metadata'     => [
                'sessionDurationMs'  => $secondsListened * 1000,
                'adjustedDurationMs' => $secondsListened * 1000,
                'playbackSpeed'      => 1.0,
            ],
            'device_id'    => $deviceId,
            'timezone'     => 'UTC',
            'sync_status'  => 'SYNCED',
            'created_at'   => $timestampMs,
            'synced_at'    => $timestampMs,
        ]);
    }

    public function test_overview_excludes_soft_deleted_favorite_genres(): void
    {
        $genre = Genre::factory()->create(['name' => 'Deleted Favorite Genre']);
        $book = Book::factory()->create();
        $book->genres()->attach($genre);

        $this->createSessionEndEvent($book, 2400, 'stats-device');

        $genre->delete();

        $response = $this->withHeader('X-Device-ID', 'stats-device')
            ->getJson('/api/v1/statistics/overview');

        $response->assertOk();
        $this->assertNotContains('Deleted Favorite Genre', $response->json('favorite_genres'));
    }

    public function test_overview_returns_favorite_genres_ordered_by_time_listened(): void
    {
        $popularGenre = Genre::factory()->create(['name' => 'Popular Genre']);
        $rareGenre = Genre::factory()->create(['name' => 'Rare Genre']);

        $popularBook = Book::factory()->create();
        $popularBook->genres()->attach($popularGenre);

        $rareBook = Book::factory()->create();
        $rareBook->genres()->attach($rareGenre);

        $this->createSessionEndEvent($popularBook, 3600, 'stats-device');
        $this->createSessionEndEvent($rareBook, 60, 'stats-device');

        $response = $this->withHeader('X-Device-ID', 'stats-device')
            ->getJson('/api/v1/statistics/overview');

        $response->assertOk();
        $genres = $response->json('favorite_genres');
        $this->assertSame(['Popular Genre', 'Rare Genre'], $genres);
    }
}
