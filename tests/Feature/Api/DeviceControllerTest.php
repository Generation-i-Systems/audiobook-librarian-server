<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class DeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->token = $this->user->createToken('test-token')->plainTextToken;

        Sanctum::actingAs($this->user);
    }

    public function testListDevicesReturnsEmptyArrayForNewUser()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson('/api/v1/devices');

        $response->assertStatus(200)
            ->assertJson([
                'devices' => [],
            ]);
    }

    public function testListDevicesReturnsUserDevices()
    {
        $device1 = Device::create([
            'device_id' => 'device-1',
            'user_id' => $this->user->id,
            'name' => 'Phone',
            'last_seen' => now()->subHours(1),
            'sync_enabled' => true,
        ]);

        $device2 = Device::create([
            'device_id' => 'device-2',
            'user_id' => $this->user->id,
            'name' => 'Tablet',
            'last_seen' => now(),
            'sync_enabled' => false,
        ]);

        $otherUser = User::factory()->create();
        Device::create([
            'device_id' => 'device-3',
            'user_id' => $otherUser->id,
            'name' => 'Other User Device',
            'last_seen' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson('/api/v1/devices');

        $response->assertStatus(200);

        $devices = $response->json('devices');
        $this->assertCount(2, $devices);
        $this->assertEquals('device-2', $devices[0]['device_id']);
        $this->assertEquals('Tablet', $devices[0]['name']);
    }

    public function testUpdateDeviceNameSuccessfully()
    {
        $device = Device::create([
            'device_id' => 'test-device',
            'user_id' => $this->user->id,
            'name' => 'Old Name',
            'last_seen' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->putJson('/api/v1/devices/test-device', [
                'name' => 'New Name',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'device_id' => 'test-device',
                'name' => 'New Name',
                'sync_enabled' => true,
            ]);

        $this->assertDatabaseHas('devices', [
            'device_id' => 'test-device',
            'name' => 'New Name',
        ]);
    }

    public function testUpdateDeviceRequiresName()
    {
        $device = Device::create([
            'device_id' => 'test-device',
            'user_id' => $this->user->id,
            'name' => 'Device',
            'last_seen' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->putJson('/api/v1/devices/test-device', []);

        $response->assertStatus(422);
    }

    public function testUpdateDeviceReturns404ForNonExistentDevice()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->putJson('/api/v1/devices/non-existent', [
                'name' => 'New Name',
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'Device not found',
            ]);
    }

    public function testUpdateDeviceDoesNotAllowAccessToOtherUsersDevice()
    {
        $otherUser = User::factory()->create();
        $device = Device::create([
            'device_id' => 'other-device',
            'user_id' => $otherUser->id,
            'name' => 'Other Device',
            'last_seen' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->putJson('/api/v1/devices/other-device', [
                'name' => 'Hacked Name',
            ]);

        $response->assertStatus(404);
    }

    public function testDeleteDeviceSuccessfully()
    {
        $device = Device::create([
            'device_id' => 'test-device',
            'user_id' => $this->user->id,
            'name' => 'Device',
            'last_seen' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->deleteJson('/api/v1/devices/test-device');

        $response->assertStatus(204);

        $this->assertDatabaseMissing('devices', [
            'device_id' => 'test-device',
        ]);
    }

    public function testDeleteDeviceReturns404ForNonExistentDevice()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->deleteJson('/api/v1/devices/non-existent');

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'Device not found',
            ]);
    }

    public function testUpdateSyncEnabledSuccessfully()
    {
        $device = Device::create([
            'device_id' => 'test-device',
            'user_id' => $this->user->id,
            'name' => 'Device',
            'last_seen' => now(),
            'sync_enabled' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->putJson('/api/v1/devices/test-device/sync-enabled', [
                'enabled' => false,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'device_id' => 'test-device',
                'sync_enabled' => false,
            ]);

        $this->assertDatabaseHas('devices', [
            'device_id' => 'test-device',
            'sync_enabled' => false,
        ]);
    }

    public function testUpdateSyncEnabledRequiresEnabledField()
    {
        $device = Device::create([
            'device_id' => 'test-device',
            'user_id' => $this->user->id,
            'name' => 'Device',
            'last_seen' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->putJson('/api/v1/devices/test-device/sync-enabled', []);

        $response->assertStatus(422);
    }
}
