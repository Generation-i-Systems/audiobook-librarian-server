<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Author;
use App\Models\Book;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorControllerPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_authenticated_user_can_view_authors_index(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->get(route('authors.index'));

        $response->assertOk();
    }

    public function test_unverified_user_cannot_view_create_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'unverified']));

        $response = $this->get(route('authors.create'));

        $response->assertStatus(403);
    }

    public function test_unverified_user_cannot_create_an_author(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'unverified']));

        $response = $this->post(route('authors.store'), ['name' => 'New Author']);

        $response->assertStatus(403);
    }

    public function test_unverified_user_cannot_delete_an_author(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'unverified']));
        $author = Author::factory()->create();

        $response = $this->delete(route('authors.destroy', $author->id));

        $response->assertStatus(403);
    }

    public function test_author_with_a_linked_book_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $author = Author::factory()->create();
        $book = Book::factory()->create([
            'directory_exists' => false,
            'needs_review' => true,
        ]);
        $book->authors()->attach($author);

        $response = $this->delete(route('authors.destroy', $author->id));

        $response->assertRedirect(route('authors.index'));
        $response->assertSessionHasErrors('author');
        $this->assertDatabaseHas('authors', [
            'id' => $author->id,
            'deleted_at' => null,
        ]);
    }

    public function test_author_edit_lists_linked_books_even_when_their_directory_is_missing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $authorId = 410;
        $bookTitle = 'Missing-directory linked book';
        $documentStore = \Mockery::mock(DocumentStoreServiceInterface::class);
        $documentStore->shouldReceive('getAuthor')->with((string) $authorId)->andReturn([
            'id' => $authorId,
            'name' => 'Linked Author',
        ]);
        $documentStore->shouldReceive('listBooks')
            ->with(1, 25, ['author_id' => (string) $authorId], true, 'title', 'asc', true)
            ->andReturn([
                'data' => [['id' => 3456, 'title' => $bookTitle]],
                'total' => 1,
            ]);
        $this->app->instance(DocumentStoreServiceInterface::class, $documentStore);

        $response = $this->get(route('authors.edit', $authorId));

        $response->assertOk();
        $response->assertSee($bookTitle);
        $response->assertSee('1 total');
    }

    public function test_user_with_manage_authors_permission_can_create_an_author(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $user->permissions()->attach(Permission::where('key', PermissionKey::MANAGE_AUTHORS->value)->firstOrFail());

        $this->actingAs($user);
        $response = $this->post(route('authors.store'), ['name' => 'New Author']);

        $response->assertRedirect(route('authors.index'));
        $this->assertDatabaseHas('authors', ['name' => 'New Author']);
    }

    public function test_admin_can_create_an_author(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->post(route('authors.store'), ['name' => 'Admin Created Author']);

        $response->assertRedirect(route('authors.index'));
        $this->assertDatabaseHas('authors', ['name' => 'Admin Created Author']);
    }

    public function test_old_admin_url_redirects_to_new_url(): void
    {
        $author = Author::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->get('/admin/authors/' . $author->id . '/edit');

        $response->assertRedirect('/authors/' . $author->id . '/edit');
    }
}
