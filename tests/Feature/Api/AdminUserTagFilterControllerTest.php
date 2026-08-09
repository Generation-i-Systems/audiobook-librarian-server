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

    public function testAdminCanListATargetUsersFilters(): void
    {
        $target = User::factory()->create();
        UserTagFilter::create(['user_id' => $target->id, 'tag' => 'cozy', 'mode' => 'require']);

        $response = $this->getJson("/api/v1/admin/users/{$target->id}/tag-filters");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function testAdminCanSetALockedFilterOnATargetUser(): void
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
            'locked_by_admin' => true,
        ]);
    }

    public function testAdminCanRemoveALockedFilter(): void
    {
        $target = User::factory()->create();
        $filter = UserTagFilter::create(['user_id' => $target->id, 'tag' => 'mature', 'mode' => 'ban', 'locked_by_admin' => true]);

        $response = $this->deleteJson("/api/v1/admin/users/{$target->id}/tag-filters/{$filter->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_tag_filters', ['id' => $filter->id]);
    }

    public function testNonAdminCannotAccessAdminEndpoint(): void
    {
        $this->user->forceFill(['role' => 'library-user'])->save();
        $target = User::factory()->create();

        $response = $this->getJson("/api/v1/admin/users/{$target->id}/tag-filters");

        $response->assertStatus(403);
    }
}
