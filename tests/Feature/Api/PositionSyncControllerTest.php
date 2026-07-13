<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookProgress;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class PositionSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $token;
    protected $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->token = $this->user->createToken('test-token')->plainTextToken;
        $this->book = Book::factory()->create();

        Sanctum::actingAs($this->user);
    }

    public function testGetPositionsReturnsEmptyForNewUser()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-1',
            'X-Device-Name' => 'Test Device',
        ])
            ->getJson('/api/v1/sync/positions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'server_timestamp',
                'positions',
            ]);

        $this->assertEmpty($response->json('positions'));
    }

    public function testGetPositionsReturnsOnlyEnabledDevices()
    {
        $device1 = Device::create([
            'device_id' => 'device-1',
            'user_id' => $this->user->id,
            'name' => 'Phone',
            'sync_enabled' => true,
        ]);

        $device2 = Device::create([
            'device_id' => 'device-2',
            'user_id' => $this->user->id,
            'name' => 'Tablet',
            'sync_enabled' => false,
        ]);

        BookProgress::create([
            'book_id' => $this->book->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-1',
            'current_position_seconds' => 1800,
            'progress_percentage' => 25.0,
        ]);

        BookProgress::create([
            'book_id' => $this->book->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-2',
            'current_position_seconds' => 3600,
            'progress_percentage' => 50.0,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-3',
            'X-Device-Name' => 'New Device',
        ])
            ->getJson('/api/v1/sync/positions');

        $response->assertStatus(200);

        $positions = $response->json('positions');
        $this->assertCount(1, $positions);
        $this->assertEquals('device-1', $positions[0]['device_id']);
        $this->assertEquals(1800000, $positions[0]['position_ms']);
    }

    public function testGetPositionsExcludesRequestingDevice()
    {
        $device1 = Device::create([
            'device_id' => 'device-1',
            'user_id' => $this->user->id,
            'name' => 'Phone',
            'sync_enabled' => true,
        ]);

        $device2 = Device::create([
            'device_id' => 'device-2',
            'user_id' => $this->user->id,
            'name' => 'Tablet',
            'sync_enabled' => true,
        ]);

        BookProgress::create([
            'book_id' => $this->book->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-1',
            'current_position_seconds' => 1800,
            'progress_percentage' => 25.0,
        ]);

        BookProgress::create([
            'book_id' => $this->book->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-2',
            'current_position_seconds' => 3600,
            'progress_percentage' => 50.0,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-1',
            'X-Device-Name' => 'Phone',
        ])
            ->getJson('/api/v1/sync/positions');

        $response->assertStatus(200);

        $positions = $response->json('positions');
        $this->assertCount(1, $positions);
        $this->assertEquals('device-2', $positions[0]['device_id']);
    }

    public function testGetPositionsFiltersBySinceParameter()
    {
        $device = Device::create([
            'device_id' => 'device-1',
            'user_id' => $this->user->id,
            'name' => 'Phone',
            'sync_enabled' => true,
        ]);

        $oldProgress = BookProgress::create([
            'book_id' => $this->book->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-1',
            'current_position_seconds' => 1800,
            'progress_percentage' => 25.0,
        ]);

        BookProgress::withoutTimestamps(function () use ($oldProgress): void {
            $oldProgress->forceFill([
                'updated_at' => now()->subHours(2),
            ])->save();
        });

        $sinceTime = now()->subHour();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-2',
            'X-Device-Name' => 'Tablet',
        ])
            ->getJson('/api/v1/sync/positions?since=' . $sinceTime->toIso8601String());

        $response->assertStatus(200);
        $this->assertEmpty($response->json('positions'));
    }

    public function testShowPositionForSpecificBook()
    {
        $device1 = Device::create([
            'device_id' => 'device-1',
            'user_id' => $this->user->id,
            'name' => 'Phone',
            'sync_enabled' => true,
        ]);

        $device2 = Device::create([
            'device_id' => 'device-2',
            'user_id' => $this->user->id,
            'name' => 'Tablet',
            'sync_enabled' => true,
        ]);

        BookProgress::create([
            'book_id' => $this->book->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-1',
            'current_position_seconds' => 1800,
            'progress_percentage' => 25.0,
        ]);

        BookProgress::create([
            'book_id' => $this->book->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-2',
            'current_position_seconds' => 3600,
            'progress_percentage' => 50.0,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson("/api/v1/sync/positions/{$this->book->id}");

        $response->assertStatus(200)
            ->assertJson([
                'book_id' => $this->book->id,
            ]);

        $positions = $response->json('positions');
        $this->assertCount(2, $positions);
    }

    public function testStorePositionCreatesNewRecord()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-1',
            'X-Device-Name' => 'Test Phone',
        ])
            ->postJson('/api/v1/sync/positions', [
                'client_timestamp' => now()->toIso8601String(),
                'positions' => [
                    [
                        'book_id' => $this->book->id,
                        'position_ms' => 3600000,
                        'progress_percentage' => 50.0,
                        'current_chapter' => 5,
                        'current_chapter_name' => 'Chapter Five',
                        'is_finished' => false,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'accepted' => 1,
                'conflicts' => [],
            ]);

        $this->assertDatabaseHas('book_progress', [
            'book_id' => $this->book->id,
            'device_id' => 'device-1',
            'current_position_seconds' => 3600,
            'progress_percentage' => 50.0,
        ]);
    }

    public function testStorePositionDetectsConflicts()
    {
        $device1 = Device::create([
            'device_id' => 'device-1',
            'user_id' => $this->user->id,
            'name' => 'Phone',
            'sync_enabled' => true,
        ]);

        BookProgress::create([
            'book_id' => $this->book->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-1',
            'current_position_seconds' => 5400,
            'progress_percentage' => 75.0,
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-2',
            'X-Device-Name' => 'Tablet',
        ])
            ->postJson('/api/v1/sync/positions', [
                'client_timestamp' => now()->toIso8601String(),
                'positions' => [
                    [
                        'book_id' => $this->book->id,
                        'position_ms' => 3600000,
                        'progress_percentage' => 50.0,
                        'current_chapter' => 5,
                        'current_chapter_name' => 'Chapter Five',
                        'is_finished' => false,
                        'updated_at' => now()->subHours(2)->toIso8601String(),
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $conflicts = $response->json('conflicts');
        $this->assertCount(1, $conflicts);
        $this->assertEquals($this->book->id, $conflicts[0]['book_id']);
        $this->assertEquals('device-1', $conflicts[0]['server_device_id']);
    }

    public function testStorePositionRequiresDeviceHeaders()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->postJson('/api/v1/sync/positions', [
                'client_timestamp' => now()->toIso8601String(),
                'positions' => [
                    [
                        'book_id' => $this->book->id,
                        'position_ms' => 3600000,
                        'progress_percentage' => 50.0,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'X-Device-ID and X-Device-Name headers are required',
            ]);
    }

    public function testStorePositionValidatesInput()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-1',
            'X-Device-Name' => 'Test Device',
        ])
            ->postJson('/api/v1/sync/positions', [
                'client_timestamp' => now()->toIso8601String(),
                'positions' => [],
            ]);

        $response->assertStatus(422);
    }
}
