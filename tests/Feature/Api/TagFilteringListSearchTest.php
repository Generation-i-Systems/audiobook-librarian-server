<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookTag;
use App\Models\Genre;
use App\Models\Series;
use App\Services\UserTagFilterService;

class TagFilteringListSearchTest extends ApiTestCase
{
    public function test_parse_tag_specs_correctly_separates_required_and_banned_tags(): void
    {
        $parsed = UserTagFilterService::parseTagSpecs('fantasy, -sci-fi, litrpg');
        $this->assertEquals(['fantasy', 'litrpg'], $parsed['required']);
        $this->assertEquals(['sci-fi'], $parsed['banned']);

        $parsedArray = UserTagFilterService::parseTagSpecs(['adventure', '-romance']);
        $this->assertEquals(['adventure'], $parsedArray['required']);
        $this->assertEquals(['romance'], $parsedArray['banned']);
    }

    public function test_books_list_and_search_supports_require_and_ban_tag_filters(): void
    {
        $book1 = Book::factory()->create(['title' => 'Fantasy Quest', 'directory_exists' => true, 'needs_review' => false]);
        $book2 = Book::factory()->create(['title' => 'Sci-Fi Dark', 'directory_exists' => true, 'needs_review' => false]);
        $book3 = Book::factory()->create(['title' => 'Fantasy Horror', 'directory_exists' => true, 'needs_review' => false]);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $book1->id,
            'scope' => 'user',
            'owner_key' => 'user:' . $this->user->id,
            'tags' => ['fantasy', 'adventure'],
        ]);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $book2->id,
            'scope' => 'user',
            'owner_key' => 'user:' . $this->user->id,
            'tags' => ['sci-fi'],
        ]);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $book3->id,
            'scope' => 'user',
            'owner_key' => 'user:' . $this->user->id,
            'tags' => ['fantasy', 'horror'],
        ]);

        // Require 'fantasy'
        $response = $this->getJson('/api/v1/books?tag=fantasy');
        $response->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($book1->id, $ids);
        $this->assertContains($book3->id, $ids);
        $this->assertNotContains($book2->id, $ids);

        // Require 'fantasy', Ban 'horror'
        $responseFilter = $this->getJson('/api/v1/books?tag=fantasy,-horror');
        $responseFilter->assertStatus(200);
        $filteredIds = array_column($responseFilter->json('data'), 'id');
        $this->assertContains($book1->id, $filteredIds);
        $this->assertNotContains($book2->id, $filteredIds);
        $this->assertNotContains($book3->id, $filteredIds);

        // Ban 'sci-fi'
        $responseBan = $this->getJson('/api/v1/books/search?tag=-sci-fi');
        $responseBan->assertStatus(200);
        $searchIds = array_column($responseBan->json('data'), 'id');
        $this->assertContains($book1->id, $searchIds);
        $this->assertContains($book3->id, $searchIds);
        $this->assertNotContains($book2->id, $searchIds);
    }

    public function test_series_list_supports_require_and_ban_tag_filters(): void
    {
        $this->withoutExceptionHandling();
        $series1 = Series::factory()->create(['name' => 'Fantasy Saga']);
        $series2 = Series::factory()->create(['name' => 'Horror Chronicles']);

        $book1 = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);
        $book2 = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        $series1->books()->attach($book1->id);
        $series2->books()->attach($book2->id);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $book1->id,
            'scope' => 'user',
            'owner_key' => 'user:' . $this->user->id,
            'tags' => ['magic'],
        ]);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $book2->id,
            'scope' => 'user',
            'owner_key' => 'user:' . $this->user->id,
            'tags' => ['scary'],
        ]);

        // Require 'magic'
        $response = $this->getJson('/api/v1/series?tag=magic');
        if ($response->status() !== 200) {
            dump($response->json() ?? $response->getContent());
        }
        $response->assertStatus(200);
        $seriesIds = array_column($response->json('series'), 'id');
        $this->assertContains($series1->id, $seriesIds);
        $this->assertNotContains($series2->id, $seriesIds);

        // Ban 'scary'
        $responseBan = $this->getJson('/api/v1/series?tag=-scary');
        $responseBan->assertStatus(200);
        $seriesBanIds = array_column($responseBan->json('series'), 'id');
        $this->assertContains($series1->id, $seriesBanIds);
        $this->assertNotContains($series2->id, $seriesBanIds);
    }

    public function test_authors_list_supports_require_and_ban_tag_filters(): void
    {
        $author1 = Author::factory()->create(['name' => 'Author One']);
        $author2 = Author::factory()->create(['name' => 'Author Two']);

        $book1 = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);
        $book2 = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        $author1->books()->attach($book1->id);
        $author2->books()->attach($book2->id);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $book1->id,
            'scope' => 'user',
            'owner_key' => 'user:' . $this->user->id,
            'tags' => ['epic'],
        ]);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $book2->id,
            'scope' => 'user',
            'owner_key' => 'user:' . $this->user->id,
            'tags' => ['dark'],
        ]);

        // Require 'epic'
        $response = $this->getJson('/api/v1/authors?tag=epic');
        $response->assertStatus(200);
        $authorIds = array_column($response->json('authors'), 'id');
        $this->assertContains($author1->id, $authorIds);
        $this->assertNotContains($author2->id, $authorIds);

        // Ban 'dark'
        $responseBan = $this->getJson('/api/v1/authors?tag=-dark');
        $responseBan->assertStatus(200);
        $authorBanIds = array_column($responseBan->json('authors'), 'id');
        $this->assertContains($author1->id, $authorBanIds);
        $this->assertNotContains($author2->id, $authorBanIds);
    }

    public function test_genres_list_supports_require_and_ban_tag_filters(): void
    {
        $genre1 = Genre::factory()->create(['name' => 'Genre One']);
        $genre2 = Genre::factory()->create(['name' => 'Genre Two']);

        $book1 = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);
        $book2 = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        $genre1->books()->attach($book1->id);
        $genre2->books()->attach($book2->id);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $book1->id,
            'scope' => 'user',
            'owner_key' => 'user:' . $this->user->id,
            'tags' => ['heroic'],
        ]);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $book2->id,
            'scope' => 'user',
            'owner_key' => 'user:' . $this->user->id,
            'tags' => ['gory'],
        ]);

        // Require 'heroic'
        $response = $this->getJson('/api/v1/genres?tag=heroic');
        $response->assertStatus(200);
        $genreIds = array_map('intval', array_column($response->json(), 'id'));
        $this->assertContains($genre1->id, $genreIds);
        $this->assertNotContains($genre2->id, $genreIds);

        // Ban 'gory'
        $responseBan = $this->getJson('/api/v1/genres?tag=-gory');
        $responseBan->assertStatus(200);
        $genreBanIds = array_map('intval', array_column($responseBan->json(), 'id'));
        $this->assertContains($genre1->id, $genreBanIds);
        $this->assertNotContains($genre2->id, $genreBanIds);
    }
}
