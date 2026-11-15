<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\BookTag;
use App\Models\Group;
use App\Models\User;
use App\Services\BookTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookTagServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookTagService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookTagService();
    }

    public function testVisibleTagsForBookIdsReturnsEmptyWhenNoBookIdsGiven(): void
    {
        $this->assertSame([], $this->service->visibleTagsForBookIds(null, []));
    }

    public function testVisibleTagsForBookIdsFetchesSystemGroupAndUserTagsForMultipleBooks(): void
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Book Club', 'creator_id' => $user->id]);
        $user->groups()->attach($group);

        $book1 = Book::factory()->create();
        $book2 = Book::factory()->create();
        $book3 = Book::factory()->create();

        // System tag for book 1
        BookTag::create([
            'book_id' => $book1->id,
            'user_id' => $user->id,
            'scope' => 'system',
            'owner_key' => 'system',
            'tags' => ['fantasy', 'magic'],
        ]);

        // Group tag for book 1
        BookTag::create([
            'book_id' => $book1->id,
            'user_id' => $user->id,
            'scope' => 'group',
            'group_id' => $group->id,
            'owner_key' => "group:{$group->id}",
            'tags' => ['club-favorite'],
        ]);

        // User tag for book 2
        BookTag::create([
            'book_id' => $book2->id,
            'user_id' => $user->id,
            'scope' => 'user',
            'owner_key' => "user:{$user->id}",
            'tags' => ['to-relisten'],
        ]);

        // Other user's private tag on book 2 - should NOT be visible
        $otherUser = User::factory()->create();
        BookTag::create([
            'book_id' => $book2->id,
            'user_id' => $otherUser->id,
            'scope' => 'user',
            'owner_key' => "user:{$otherUser->id}",
            'tags' => ['secret-tag'],
        ]);

        $result = $this->service->visibleTagsForBookIds($user, [$book1->id, $book2->id, $book3->id]);

        $this->assertSame([
            $book1->id => [
                'system' => ['fantasy', 'magic'],
                'groups' => [
                    [
                        'groupId' => $group->id,
                        'groupName' => $group->name,
                        'tags' => ['club-favorite'],
                    ],
                ],
                'user' => [],
            ],
            $book2->id => [
                'system' => [],
                'groups' => [],
                'user' => ['to-relisten'],
            ],
            $book3->id => [
                'system' => [],
                'groups' => [],
                'user' => [],
            ],
        ], $result);
    }

    public function testVisibleTagsForBookIdsForGuestOnlyReturnsSystemTags(): void
    {
        $book = Book::factory()->create();

        BookTag::create([
            'book_id' => $book->id,
            'scope' => 'system',
            'owner_key' => 'system',
            'tags' => ['public-tag'],
        ]);

        $user = User::factory()->create();
        BookTag::create([
            'book_id' => $book->id,
            'user_id' => $user->id,
            'scope' => 'user',
            'owner_key' => "user:{$user->id}",
            'tags' => ['private-tag'],
        ]);

        $result = $this->service->visibleTagsForBookIds(null, [$book->id]);

        $this->assertSame([
            $book->id => [
                'system' => ['public-tag'],
                'groups' => [],
                'user' => [],
            ],
        ], $result);
    }
}
