<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\DocumentstoreUser;
use App\Models\Book;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TagControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_set_their_own_tags(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $book = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        $this->actingAs($user);
        $response = $this->post(route('books.tags.update', $book->id), [
            'scope' => 'user',
            'tags' => 'sci-fi, favorites',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('book_tags', [
            'book_id' => $book->id,
            'owner_key' => 'user:' . $user->id,
        ]);
    }

    public function test_non_admin_cannot_set_system_tags(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $book = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        $this->actingAs($user);
        $response = $this->post(route('books.tags.update', $book->id), [
            'scope' => 'system',
            'tags' => 'staff-pick',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('book_tags', ['book_id' => $book->id, 'scope' => 'system']);
    }

    public function test_non_member_cannot_set_group_tags(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $group = Group::query()->create(['name' => 'Book Club']);
        $book = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);

        $this->actingAs($user);
        $response = $this->post(route('books.tags.update', $book->id), [
            'scope' => 'group',
            'group_id' => $group->id,
            'tags' => 'book-club-pick',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('book_tags', ['book_id' => $book->id, 'scope' => 'group']);
    }

    public function test_documentstore_user_can_set_system_tags_from_admin_book_edit(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $book = Book::factory()->create(['directory_exists' => true, 'needs_review' => false]);
        Auth::setUser(new DocumentstoreUser($user->toArray()));

        $response = $this->post(route('books.tags.update', $book->id), [
            'scope' => 'system',
            'tags' => 'staff-pick',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('book_tags', [
            'book_id' => $book->id,
            'owner_key' => 'system',
        ]);
    }

    public function test_tag_save_returns_json_for_an_in_place_book_edit_save(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $this->actingAs($user);

        $this->postJson(route('books.tags.update', $book->id), [
            'scope' => 'user',
            'tags' => 'reread',
        ])->assertOk()->assertJson([
            'message' => 'Tags updated successfully!',
            'scope' => 'user',
            'tags' => ['reread'],
        ]);
    }
}
