<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookTag;
use App\Models\UserTagFilter;

class UserTagFilterAppliesToBookListingTest extends ApiTestCase
{
    public function testBannedTagExcludesBooksFromBookListing(): void
    {
        UserTagFilter::create(['user_id' => $this->user->id, 'tag' => 'spoilers', 'mode' => 'ban']);

        $banned = Book::factory()->create(['directory_exists' => true, 'title' => 'Banned Book']);
        BookTag::create(['book_id' => $banned->id, 'scope' => 'system', 'owner_key' => 'system', 'tags' => ['spoilers']]);

        $allowed = Book::factory()->create(['directory_exists' => true, 'title' => 'Allowed Book']);

        $response = $this->getJson('/api/v1/books');

        $response->assertOk();
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('Allowed Book', $titles);
        $this->assertNotContains('Banned Book', $titles);
    }

    public function testRequiredTagOnlyIncludesMatchingBooks(): void
    {
        UserTagFilter::create(['user_id' => $this->user->id, 'tag' => 'cozy', 'mode' => 'require']);

        $matching = Book::factory()->create(['directory_exists' => true, 'title' => 'Cozy Book']);
        BookTag::create(['book_id' => $matching->id, 'scope' => 'system', 'owner_key' => 'system', 'tags' => ['cozy']]);

        $nonMatching = Book::factory()->create(['directory_exists' => true, 'title' => 'Other Book']);

        $response = $this->getJson('/api/v1/books');

        $response->assertOk();
        $titles = array_column($response->json('data'), 'title');
        $this->assertContains('Cozy Book', $titles);
        $this->assertNotContains('Other Book', $titles);
    }
}
