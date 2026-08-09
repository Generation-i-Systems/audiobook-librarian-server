<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\UserTagFilter;

class UserTagFilterControllerTest extends ApiTestCase
{
    public function testIndexReturnsOnlyTheCurrentUsersFilters(): void
    {
        UserTagFilter::create(['user_id' => $this->user->id, 'tag' => 'cozy', 'mode' => 'require']);
        $otherUser = \App\Models\User::factory()->create();
        UserTagFilter::create(['user_id' => $otherUser->id, 'tag' => 'other', 'mode' => 'ban']);

        $response = $this->getJson('/api/v1/users/me/tag-filters');

        $response->assertOk();
        $tags = array_column($response->json('data'), 'tag');
        $this->assertSame(['cozy'], $tags);
    }

    public function testStoreCreatesAFilter(): void
    {
        $response = $this->postJson('/api/v1/users/me/tag-filters', ['tag' => 'spoilers', 'mode' => 'ban']);

        $response->assertCreated();
        $this->assertDatabaseHas('user_tag_filters', [
            'user_id' => $this->user->id,
            'tag' => 'spoilers',
            'mode' => 'ban',
            'locked_by_admin' => false,
        ]);
    }

    public function testStoreRejectsAnInvalidMode(): void
    {
        $response = $this->postJson('/api/v1/users/me/tag-filters', ['tag' => 'x', 'mode' => 'not-a-mode']);

        $response->assertStatus(422);
    }

    public function testStoreCannotOverwriteALockedFilter(): void
    {
        UserTagFilter::create(['user_id' => $this->user->id, 'tag' => 'mature', 'mode' => 'ban', 'locked_by_admin' => true]);

        $response = $this->postJson('/api/v1/users/me/tag-filters', ['tag' => 'mature', 'mode' => 'require']);

        $response->assertStatus(403);
    }

    public function testDestroyRemovesOwnFilter(): void
    {
        $filter = UserTagFilter::create(['user_id' => $this->user->id, 'tag' => 'cozy', 'mode' => 'require']);

        $response = $this->deleteJson("/api/v1/users/me/tag-filters/{$filter->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_tag_filters', ['id' => $filter->id]);
    }

    public function testDestroyCannotRemoveALockedFilter(): void
    {
        $filter = UserTagFilter::create(['user_id' => $this->user->id, 'tag' => 'mature', 'mode' => 'ban', 'locked_by_admin' => true]);

        $response = $this->deleteJson("/api/v1/users/me/tag-filters/{$filter->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('user_tag_filters', ['id' => $filter->id]);
    }
}
