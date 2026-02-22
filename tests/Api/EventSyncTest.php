<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Models\Book;
use App\Models\ListeningEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventSyncTest extends TestCase
{
    use RefreshDatabase;

    public function testSyncCreatesNewEvents(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $book = Book::factory()->create();
        $this->actingAs($user, 'api');

        $payload = [
            'events' => [
                [
                    'id' => 'test-event-1',
                    'bookId' => $book->id,
                    'eventType' => 'SESSION_END',
                    'timestampMs' => 1707945600000,
                    'positionMs' => 1234567,
                    'metadata' => [
                        'sessionDurationMs' => 3600000,
                        'adjustedDurationMs' => 2400000,
                    ],
                    'deviceId' => 'test-device',
                    'timezone' => 'UTC',
                    'createdAt' => 1707945600000,
                ],
            ],
            'lastSyncTimestamp' => 0,
        ];

        $response = $this->postJson('/api/v1/sync/events', $payload, [
            'X-Device-ID' => 'test-device',
            'X-Acting-As-Test' => 'true',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'received' => 1,
        ]);

        $this->assertDatabaseHas('listening_events', [
            'id' => 'test-event-1',
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
    }

    public function testSyncDeduplicatesEvents(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $book = Book::factory()->create();
        $this->actingAs($user, 'api');

        // Create event
        ListeningEvent::create([
            'id' => 'test-event-1',
            'user_id' => $user->id,
            'book_id' => $book->id,
            'event_type' => 'SESSION_END',
            'timestamp_ms' => 1707945600000,
            'position_ms' => 1234567,
            'device_id' => 'test-device',
            'timezone' => 'UTC',
            'sync_status' => 'SYNCED',
            'created_at' => 1707945600000,
            'synced_at' => 1707945605000,
        ]);

        // Try to sync same event again
        $payload = [
            'events' => [
                [
                    'id' => 'test-event-1',
                    'bookId' => $book->id,
                    'eventType' => 'SESSION_END',
                    'timestampMs' => 1707945600000,
                    'positionMs' => 1234567,
                    'deviceId' => 'test-device',
                    'timezone' => 'UTC',
                    'createdAt' => 1707945600000,
                ],
            ],
            'lastSyncTimestamp' => 0,
        ];

        $response = $this->postJson('/api/v1/sync/events', $payload, [
            'X-Device-ID' => 'test-device',
            'X-Acting-As-Test' => 'true',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'received' => 0,  // Should be 0 because event already exists
        ]);
    }

    public function testSyncReturnsRemoteEventsFromOtherDevices(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $book = Book::factory()->create();
        $this->actingAs($user, 'api');

        ListeningEvent::create([
            'id' => 'remote-event-1',
            'user_id' => $user->id,
            'book_id' => $book->id,
            'event_type' => 'SESSION_END',
            'timestamp_ms' => 1707945600000,
            'position_ms' => 5000000,
            'device_id' => 'other-device',
            'timezone' => 'UTC',
            'sync_status' => 'SYNCED',
            'created_at' => 1707945600000,
            'synced_at' => 1707945605000,
        ]);

        $response = $this->postJson('/api/v1/sync/events', [
            'events' => [],
            'lastSyncTimestamp' => 0,
        ], [
            'X-Device-ID' => 'requesting-device',
            'X-Acting-As-Test' => 'true',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('remoteEvents.0.id', 'remote-event-1')
            ->assertJsonPath('remoteEvents.0.deviceId', 'other-device');
    }

    public function testSyncAlwaysReturnsNextSyncAfter(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/v1/sync/events', [
            'events' => [],
            'lastSyncTimestamp' => 9999999999999,
        ], [
            'X-Device-ID' => 'test-device',
            'X-Acting-As-Test' => 'true',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'hasMore' => false]);

        $this->assertNotNull($response->json('nextSyncAfter'));
        $this->assertGreaterThan(0, $response->json('nextSyncAfter'));
    }

    public function testSyncSkipsMigratedEvents(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $book = Book::factory()->create();
        $this->actingAs($user, 'api');

        $payload = [
            'events' => [
                [
                    'id' => 'migrated-event-1',
                    'bookId' => $book->id,
                    'eventType' => 'SESSION_END',
                    'timestampMs' => 1707945600000,
                    'positionMs' => 1234567,
                    'deviceId' => 'test-device',
                    'timezone' => 'UTC',
                    'createdAt' => 1707945600000,
                    'migratedFrom' => 'listening_session',
                    'migrationSourceId' => '123',
                ],
            ],
            'lastSyncTimestamp' => 0,
        ];

        $response = $this->postJson('/api/v1/sync/events', $payload, [
            'X-Device-ID' => 'test-device',
            'X-Acting-As-Test' => 'true',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'received' => 0,  // Should be 0 because migrated events are skipped
        ]);

        $this->assertDatabaseMissing('listening_events', [
            'id' => 'migrated-event-1',
        ]);
    }
}
