<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserTagFilter;

class UserTagFilterControllerTest extends ApiTestCase
{
    public function testIndexReturnsOnlyTheCurrentUsersFilters(): void
    {
        UserTagFilter::create([
            'user_id' => $this->user->id,
            'tag' => 'cozy',
            'mode' => 'require',
            'scope' => UserTagFilter::SCOPE_USER,
            'owner_key' => 'user:' . $this->user->id,
        ]);
        $otherUser = User::factory()->create();
        UserTagFilter::create([
            'user_id' => $otherUser->id,
            'tag' => 'other',
            'mode' => 'ban',
            'scope' => UserTagFilter::SCOPE_USER,
            'owner_key' => 'user:' . $otherUser->id,
        ]);

        $response = $this->getJson('/api/v1/users/me/tag-filters');

        $response->assertOk();
        $tags = array_column($response->json('data'), 'tag');
        $this->assertSame(['cozy'], $tags);
    }

    public function testIndexAlsoReturnsTheAccountsSystemFilters(): void
    {
        UserTagFilter::create([
            'user_id' => $this->user->id,
            'tag' => 'mature',
            'mode' => 'ban',
            'scope' => UserTagFilter::SCOPE_SYSTEM,
            'owner_key' => 'account:' . $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/users/me/tag-filters');

        $response->assertOk();
        $this->assertSame(['mature'], array_column($response->json('system'), 'tag'));
    }

    public function testStoreCreatesAFilter(): void
    {
        $response = $this->postJson('/api/v1/users/me/tag-filters', ['tag' => 'spoilers', 'mode' => 'ban']);

        $response->assertCreated();
        $this->assertDatabaseHas('user_tag_filters', [
            'user_id' => $this->user->id,
            'tag' => 'spoilers',
            'mode' => 'ban',
            'scope' => UserTagFilter::SCOPE_USER,
            'owner_key' => 'user:' . $this->user->id,
        ]);
    }

    public function testStoreRejectsAnInvalidMode(): void
    {
        $response = $this->postJson('/api/v1/users/me/tag-filters', ['tag' => 'x', 'mode' => 'not-a-mode']);

        $response->assertStatus(422);
    }

    public function testStoreDoesNotConflictWithASystemFilterOnTheSameTag(): void
    {
        UserTagFilter::create([
            'user_id' => $this->user->id,
            'tag' => 'mature',
            'mode' => 'ban',
            'scope' => UserTagFilter::SCOPE_SYSTEM,
            'owner_key' => 'account:' . $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/users/me/tag-filters', ['tag' => 'mature', 'mode' => 'require']);

        $response->assertCreated();
        $this->assertDatabaseHas('user_tag_filters', [
            'owner_key' => 'user:' . $this->user->id,
            'tag' => 'mature',
            'mode' => 'require',
        ]);
        $this->assertDatabaseHas('user_tag_filters', [
            'owner_key' => 'account:' . $this->user->id,
            'tag' => 'mature',
            'mode' => 'ban',
        ]);
    }

    public function testDestroyRemovesOwnFilter(): void
    {
        $filter = UserTagFilter::create([
            'user_id' => $this->user->id,
            'tag' => 'cozy',
            'mode' => 'require',
            'scope' => UserTagFilter::SCOPE_USER,
            'owner_key' => 'user:' . $this->user->id,
        ]);

        $response = $this->deleteJson("/api/v1/users/me/tag-filters/{$filter->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_tag_filters', ['id' => $filter->id]);
    }

    public function testDestroyCannotRemoveASystemFilter(): void
    {
        $filter = UserTagFilter::create([
            'user_id' => $this->user->id,
            'tag' => 'mature',
            'mode' => 'ban',
            'scope' => UserTagFilter::SCOPE_SYSTEM,
            'owner_key' => 'account:' . $this->user->id,
        ]);

        $response = $this->deleteJson("/api/v1/users/me/tag-filters/{$filter->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('user_tag_filters', ['id' => $filter->id]);
    }
}
