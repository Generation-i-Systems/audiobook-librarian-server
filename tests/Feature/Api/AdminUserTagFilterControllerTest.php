<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserTagFilter;

class AdminUserTagFilterControllerTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->user->forceFill(['role' => 'admin'])->save();
    }

    public function testAdminCanListATargetUsersSystemFilters(): void
    {
        $target = User::factory()->create();
        UserTagFilter::create([
            'user_id' => $target->id,
            'tag' => 'cozy',
            'mode' => 'require',
            'scope' => UserTagFilter::SCOPE_SYSTEM,
            'owner_key' => 'account:' . $target->id,
        ]);

        $response = $this->getJson("/api/v1/admin/users/{$target->id}/tag-filters");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function testAdminCanSetASystemFilterOnATargetUser(): void
    {
        $target = User::factory()->create();

        $response = $this->postJson("/api/v1/admin/users/{$target->id}/tag-filters", [
            'tag' => 'mature',
            'mode' => 'ban',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('user_tag_filters', [
            'user_id' => $target->id,
            'tag' => 'mature',
            'mode' => 'ban',
            'scope' => UserTagFilter::SCOPE_SYSTEM,
            'owner_key' => 'account:' . $target->id,
        ]);
    }

    public function testAdminCanRemoveASystemFilter(): void
    {
        $target = User::factory()->create();
        $filter = UserTagFilter::create([
            'user_id' => $target->id,
            'tag' => 'mature',
            'mode' => 'ban',
            'scope' => UserTagFilter::SCOPE_SYSTEM,
            'owner_key' => 'account:' . $target->id,
        ]);

        $response = $this->deleteJson("/api/v1/admin/users/{$target->id}/tag-filters/{$filter->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_tag_filters', ['id' => $filter->id]);
    }

    public function testAdminSettingASystemFilterOnAChildAppliesToTheWholeAccount(): void
    {
        $parent = User::factory()->create();
        $child = User::factory()->create(['parent_user_id' => $parent->id]);

        $response = $this->postJson("/api/v1/admin/users/{$child->id}/tag-filters", [
            'tag' => 'mature',
            'mode' => 'ban',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('user_tag_filters', [
            'user_id' => $parent->id,
            'tag' => 'mature',
            'mode' => 'ban',
            'scope' => UserTagFilter::SCOPE_SYSTEM,
            'owner_key' => 'account:' . $parent->id,
        ]);
    }

    public function testNonAdminCannotAccessAdminEndpoint(): void
    {
        $this->user->forceFill(['role' => 'library-user'])->save();
        $target = User::factory()->create();

        $response = $this->getJson("/api/v1/admin/users/{$target->id}/tag-filters");

        $response->assertStatus(403);
    }
}
