<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Message;
use App\Models\User;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

class MessageDeliveryApiTest extends ApiTestCase
{
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var User $other */
        $other = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $this->other = $other;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeMessage(User $sender, User $recipient, ?string $deliveredAt = null): Message
    {
        return Message::query()->create([
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'type' => 'general',
            'content' => 'hello',
            'delivered_at' => $deliveredAt,
        ]);
    }

    /**
     * @param list<Message> $messages
     * @return array<int, string|null>
     */
    private function deliveredAt(array $messages): array
    {
        $result = [];
        foreach ($messages as $message) {
            $result[$message->id] = $message->fresh()?->delivered_at?->toDateTimeString();
        }

        return $result;
    }

    #[Test]
    public function testFetchingMessagesMarksOnlyTheRecipientsReturnedMessagesDelivered(): void
    {
        $deliveredTime = '2026-10-22 09:00:00';
        Carbon::setTestNow($deliveredTime);
        $mine = $this->makeMessage($this->other, $this->user);
        $theirs = $this->makeMessage($this->user, $this->other);

        $response = $this->getJson('/api/v1/messages')->assertOk();

        $this->assertSame(
            [$mine->id => $deliveredTime, $theirs->id => null],
            $this->deliveredAt([$mine, $theirs])
        );
        $this->assertNotNull($response->json('data.0.deliveredAt'));
    }

    #[Test]
    public function testRefetchingKeepsTheFirstDeliveryTime(): void
    {
        $firstDelivery = '2026-10-20 08:00:00';
        $message = $this->makeMessage($this->other, $this->user, $firstDelivery);
        Carbon::setTestNow('2026-10-22 09:00:00');

        $this->getJson('/api/v1/messages')->assertOk();

        $this->assertSame([$message->id => $firstDelivery], $this->deliveredAt([$message]));
    }

    #[Test]
    public function testReceivedHistoryMarksDeliveredButSentHistoryDoesNot(): void
    {
        $deliveredTime = '2026-10-22 09:00:00';
        Carbon::setTestNow($deliveredTime);
        $received = $this->makeMessage($this->other, $this->user);
        $sent = $this->makeMessage($this->user, $this->other);

        $this->getJson('/api/v1/messages/history?box=sent')->assertOk();
        $this->assertSame([$received->id => null, $sent->id => null], $this->deliveredAt([$received, $sent]));

        $this->getJson('/api/v1/messages/history?box=received')
            ->assertOk()
            ->assertJsonPath('data.0.deliveredAt', Carbon::parse($deliveredTime)->toISOString());
        $this->assertSame(
            [$received->id => $deliveredTime, $sent->id => null],
            $this->deliveredAt([$received, $sent])
        );
    }
}
