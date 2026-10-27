<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Book;
use App\Models\BookTag;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemTagControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_authenticated_user_can_view_tags_index(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->get(route('tags.index'));

        $response->assertOk();
    }

    public function test_unverified_user_cannot_rename_a_tag(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'unverified']));

        $response = $this->put(route('tags.update', 'staff-pick'), ['name' => 'editors-choice']);

        $response->assertStatus(403);
    }

    public function test_unverified_user_cannot_delete_a_tag(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'unverified']));

        $response = $this->delete(route('tags.destroy', 'staff-pick'));

        $response->assertStatus(403);
    }

    public function test_user_with_manage_tags_permission_can_rename_a_system_tag_across_all_books(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $user->permissions()->attach(Permission::where('key', PermissionKey::MANAGE_TAGS->value)->firstOrFail());
        $bookA = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);
        $bookB = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        BookTag::create(['user_id' => $user->id, 'book_id' => $bookA->id, 'scope' => 'system', 'tags' => ['staff-pick']]);
        BookTag::create(['user_id' => $user->id, 'book_id' => $bookB->id, 'scope' => 'system', 'tags' => ['staff-pick', 'award-winner']]);

        $this->actingAs($user);
        $response = $this->put(route('tags.update', 'staff-pick'), ['name' => 'editors-choice']);

        $response->assertRedirect(route('tags.index'));
        $this->assertDatabaseHas('book_tags', ['book_id' => $bookA->id, 'tags' => json_encode(['editors-choice'])]);
        $this->assertDatabaseMissing('book_tags', ['book_id' => $bookA->id, 'tags' => json_encode(['staff-pick'])]);
    }

    public function test_admin_sees_system_tags_with_usage_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $bookA = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);
        $bookB = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        BookTag::create(['user_id' => $admin->id, 'book_id' => $bookA->id, 'scope' => 'system', 'tags' => ['staff-pick']]);
        BookTag::create(['user_id' => $admin->id, 'book_id' => $bookB->id, 'scope' => 'system', 'tags' => ['staff-pick']]);

        $this->actingAs($admin);
        $response = $this->get(route('tags.index'));

        $response->assertOk();
        $response->assertSee('staff-pick');
        $response->assertSee('2');
    }

    public function test_admin_can_rename_a_system_tag_across_all_books(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $bookA = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);
        $bookB = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        BookTag::create(['user_id' => $admin->id, 'book_id' => $bookA->id, 'scope' => 'system', 'tags' => ['staff-pick']]);
        BookTag::create(['user_id' => $admin->id, 'book_id' => $bookB->id, 'scope' => 'system', 'tags' => ['staff-pick', 'award-winner']]);

        $this->actingAs($admin);
        $response = $this->put(route('tags.update', 'staff-pick'), ['name' => 'editors-choice']);

        $response->assertRedirect(route('tags.index'));
        $this->assertDatabaseHas('book_tags', ['book_id' => $bookA->id, 'tags' => json_encode(['editors-choice'])]);
        $this->assertDatabaseMissing('book_tags', ['book_id' => $bookA->id, 'tags' => json_encode(['staff-pick'])]);
    }

    public function test_admin_can_delete_a_system_tag_across_all_books(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $book = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        BookTag::create(['user_id' => $admin->id, 'book_id' => $book->id, 'scope' => 'system', 'tags' => ['staff-pick', 'award-winner']]);

        $this->actingAs($admin);
        $response = $this->delete(route('tags.destroy', 'staff-pick'));

        $response->assertRedirect(route('tags.index'));
        $this->assertDatabaseHas('book_tags', ['book_id' => $book->id, 'tags' => json_encode(['award-winner'])]);
    }
    public function testTagBrowseFiltersAndPaginatesBooks(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $book = Book::factory()->create(['title' => 'Unique Selected Title']);
        $other = Book::factory()->create(['title' => 'Other Title']);
        BookTag::create(['user_id' => $user->id, 'book_id' => $book->id, 'scope' => 'system', 'tags' => ['featured']]);
        BookTag::create(['user_id' => $user->id, 'book_id' => $other->id, 'scope' => 'system', 'tags' => ['featured']]);

        $this->actingAs($user)->get(route('tags.index', ['tag' => 'featured', 'search' => 'Unique Selected']))
            ->assertOk()->assertSee('Unique Selected Title')->assertDontSee('Other Title');
    }

    public function testBulkChangesPreserveOtherTagsAndRejectUnauthorizedUsers(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $book = Book::factory()->create();
        BookTag::create(['user_id' => $admin->id, 'book_id' => $book->id, 'scope' => 'system', 'tags' => ['Keep', 'Old']]);
        $payload = ['book_ids' => [$book->id], 'tag' => 'old', 'action' => 'remove'];
        $this->actingAs(User::factory()->create(['role' => 'unverified']))
            ->post(route('tags.bulk'), $payload)->assertForbidden();
        $this->actingAs($admin)->post(route('tags.bulk'), $payload)->assertRedirect();
        $this->assertSame(['Keep'], BookTag::where('book_id', $book->id)->firstOrFail()->tags);
        $this->post(route('tags.bulk'), ['book_ids' => [$book->id], 'tag' => 'New', 'action' => 'add'])->assertRedirect();
        $this->assertSame(['Keep', 'New'], BookTag::where('book_id', $book->id)->firstOrFail()->tags);
    }

    public function testTagBrowseLimitsResultsToFiftyAndFiltersGenres(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $genre = \App\Models\Genre::factory()->create();
        $books = Book::factory()->count(51)->create();
        foreach ($books as $book) {
            $book->genres()->attach($genre);
            BookTag::create(['user_id' => $admin->id, 'book_id' => $book->id, 'scope' => 'system', 'tags' => ['large']]);
        }
        $response = $this->actingAs($admin)->get(route('tags.index', ['tag' => 'large', 'genre' => $genre->id]));
        $response->assertOk()->assertViewHas('books', fn ($result): bool => $result->total() === 51 && $result->count() === 50);
    }
}
