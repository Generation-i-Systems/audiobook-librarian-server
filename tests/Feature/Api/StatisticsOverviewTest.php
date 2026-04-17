<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Genre;
use App\Models\ListeningStatistic;

class StatisticsOverviewTest extends ApiTestCase
{
    public function test_overview_excludes_soft_deleted_favorite_genres(): void
    {
        $genre = Genre::factory()->create(['name' => 'Deleted Favorite Genre']);
        $book = Book::factory()->create();
        $book->genres()->attach($genre);

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'device_id' => 'stats-device',
            'book_id' => $book->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 2400,
            'session_type' => 'listening',
        ]);

        $genre->delete();

        $response = $this->withHeader('X-Device-ID', 'stats-device')
            ->getJson('/api/v1/statistics/overview');

        $response->assertOk();
        $this->assertNotContains('Deleted Favorite Genre', $response->json('favorite_genres'));
    }
}
