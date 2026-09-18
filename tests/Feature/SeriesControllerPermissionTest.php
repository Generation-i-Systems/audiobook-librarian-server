<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Permission;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesControllerPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_authenticated_user_can_view_series_manage_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $response = $this->get(route('series.manage'));

        $response->assertOk();
    }

    public function test_unverified_user_cannot_view_edit_form(): void
    {
        $series = Series::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'unverified']));

        $response = $this->get(route('series.edit', $series->id));

        $response->assertStatus(403);
    }

    public function test_unverified_user_cannot_update_a_series(): void
    {
        $series = Series::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'unverified']));

        $response = $this->put(route('series.update', $series->id), ['name' => 'New Name']);

        $response->assertStatus(403);
    }

    public function test_user_with_manage_series_permission_can_update_a_series(): void
    {
        $series = Series::factory()->create();
        $user = User::factory()->create(['role' => 'library-user']);
        $user->permissions()->attach(Permission::where('key', PermissionKey::MANAGE_SERIES->value)->firstOrFail());

        $this->actingAs($user);
        $response = $this->put(route('series.update', $series->id), ['name' => 'New Name']);

        $response->assertRedirect(route('series.edit', $series->id));
        $this->assertDatabaseHas('series', ['id' => $series->id, 'name' => 'New Name']);
    }

    public function test_old_admin_url_redirects_to_new_url(): void
    {
        $series = Series::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->get('/admin/series/' . $series->id . '/edit');

        $response->assertRedirect('/series/' . $series->id . '/edit');
    }
}
