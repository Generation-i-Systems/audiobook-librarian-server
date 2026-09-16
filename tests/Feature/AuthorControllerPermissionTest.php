<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Author;
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

    public function test_non_admin_without_permission_cannot_view_create_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->get(route('authors.create'));

        $response->assertStatus(403);
    }

    public function test_non_admin_without_permission_cannot_create_an_author(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->post(route('authors.store'), ['name' => 'New Author']);

        $response->assertStatus(403);
    }

    public function test_non_admin_without_permission_cannot_delete_an_author(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));
        $author = Author::factory()->create();

        $response = $this->delete(route('authors.destroy', $author->id));

        $response->assertStatus(403);
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
