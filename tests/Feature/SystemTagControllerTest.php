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
}
