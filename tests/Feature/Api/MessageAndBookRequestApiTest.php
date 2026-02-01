<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Message;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class MessageAndBookRequestApiTest extends ApiTestCase
{
    #[Test]
    public function testMessagesEndpointCreatesMessage(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $content = 'Hello admin';

        $response = $this->postJson('/api/v1/messages', [
            'content' => $content,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id']);

        $messageId = $response->json('id');
        $this->assertIsString($messageId);

        $this->assertDatabaseHas('messages', [
            'id' => (int) $messageId,
            'sender_id' => $this->user->id,
            'recipient_id' => $admin->id,
            'content' => $content,
        ]);
    }

    #[Test]
    public function testBookRequestsEndpointCreatesMessage(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $payload = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'series' => [],
            'description' => 'Test Description',
            'book_id' => 'book-123',
        ];

        $response = $this->postJson('/api/v1/book-requests', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id']);

        $messageId = $response->json('id');
        $this->assertIsString($messageId);

        $message = Message::query()->find((int) $messageId);
        $this->assertNotNull($message);

        $this->assertSame($this->user->id, $message->sender_id);
        $this->assertSame($admin->id, $message->recipient_id);

        $decoded = json_decode($message->content, true);
        $this->assertIsArray($decoded);
        $this->assertSame('book_request', $decoded['type'] ?? null);
        $this->assertSame($payload['book_id'], $decoded['book_id'] ?? null);
        $this->assertSame($payload['title'], $decoded['title'] ?? null);
    }
}
