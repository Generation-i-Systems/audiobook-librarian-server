<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Message;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class MessageHistoryApiTest extends ApiTestCase
{
    private function otherUser(string $role = 'library-user'): User
    {
        /** @var User $other */
        $other = User::factory()->create(['role' => $role, 'email_verified_at' => now()]);

        return $other;
    }

    private function makeMessage(User $sender, User $recipient, string $content, ?string $acknowledgedAt = null, array $payload = []): Message
    {
        return Message::query()->create([
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'type' => 'general',
            'content' => $content,
            'payload' => $payload === [] ? null : $payload,
            'acknowledged_at' => $acknowledgedAt,
        ]);
    }

    #[Test]
    public function testReceivedHistoryIncludesReadMessagesNewestFirst(): void
    {
        $admin = $this->otherUser('admin');
        $older = $this->makeMessage($admin, $this->user, "Welcome\n\nGlad you joined.", now()->toDateTimeString());
        $newer = $this->makeMessage($admin, $this->user, 'Library closed Monday', null, ['subject' => 'Notice']);
        $this->makeMessage($this->user, $admin, 'not mine to receive');

        $response = $this->getJson('/api/v1/messages/history?box=received')->assertOk();

        $response->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.subject', 'Notice')
            ->assertJsonPath('data.0.body', 'Library closed Monday')
            ->assertJsonPath('data.0.readAt', null)
            ->assertJsonPath('data.0.sender.id', $admin->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.1.subject', 'Welcome')
            ->assertJsonPath('data.1.body', 'Glad you joined.')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.unread', 1);
        $this->assertNotNull($response->json('data.1.readAt'));
        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function testSentHistoryListsOnlyMessagesTheUserSentWithRecipients(): void
    {
        $friend = $this->otherUser();
        $mine = $this->makeMessage($this->user, $friend, 'See you', null, ['subject' => 'Hi']);
        $this->makeMessage($friend, $this->user, 'reply');

        $this->getJson('/api/v1/messages/history?box=sent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.recipient.id', $friend->id)
            ->assertJsonPath('data.0.recipient.name', $friend->name)
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function testHistoryPaginates(): void
    {
        $admin = $this->otherUser('admin');
        foreach (range(1, 5) as $i) {
            $this->makeMessage($admin, $this->user, "message {$i}");
        }

        $page2 = $this->getJson('/api/v1/messages/history?box=received&per_page=2&page=2')->assertOk();

        $page2->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.perPage', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('data.0.body', 'message 3');
    }

    #[Test]
    public function testHistoryRejectsUnknownBox(): void
    {
        $this->getJson('/api/v1/messages/history?box=archive')->assertStatus(422);
    }

    #[Test]
    public function testUserCanSendDirectMessageToAnotherUser(): void
    {
        $friend = $this->otherUser();

        $response = $this->postJson('/api/v1/messages', [
            'recipient_id' => $friend->id,
            'subject' => 'Book club',
            'content' => 'Thursday at 7?',
        ])->assertStatus(201);

        $messageId = $response->json('id');
        $this->assertIsString($messageId);
        $this->assertDatabaseHas('messages', [
            'id' => (int) $messageId,
            'sender_id' => $this->user->id,
            'recipient_id' => $friend->id,
            'content' => 'Thursday at 7?',
        ]);
        $this->assertSame('Book club', Message::query()->findOrFail((int) $messageId)->payload['subject'] ?? null);
    }

    #[Test]
    public function testDirectMessageToSelfIsRejected(): void
    {
        $this->postJson('/api/v1/messages', [
            'recipient_id' => $this->user->id,
            'content' => 'note to self',
        ])->assertStatus(422)->assertJsonValidationErrors('recipient_id');
    }

    #[Test]
    public function testDirectMessageToUnreachableUserIsForbidden(): void
    {
        /** @var User $restricted */
        $restricted = User::factory()->create([
            'role' => 'library-user',
            'email_verified_at' => now(),
            'community_family_only' => true,
        ]);

        $this->postJson('/api/v1/messages', [
            'recipient_id' => $restricted->id,
            'content' => 'hello',
        ])->assertStatus(403);
        $this->assertDatabaseMissing('messages', ['recipient_id' => $restricted->id]);
    }

    #[Test]
    public function testDirectMessagingIsRefusedWhenCommunityIsUnavailable(): void
    {
        config(['community.enabled' => false]);
        $friend = $this->otherUser();

        $this->postJson('/api/v1/messages', ['recipient_id' => $friend->id, 'content' => 'hello'])
            ->assertStatus(403);
    }

    #[Test]
    public function testMessageWithoutRecipientStillGoesToAdmin(): void
    {
        $admin = $this->otherUser('admin');

        $this->postJson('/api/v1/messages', ['content' => 'Hello admin'])->assertStatus(201);

        $this->assertDatabaseHas('messages', ['recipient_id' => $admin->id, 'content' => 'Hello admin']);
    }

    #[Test]
    public function testEventSyncReportsMessageCursorAndUnreadCount(): void
    {
        $admin = $this->otherUser('admin');
        $first = $this->makeMessage($admin, $this->user, 'one', now()->toDateTimeString());
        $second = $this->makeMessage($admin, $this->user, 'two');

        $this->postJson('/api/v1/sync/events', ['events' => [], 'lastSyncTimestamp' => 0], [
            'X-Device-ID' => 'test-device',
            'X-Acting-As-Test' => 'true',
        ])->assertOk()
            ->assertJsonPath('messages.cursor', $second->id)
            ->assertJsonPath('messages.unread', 1);
        $this->assertLessThan($second->id, $first->id);
    }

    #[Test]
    public function testRecommendationInboxCanIncludeAcknowledgedHistory(): void
    {
        $book = \App\Models\Book::factory()->create();
        $sender = $this->otherUser();
        $open = \App\Models\UserRecommendation::create([
            'sender_id' => $sender->id, 'recipient_id' => $this->user->id, 'book_id' => $book->id,
            'message' => 'open', 'batch_id' => 'b1', 'audience_type' => 'user',
        ]);
        $done = \App\Models\UserRecommendation::create([
            'sender_id' => $sender->id, 'recipient_id' => $this->user->id, 'book_id' => $book->id,
            'message' => 'done', 'batch_id' => 'b2', 'audience_type' => 'user', 'acknowledged_at' => now(),
        ]);

        $this->getJson('/api/v1/recommendations/inbox')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $open->id);

        $this->getJson('/api/v1/recommendations/inbox?status=all')->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.id', $done->id);
    }

    #[Test]
    public function testCapabilitiesAdvertiseMessagingAndDirectMessaging(): void
    {
        $capabilities = $this->getJson('/api/v1/health/capabilities')->assertOk()->json('capabilities');

        $this->assertContains('MESSAGING', $capabilities);
        $this->assertContains('DIRECT_MESSAGING', $capabilities);
    }

    #[Test]
    public function testDirectMessagingIsNotAdvertisedWhenCommunityIsUnavailable(): void
    {
        config(['community.enabled' => false]);

        $capabilities = $this->getJson('/api/v1/health/capabilities')->assertOk()->json('capabilities');

        $this->assertContains('MESSAGING', $capabilities);
        $this->assertNotContains('DIRECT_MESSAGING', $capabilities);
    }
}
