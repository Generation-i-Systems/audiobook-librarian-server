<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Genre;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenreControllerPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_authenticated_user_can_view_genres_index(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->get(route('genres.index'));

        $response->assertOk();
    }

    public function test_non_admin_without_permission_cannot_view_create_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->get(route('genres.create'));

        $response->assertStatus(403);
    }

    public function test_non_admin_without_permission_cannot_create_a_genre(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->post(route('genres.store'), ['name' => 'New Genre']);

        $response->assertStatus(403);
    }

    public function test_non_admin_without_permission_cannot_delete_a_genre(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));
        $genre = Genre::factory()->create();

        $response = $this->delete(route('genres.destroy', $genre->id));

        $response->assertStatus(403);
    }

    public function test_user_with_manage_genres_permission_can_create_a_genre(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $user->permissions()->attach(Permission::where('key', PermissionKey::MANAGE_GENRES->value)->firstOrFail());

        $this->actingAs($user);
        $response = $this->post(route('genres.store'), ['name' => 'New Genre']);

        $response->assertRedirect(route('genres.index'));
        $this->assertDatabaseHas('genres', ['name' => 'New Genre']);
    }

    public function test_admin_can_create_a_genre(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->post(route('genres.store'), ['name' => 'Admin Created Genre']);

        $response->assertRedirect(route('genres.index'));
        $this->assertDatabaseHas('genres', ['name' => 'Admin Created Genre']);
    }

    public function test_old_admin_url_redirects_to_new_url(): void
    {
        $genre = Genre::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->get('/admin/genres/' . $genre->id . '/edit');

        $response->assertRedirect('/genres/' . $genre->id . '/edit');
    }
}
