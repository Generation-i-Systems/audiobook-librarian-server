<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use Tests\Mocks\MockDocumentStoreService;
use Tests\TestCase;

class MockDocumentStoreServiceAcknowledgeMessageTest extends TestCase
{
    #[Test]
    public function acknowledgeMessageReturnsFalseWhenMessageDoesNotExist(): void
    {
        $service = new MockDocumentStoreService();

        $this->assertFalse($service->acknowledgeMessage('missing'));
    }

    #[Test]
    public function acknowledgeMessageReturnsTrueWhenMessageExists(): void
    {
        $service = new MockDocumentStoreService();

        $messageId = $service->createMessage([
            'sender_id' => 1,
            'recipient_id' => 2,
            'content' => 'hello',
        ]);

        $this->assertIsString($messageId);
        $this->assertTrue($service->acknowledgeMessage($messageId));
    }
}
