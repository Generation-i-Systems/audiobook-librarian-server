<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookTag;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLibraryControllerTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_tags_page_lists_the_users_own_tags(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $book = Book::factory()->create(['directory_exists' => true, 'needs_review' => false, 'title' => 'My Favorite Book']);

        BookTag::create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'scope' => 'user',
            'tags' => ['favorites'],
        ]);

        $this->actingAs($user);
        $response = $this->get(route('my-library.tags'));

        $response->assertOk();
        $response->assertSee('favorites');
        $response->assertSee('My Favorite Book');
    }

    public function test_group_tags_are_invisible_to_non_members(): void
    {
        $member = User::factory()->create(['role' => 'library-user']);
        $outsider = User::factory()->create(['role' => 'library-user']);
        $group = Group::query()->create(['name' => 'Book Club']);
        $group->members()->attach($member->id);
        $book = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        BookTag::create([
            'user_id' => $member->id,
            'book_id' => $book->id,
            'scope' => 'group',
            'group_id' => $group->id,
            'tags' => ['book-club-pick'],
        ]);

        $this->actingAs($outsider);
        $response = $this->get(route('my-library.tags'));

        $response->assertOk();
        $response->assertDontSee('book-club-pick');
        $response->assertDontSee('Book Club');
    }
}
