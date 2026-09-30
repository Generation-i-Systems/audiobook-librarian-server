<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\BlockedEntity;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testPeopleListsEveryoneElseExceptBlockedAndOwnGroups(): void
    {
        $me = User::factory()->create(['role' => 'library-user', 'name' => 'Me']);
        $alice = User::factory()->create(['role' => 'library-user', 'name' => 'Alice']);
        $bob = User::factory()->create(['role' => 'library-user', 'name' => 'Bob']);
        $blocked = User::factory()->create(['role' => 'library-user', 'name' => 'Blocked']);
        BlockedEntity::create([
            'user_id' => $me->id,
            'string_id' => (string) Str::uuid(),
            'entity_type' => BlockedEntity::TYPE_USER,
            'entity_ref_id' => $blocked->id,
            'entity_value' => (string) $blocked->id,
            'entity_label' => 'Blocked',
            'created_at' => 1000,
        ]);
        $mine = Group::create(['name' => 'Book club']);
        $mine->members()->attach([$me->id, $alice->id]);
        Group::create(['name' => 'Other'])->members()->attach([$bob->id]);

        Sanctum::actingAs($me);
        $this->getJson('/api/v1/community/people')
            ->assertOk()
            ->assertExactJson([
                'users' => [
                    ['id' => $alice->id, 'name' => 'Alice', 'photo_url' => null, 'is_family' => false],
                    ['id' => $bob->id, 'name' => 'Bob', 'photo_url' => null, 'is_family' => false],
                ],
                'groups' => [
                    ['id' => $mine->id, 'name' => 'Book club', 'member_count' => 2, 'is_member' => true],
                ],
                'can_send_to_everyone' => false,
            ]);
    }

    public function testAdminSeesEveryGroup(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $names = ['Alpha', 'Beta'];
        foreach ($names as $name) {
            Group::create(['name' => $name]);
        }

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/community/people')->assertOk();

        $this->assertEquals($names, array_column($response->json('groups'), 'name'));
        $response->assertJsonPath('can_send_to_everyone', true);
    }

    public function testUserUpdatesOwnShareProgressSetting(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/community/settings', ['share_progress' => true])
            ->assertOk()
            ->assertExactJson(['share_progress' => true, 'family_only' => false]);
        $this->assertTrue($user->fresh()->community_share_progress);
    }

    public function testOnlyParentOrAdminCanSetFamilyOnly(): void
    {
        $parent = User::factory()->create(['role' => 'library-user']);
        $child = User::factory()->create(['role' => 'library-user', 'parent_user_id' => $parent->id]);
        $stranger = User::factory()->create(['role' => 'library-user']);

        Sanctum::actingAs($stranger);
        $this->putJson("/api/v1/community/members/{$child->id}/family-only", ['family_only' => true])
            ->assertStatus(403);

        Sanctum::actingAs($parent);
        $this->putJson("/api/v1/community/members/{$child->id}/family-only", ['family_only' => true])
            ->assertOk()
            ->assertExactJson(['id' => $child->id, 'family_only' => true]);
        $this->assertTrue($child->fresh()->community_family_only);
    }

    public function testDemoServerRefusesCommunityEndpointsAndAdvertisesDemoMode(): void
    {
        config(['app.demo_mode' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'library-user']));

        $this->getJson('/api/v1/community/people')
            ->assertStatus(403)
            ->assertJsonPath('error', 'community_demo_only');
        $this->getJson('/api/v1/notifications')->assertStatus(403);
        $this->getJson('/api/v1/health/capabilities')
            ->assertJsonPath('community', ['enabled' => false, 'mode' => 'demo']);
    }

    public function testSelfHostedServerAdvertisesFullMode(): void
    {
        $this->getJson('/api/v1/health/capabilities')
            ->assertJsonPath('community', ['enabled' => true, 'mode' => 'full']);
    }

    public function testDisabledCommunityIsRefused(): void
    {
        config(['community.enabled' => false]);
        Sanctum::actingAs(User::factory()->create(['role' => 'library-user']));

        $this->getJson('/api/v1/recommendations/inbox')
            ->assertStatus(403)
            ->assertJsonPath('error', 'community_disabled');
    }
}
