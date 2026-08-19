<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\BookTag;
use App\Models\User;
use App\Services\MySqlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MySqlServiceRelevanceSortTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function bookIdsFilterReturnsOnlyMatchingBooksInAnyOrderByDefault(): void
    {
        $a = Book::factory()->create(['title' => 'Alpha']);
        $b = Book::factory()->create(['title' => 'Beta']);
        Book::factory()->create(['title' => 'Gamma']);

        $service = app(MySqlService::class);

        $result = $service->listBooks(1, 20, ['book_ids' => [$a->id, $b->id]]);

        $ids = array_map('intval', array_column($result['data'], 'id'));
        sort($ids);
        $expected = [$a->id, $b->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    #[Test]
    public function relevanceSortPreservesBookIdsOrder(): void
    {
        $first = Book::factory()->create(['title' => 'Zed']);
        $second = Book::factory()->create(['title' => 'Alpha']);
        $third = Book::factory()->create(['title' => 'Mid']);
        Book::factory()->create(['title' => 'AAA Distractor Not In Candidate List']);

        $service = app(MySqlService::class);

        $result = $service->listBooks(
            1,
            20,
            ['book_ids' => [$second->id, $third->id, $first->id]],
            true,
            'relevance'
        );

        $ids = array_map('intval', array_column($result['data'], 'id'));

        $this->assertSame([$second->id, $third->id, $first->id], $ids);
    }

    #[Test]
    public function relevanceSortFallsBackToTitleWhenNoBookIdsGiven(): void
    {
        Book::factory()->create(['title' => 'Zed']);
        Book::factory()->create(['title' => 'Alpha']);

        $service = app(MySqlService::class);

        $result = $service->listBooks(1, 20, [], true, 'relevance');

        $titles = array_column($result['data'], 'title');

        $this->assertSame(['Alpha', 'Zed'], $titles);
    }

    #[Test]
    public function bookIdsFilterComposesWithTagFilter(): void
    {
        $tagged = Book::factory()->create(['title' => 'Tagged Book']);
        $untagged = Book::factory()->create(['title' => 'Untagged Book']);

        BookTag::create([
            'book_id' => $tagged->id,
            'scope' => 'system',
            'owner_key' => 'system',
            'tags' => ['funny'],
        ]);

        $service = app(MySqlService::class);

        $result = $service->listBooks(1, 20, [
            'book_ids' => [$tagged->id, $untagged->id],
            'tag' => 'funny',
        ]);

        $ids = array_map('intval', array_column($result['data'], 'id'));

        $this->assertSame([$tagged->id], $ids);
    }

    #[Test]
    public function bookIdsFilterComposesWithPerUserBanRule(): void
    {
        $user = User::factory()->create();
        $banned = Book::factory()->create(['title' => 'Banned Book']);
        $allowed = Book::factory()->create(['title' => 'Allowed Book']);

        BookTag::create([
            'book_id' => $banned->id,
            'scope' => 'system',
            'owner_key' => 'system',
            'tags' => ['spicy'],
        ]);

        \App\Models\UserTagFilter::create([
            'user_id' => $user->id,
            'tag' => 'spicy',
            'mode' => 'ban',
        ]);

        $service = app(MySqlService::class);

        $result = $service->listBooks(
            1,
            20,
            ['book_ids' => [$banned->id, $allowed->id]],
            true,
            'title',
            'asc',
            false,
            $user->id
        );

        $ids = array_map('intval', array_column($result['data'], 'id'));

        $this->assertSame([$allowed->id], $ids);
    }
}
