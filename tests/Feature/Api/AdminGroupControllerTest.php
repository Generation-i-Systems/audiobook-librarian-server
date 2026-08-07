<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGroupControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_create_group_requires_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $response = $this->postJson('/api/v1/admin/groups', ['name' => 'Book Club']);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_group(): void
    {
        $this->actingAs($this->admin());

        $response = $this->postJson('/api/v1/admin/groups', ['name' => 'Book Club']);

        $response->assertStatus(201);
        $response->assertJson(['name' => 'Book Club', 'memberCount' => 0]);
        $this->assertDatabaseHas('groups', ['name' => 'Book Club']);
    }

    public function test_admin_can_add_and_remove_member(): void
    {
        $this->actingAs($this->admin());
        $group = Group::query()->create(['name' => 'Book Club']);
        $member = User::factory()->create();

        $addResponse = $this->postJson("/api/v1/admin/groups/{$group->id}/members", ['user_id' => $member->id]);
        $addResponse->assertStatus(200);
        $addResponse->assertJsonCount(1, 'members');
        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $member->id]);

        $removeResponse = $this->deleteJson("/api/v1/admin/groups/{$group->id}/members/{$member->id}");
        $removeResponse->assertStatus(200);
        $removeResponse->assertJsonCount(0, 'members');
        $this->assertDatabaseMissing('group_members', ['group_id' => $group->id, 'user_id' => $member->id]);
    }

    public function test_user_endpoint_returns_groups(): void
    {
        $user = User::factory()->create(['role' => 'hybrid-user']);
        $group = Group::query()->create(['name' => 'Book Club']);
        $group->members()->attach($user->id);

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/user');

        $response->assertStatus(200);
        $response->assertJsonPath('groups.0.name', 'Book Club');
    }
}
