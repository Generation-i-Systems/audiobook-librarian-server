<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\BlockedEntity;
use App\Models\Book;
use App\Models\Group;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Models\UserNotification;
use App\Models\UserRecommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private User $member;
    private User $friend;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->create(['role' => 'library-user']);
        $this->friend = User::factory()->create(['role' => 'library-user']);
        $this->book = Book::factory()->create();
        RateLimiter::clear('community-recommend:' . $this->member->id);
        Sanctum::actingAs($this->member);
    }

    private function groupWith(User ...$users): Group
    {
        $group = Group::create(['name' => 'Group ' . Str::random(6)]);
        $group->members()->attach(collect($users)->pluck('id')->all());

        return $group;
    }

    private function block(User $blocker, User $blocked): void
    {
        BlockedEntity::create([
            'user_id' => $blocker->id,
            'string_id' => (string) Str::uuid(),
            'entity_type' => BlockedEntity::TYPE_USER,
            'entity_ref_id' => $blocked->id,
            'entity_value' => (string) $blocked->id,
            'entity_label' => (string) $blocked->name,
            'created_at' => 1000,
        ]);
    }

    public function testMemberRecommendsToOwnGroupFansOutToOtherMembers(): void
    {
        $third = User::factory()->create(['role' => 'library-user']);
        $group = $this->groupWith($this->member, $this->friend, $third);

        $this->postJson("/api/v1/recommendations/{$this->book->id}", [
            'group_ids' => [$group->id],
            'message' => 'Family pick',
        ])->assertStatus(201)->assertJsonPath('recipientCount', 2);

        $rows = UserRecommendation::orderBy('recipient_id')->get(['recipient_id', 'audience_type', 'group_id']);
        $this->assertEquals(
            [
                ['recipient_id' => $this->friend->id, 'audience_type' => 'group', 'group_id' => $group->id],
                ['recipient_id' => $third->id, 'audience_type' => 'group', 'group_id' => $group->id],
            ],
            $rows->map(fn ($r) => $r->only(['recipient_id', 'audience_type', 'group_id']))->all(),
        );
        $this->assertEquals(1, UserRecommendation::distinct()->count('batch_id'));
    }

    public function testMemberCannotRecommendToGroupTheyAreNotIn(): void
    {
        $group = $this->groupWith($this->friend);

        $this->postJson("/api/v1/recommendations/{$this->book->id}", ['group_ids' => [$group->id]])
            ->assertStatus(403);
        $this->assertDatabaseCount('user_recommendations', 0);
    }

    public function testOnlyAdminsCanRecommendToEveryone(): void
    {
        $this->postJson("/api/v1/recommendations/{$this->book->id}", ['everyone' => true])->assertStatus(403);

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/recommendations/{$this->book->id}", ['everyone' => true])
            ->assertStatus(201)
            ->assertJsonPath('recipientCount', 2);
        $this->assertEquals(
            [$this->member->id, $this->friend->id],
            UserRecommendation::orderBy('recipient_id')->pluck('recipient_id')->all(),
        );
    }

    public function testDirectRecipientWinsOverGroupAudience(): void
    {
        $group = $this->groupWith($this->member, $this->friend);

        $this->postJson("/api/v1/recommendations/{$this->book->id}", [
            'group_ids' => [$group->id],
            'recipient_ids' => [$this->friend->id],
        ])->assertStatus(201)->assertJsonPath('recipientCount', 1);

        $this->assertDatabaseHas('user_recommendations', [
            'recipient_id' => $this->friend->id,
            'audience_type' => 'user',
            'group_id' => null,
        ]);
    }

    public function testSendRequiresSomeAudience(): void
    {
        $this->postJson("/api/v1/recommendations/{$this->book->id}", ['message' => 'hi'])->assertStatus(422);
    }

    public function testBlockInEitherDirectionPreventsDirectSend(): void
    {
        foreach ([[$this->member, $this->friend], [$this->friend, $this->member]] as [$blocker, $blocked]) {
            BlockedEntity::query()->delete();
            $this->block($blocker, $blocked);

            $this->postJson("/api/v1/recommendations/{$this->book->id}", ['recipient_id' => $this->friend->id])
                ->assertStatus(403);
        }
        $this->assertDatabaseCount('user_recommendations', 0);
    }

    public function testBlockedMemberIsSkippedInGroupFanOut(): void
    {
        $third = User::factory()->create(['role' => 'library-user']);
        $group = $this->groupWith($this->member, $this->friend, $third);
        $this->block($third, $this->member);

        $this->postJson("/api/v1/recommendations/{$this->book->id}", ['group_ids' => [$group->id]])
            ->assertStatus(201)
            ->assertJsonPath('recipientCount', 1);
        $this->assertEquals([$this->friend->id], UserRecommendation::pluck('recipient_id')->all());
    }

    public function testFamilyOnlyUserIsReachableOnlyByFamily(): void
    {
        $child = User::factory()->create([
            'role' => 'library-user',
            'parent_user_id' => $this->member->id,
            'community_family_only' => true,
        ]);

        $this->postJson("/api/v1/recommendations/{$this->book->id}", ['recipient_id' => $child->id])
            ->assertStatus(201);

        Sanctum::actingAs($this->friend);
        $this->postJson("/api/v1/recommendations/{$this->book->id}", ['recipient_id' => $child->id])
            ->assertStatus(403);
    }

    public function testOpenRecipientsAreSkippedAndAllOpenIsConflict(): void
    {
        $third = User::factory()->create(['role' => 'library-user']);
        UserRecommendation::factory()->create([
            'sender_id' => $this->member->id,
            'recipient_id' => $this->friend->id,
            'book_id' => $this->book->id,
        ]);

        $this->postJson("/api/v1/recommendations/{$this->book->id}", [
            'recipient_ids' => [$this->friend->id, $third->id],
        ])->assertStatus(201)
            ->assertJsonPath('recipientCount', 1)
            ->assertJsonPath('skippedRecipientIds', [$this->friend->id]);

        $this->postJson("/api/v1/recommendations/{$this->book->id}", [
            'recipient_ids' => [$this->friend->id, $third->id],
        ])->assertStatus(409);
    }

    public function testSendsAreRateLimitedPerHour(): void
    {
        config(['community.recommendation_sends_per_hour' => 2]);
        $books = Book::factory()->count(3)->create();

        $statuses = $books->map(fn (Book $book) => $this->postJson(
            "/api/v1/recommendations/{$book->id}",
            ['recipient_id' => $this->friend->id],
        )->status())->all();

        $this->assertEquals([201, 201, 429], $statuses);
    }

    public function testSendRecordsNotificationForRecipient(): void
    {
        $this->postJson("/api/v1/recommendations/{$this->book->id}", [
            'recipient_id' => $this->friend->id,
            'message' => 'You will love it',
        ])->assertStatus(201);

        $notification = UserNotification::where('user_id', $this->friend->id)->sole();
        $this->assertEquals(
            [
                'type' => UserNotification::TYPE_RECOMMENDATION,
                'delivery' => 'show',
                'actor_id' => $this->member->id,
                'book_id' => $this->book->id,
                'message' => 'You will love it',
            ],
            [
                'type' => $notification->type,
                'delivery' => $notification->delivery,
                'actor_id' => $notification->actor_id,
                'book_id' => $notification->payload['book_id'] ?? null,
                'message' => $notification->payload['message'] ?? null,
            ],
        );
    }

    public function testRecipientReactionNotifiesSenderAndDismissHidesFromInbox(): void
    {
        /** @var UserRecommendation $recommendation */
        $recommendation = UserRecommendation::factory()->create([
            'sender_id' => $this->friend->id,
            'recipient_id' => $this->member->id,
            'book_id' => $this->book->id,
        ]);

        $this->patchJson("/api/v1/recommendations/{$recommendation->id}", [
            'reaction' => 'loved',
            'reply' => 'Thank you!',
        ])->assertOk()
            ->assertJsonPath('recommendation.reaction', 'loved')
            ->assertJsonPath('recommendation.reply', 'Thank you!');

        $this->assertEquals(
            [UserNotification::TYPE_RECOMMENDATION_REPLY],
            UserNotification::where('user_id', $this->friend->id)->pluck('type')->all(),
        );
        $recommendation->refresh();
        $this->assertNotNull($recommendation->seen_at);

        $this->getJson('/api/v1/recommendations/inbox')->assertJsonCount(1);
        $this->patchJson("/api/v1/recommendations/{$recommendation->id}", ['dismissed' => true])->assertOk();
        $this->getJson('/api/v1/recommendations/inbox')->assertJsonCount(0);
    }

    public function testOnlyRecipientCanUpdateRecommendation(): void
    {
        /** @var UserRecommendation $recommendation */
        $recommendation = UserRecommendation::factory()->create([
            'sender_id' => $this->member->id,
            'recipient_id' => $this->friend->id,
            'book_id' => $this->book->id,
        ]);

        $this->patchJson("/api/v1/recommendations/{$recommendation->id}", ['dismissed' => true])
            ->assertStatus(403);
    }

    public function testSentListShowsProgressOnlyWhenRecipientShares(): void
    {
        UserBookStatus::create([
            'user_id' => $this->friend->id,
            'book_id' => $this->book->id,
            'status' => 'in_progress',
            'order' => 0,
            'started_at' => now(),
        ]);
        UserRecommendation::factory()->create([
            'sender_id' => $this->member->id,
            'recipient_id' => $this->friend->id,
            'book_id' => $this->book->id,
        ]);

        $this->getJson('/api/v1/recommendations/sent')
            ->assertOk()
            ->assertJsonPath('0.recipient.id', $this->friend->id)
            ->assertJsonPath('0.recipientProgress', null);

        $this->friend->update(['community_share_progress' => true]);
        $this->getJson('/api/v1/recommendations/sent')->assertJsonPath('0.recipientProgress', 'started');
    }
}
