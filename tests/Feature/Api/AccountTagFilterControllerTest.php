<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserTagFilter;

/**
 * Covers the non-admin path for system (account-wide) tag filters: an account's
 * parent, or a member the parent designated as a filter manager, may set or remove
 * them for every member of that account. See AdminUserTagFilterControllerTest for the
 * equivalent full-admin path.
 */
class AccountTagFilterControllerTest extends ApiTestCase
{
    public function testParentCanSetASystemFilterForTheirOwnAccount(): void
    {
        $child = User::factory()->create(['parent_user_id' => $this->user->id]);

        $response = $this->postJson("/api/v1/users/{$this->user->id}/tag-filters/system", [
            'tag' => 'mature',
            'mode' => 'ban',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('user_tag_filters', [
            'user_id' => $this->user->id,
            'tag' => 'mature',
            'mode' => 'ban',
            'scope' => UserTagFilter::SCOPE_SYSTEM,
            'owner_key' => 'account:' . $this->user->id,
        ]);
    }

    public function testParentCanSetASystemFilterThroughAChildsIdAndItAppliesToTheAccount(): void
    {
        $child = User::factory()->create(['parent_user_id' => $this->user->id]);

        $response = $this->postJson("/api/v1/users/{$child->id}/tag-filters/system", [
            'tag' => 'mature',
            'mode' => 'ban',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('user_tag_filters', [
            'user_id' => $this->user->id,
            'owner_key' => 'account:' . $this->user->id,
            'tag' => 'mature',
        ]);
    }

    public function testDesignatedFilterManagerCanSetASystemFilterForTheAccount(): void
    {
        $parent = User::factory()->create();
        $manager = User::factory()->create([
            'role' => 'library-user',
            'parent_user_id' => $parent->id,
            'is_filter_manager' => true,
        ]);
        $this->actingAsUser($manager);

        $response = $this->postJson("/api/v1/users/{$parent->id}/tag-filters/system", [
            'tag' => 'mature',
            'mode' => 'ban',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('user_tag_filters', [
            'owner_key' => 'account:' . $parent->id,
            'tag' => 'mature',
        ]);
    }

    public function testOrdinaryChildCannotSetASystemFilterForTheAccount(): void
    {
        $parent = User::factory()->create();
        $child = User::factory()->create([
            'role' => 'library-user',
            'parent_user_id' => $parent->id,
            'is_filter_manager' => false,
        ]);
        $this->actingAsUser($child);

        $response = $this->postJson("/api/v1/users/{$parent->id}/tag-filters/system", [
            'tag' => 'mature',
            'mode' => 'ban',
        ]);

        $response->assertStatus(403);
    }

    public function testUnrelatedUserCannotManageAnotherAccountsSystemFilters(): void
    {
        $otherAccountOwner = User::factory()->create();

        $response = $this->postJson("/api/v1/users/{$otherAccountOwner->id}/tag-filters/system", [
            'tag' => 'mature',
            'mode' => 'ban',
        ]);

        $response->assertStatus(403);
    }

    public function testDesignatedFilterManagerCanRemoveASystemFilter(): void
    {
        $parent = User::factory()->create();
        $manager = User::factory()->create([
            'role' => 'library-user',
            'parent_user_id' => $parent->id,
            'is_filter_manager' => true,
        ]);
        $filter = UserTagFilter::create([
            'user_id' => $parent->id,
            'tag' => 'mature',
            'mode' => 'ban',
            'scope' => UserTagFilter::SCOPE_SYSTEM,
            'owner_key' => 'account:' . $parent->id,
        ]);
        $this->actingAsUser($manager);

        $response = $this->deleteJson("/api/v1/users/{$parent->id}/tag-filters/system/{$filter->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_tag_filters', ['id' => $filter->id]);
    }
}
