<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Message;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class MessageAcknowledgeApiTest extends ApiTestCase
{
    #[Test]
    public function testMySqlServiceAcknowledgeMessageSetsAcknowledgedAt(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $message = Message::query()->create([
            'sender_id' => $this->user->id,
            'recipient_id' => $admin->id,
            'content' => 'hello',
        ]);

        $service = app(\App\Contracts\DocumentStoreServiceInterface::class);

        $this->assertTrue($service->acknowledgeMessage((string) $message->id));

        $message->refresh();
        $this->assertNotNull($message->acknowledged_at);
    }
}
