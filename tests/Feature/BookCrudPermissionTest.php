<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use App\Enums\PermissionKey;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BookCrudPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_without_permission_cannot_view_create_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->get(route('admin.books.create'));

        $response->assertStatus(403);
    }

    public function test_non_admin_without_permission_cannot_view_the_top_level_create_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->get(route('books.create'));

        $response->assertStatus(403);
    }

    public function test_non_admin_without_permission_cannot_update_a_book(): void
    {
        $mock = Mockery::mock(DocumentStoreServiceInterface::class);
        $mock->shouldReceive('getBook')->with('some-book-id')->andReturn(['id' => 'some-book-id']);
        $this->app->instance(DocumentStoreServiceInterface::class, $mock);

        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->put(route('admin.books.update', 'some-book-id'), ['title' => 'New Title']);

        $response->assertStatus(403);
    }

    public function test_non_admin_without_permission_cannot_use_book_form_autocomplete(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->getJson(route('admin.books.autocomplete.authors', ['q' => 'test']));

        $response->assertStatus(403);
    }

    public function test_user_with_manage_books_permission_can_view_create_form(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $user->permissions()->attach(Permission::where('key', PermissionKey::MANAGE_BOOKS->value)->firstOrFail());

        $this->actingAs($user);
        $response = $this->get(route('books.create'));

        $response->assertOk();
    }
}
