<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\BlockedEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockedEntityControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        \Laravel\Sanctum\Sanctum::actingAs($this->user);
    }

    private function headers(): array
    {
        return ['X-Acting-As-Test' => '1'];
    }

    public function test_can_block_an_author(): void
    {
        $stringId = (string) \Illuminate\Support\Str::uuid();

        $response = $this->postJson('/api/v1/blocks', [
            'string_id'     => $stringId,
            'entity_type'   => 'AUTHOR',
            'entity_ref_id' => 42,
            'entity_value'  => 'jane austen',
            'entity_label'  => 'Jane Austen',
            'created_at'    => 1000,
        ], $this->headers())->assertStatus(201);

        $response->assertJson([
            'block' => [
                'id'            => $stringId,
                'entity_type'   => 'AUTHOR',
                'entity_ref_id' => 42,
                'entity_value'  => 'jane austen',
                'entity_label'  => 'Jane Austen',
            ],
        ]);

        $this->assertDatabaseHas('blocked_entities', [
            'user_id'      => $this->user->id,
            'entity_type'  => 'AUTHOR',
            'entity_value' => 'jane austen',
        ]);
    }

    public function test_rejects_an_invalid_entity_type(): void
    {
        $this->postJson('/api/v1/blocks', [
            'string_id'    => (string) \Illuminate\Support\Str::uuid(),
            'entity_type'  => 'PUBLISHER',
            'entity_value' => 'acme',
            'entity_label' => 'Acme',
            'created_at'   => 1000,
        ], $this->headers())->assertStatus(422);
    }

    public function test_reviving_an_unblocked_entity_reuses_the_row_instead_of_violating_the_unique_constraint(): void
    {
        $firstStringId = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/blocks', [
            'string_id'    => $firstStringId,
            'entity_type'  => 'TAG',
            'entity_value' => 'spoilers',
            'entity_label' => 'spoilers',
            'created_at'   => 1000,
        ], $this->headers())->assertStatus(201);

        $this->deleteJson('/api/v1/blocks/' . $firstStringId, [], $this->headers())->assertStatus(200);
        $this->assertDatabaseMissing('blocked_entities', ['string_id' => $firstStringId]);

        // Client generates a new local uuid when re-blocking the same tag after an unblock.
        $secondStringId = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/blocks', [
            'string_id'    => $secondStringId,
            'entity_type'  => 'TAG',
            'entity_value' => 'spoilers',
            'entity_label' => 'spoilers',
            'created_at'   => 2000,
        ], $this->headers())->assertStatus(201);

        $this->assertDatabaseCount('blocked_entities', 1);
        $this->assertDatabaseHas('blocked_entities', ['string_id' => $secondStringId, 'entity_value' => 'spoilers']);
    }

    public function test_index_returns_only_the_authenticated_users_blocks(): void
    {
        $otherUser = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        BlockedEntity::create([
            'user_id' => $otherUser->id, 'string_id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => 'TAG', 'entity_value' => 'not-mine', 'entity_label' => 'Not Mine', 'created_at' => 1,
        ]);
        BlockedEntity::create([
            'user_id' => $this->user->id, 'string_id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => 'TAG', 'entity_value' => 'mine', 'entity_label' => 'Mine', 'created_at' => 1,
        ]);

        $response = $this->getJson('/api/v1/blocks', $this->headers())->assertStatus(200);

        $labels = collect($response->json('blocks'))->pluck('entity_label');
        $this->assertEquals(['Mine'], $labels->all());
    }

    public function test_cannot_delete_another_users_block(): void
    {
        $otherUser = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $block = BlockedEntity::create([
            'user_id' => $otherUser->id, 'string_id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => 'TAG', 'entity_value' => 'not-mine', 'entity_label' => 'Not Mine', 'created_at' => 1,
        ]);

        $this->deleteJson('/api/v1/blocks/' . $block->string_id, [], $this->headers())->assertStatus(403);
    }
}
