<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\Community\CommunityNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'library-user']);
        Sanctum::actingAs($this->user);
    }

    /** @return list<int> */
    private function makeNotifications(int $count, ?User $owner = null): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = UserNotification::create([
                'user_id' => ($owner ?? $this->user)->id,
                'type' => UserNotification::TYPE_RECOMMENDATION,
                'delivery' => 'show',
                'payload' => ['n' => $i],
            ])->id;
        }

        return $ids;
    }

    public function testIndexPagesOldestFirstAfterCursor(): void
    {
        $ids = $this->makeNotifications(5);
        $this->makeNotifications(2, User::factory()->create(['role' => 'library-user']));

        $first = $this->getJson('/api/v1/notifications?limit=3')->assertOk();
        $this->assertEquals(array_slice($ids, 0, 3), array_column($first->json('notifications'), 'id'));
        $first->assertJsonPath('cursor', $ids[2])
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('unread_count', 5);

        $second = $this->getJson('/api/v1/notifications?since=' . $ids[2])->assertOk();
        $this->assertEquals(array_slice($ids, 3), array_column($second->json('notifications'), 'id'));
        $second->assertJsonPath('has_more', false)->assertJsonPath('cursor', $ids[4]);
    }

    public function testEmptyPageKeepsCursor(): void
    {
        $ids = $this->makeNotifications(1);

        $this->getJson('/api/v1/notifications?since=' . $ids[0])
            ->assertOk()
            ->assertJsonPath('notifications', [])
            ->assertJsonPath('cursor', $ids[0]);
    }

    public function testMarkReadByIdsAndAll(): void
    {
        $ids = $this->makeNotifications(3);
        $otherIds = $this->makeNotifications(1, User::factory()->create(['role' => 'library-user']));

        $this->postJson('/api/v1/notifications/read', ['ids' => [$ids[0], $otherIds[0]]])
            ->assertOk()
            ->assertExactJson(['updated' => 1, 'unread_count' => 2]);

        $this->postJson('/api/v1/notifications/read', ['all' => true])
            ->assertExactJson(['updated' => 2, 'unread_count' => 0]);
        $this->assertNull(UserNotification::find($otherIds[0])?->read_at);
    }

    public function testPreferencesDefaultAndUpdate(): void
    {
        $this->getJson('/api/v1/notifications/preferences')
            ->assertExactJson(['preferences' => config('community.notification_defaults')]);

        $this->putJson('/api/v1/notifications/preferences', ['preferences' => ['recommendation' => 'badge']])
            ->assertOk()
            ->assertJsonPath('preferences.recommendation', 'badge');

        $this->putJson('/api/v1/notifications/preferences', ['preferences' => ['nope' => 'off']])
            ->assertStatus(422);
        $this->putJson('/api/v1/notifications/preferences', ['preferences' => ['recommendation' => 'loud']])
            ->assertStatus(422);
    }

    public function testOffPreferenceSkipsRecordingAndBadgeIsStored(): void
    {
        $notifier = app(CommunityNotifier::class);
        $cases = ['off' => null, 'badge' => 'badge', 'show' => 'show'];

        foreach ($cases as $choice => $expected) {
            $this->putJson('/api/v1/notifications/preferences', ['preferences' => ['recommendation' => $choice]]);
            $notification = $notifier->notify($this->user, UserNotification::TYPE_RECOMMENDATION, null, []);
            $this->assertEquals($expected, $notification?->delivery, "preference {$choice}");
        }
    }

    public function testEventSyncReportsNotificationCursorAndUnreadCount(): void
    {
        $ids = $this->makeNotifications(2);
        UserNotification::whereKey($ids[0])->update(['read_at' => now()]);

        $this->postJson('/api/v1/sync/events', ['events' => [], 'lastSyncTimestamp' => 0], [
            'X-Device-ID' => 'test-device',
            'X-Acting-As-Test' => 'true',
        ])->assertOk()->assertJsonPath('notifications', ['cursor' => $ids[1], 'unread' => 1]);
    }
}
