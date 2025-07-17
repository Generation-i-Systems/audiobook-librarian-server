<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @covers \App\Http\Controllers\AdminNotificationController
 */
class AdminNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock DocumentStoreServiceInterface
        $mock = Mockery::mock(DocumentStoreServiceInterface::class);
        $mock->shouldReceive('getClient')->andReturnSelf();
        $mock->shouldReceive('collection')->with('users')->andReturnSelf();
        $mock->shouldReceive('document')->andReturnSelf();
        $mock->shouldReceive('snapshot')->andReturn(new class
        {
            public function exists()
            {
                return true;
            }

            public function data()
            {
                return ['device_token' => 'testtoken', 'id' => 'user123'];
            }

            public function id()
            {
                return 'user123';
            }
        });
        $mock->shouldReceive('documents')->andReturn([
            new class
            {
                public function exists()
                {
                    return true;
                }

                public function data()
                {
                    return ['device_token' => 'testtoken', 'id' => 'user123'];
                }

                public function id()
                {
                    return 'user123';
                }
            },
        ]);
        $this->app->instance(DocumentStoreServiceInterface::class, $mock);
        Log::spy();
    }

    #[Test]
    public function send_notification_sends_to_specific_user(): void
    {
        $response = $this->post(route('admin.send.notification'), [
            'message' => 'Test message',
            'user_id' => 'user123',
        ]);
        $response->assertSessionHas('success', 'Notification sent to specific user!');
        Log::shouldHaveReceived('info')->withArgs([
            fn ($msg) => str_contains($msg, 'Sending push notification to user user123 with message: Test message'),
        ]);
    }

    #[Test]
    public function send_notification_sends_to_all_users(): void
    {
        $response = $this->post(route('admin.send.notification'), [
            'message' => 'Broadcast message',
        ]);
        $response->assertSessionHas('success', 'Notification sent to all users!');
        Log::shouldHaveReceived('info')->withArgs([
            fn ($msg) => str_contains($msg, 'Sending push notification to user user123 with message: Broadcast message'),
        ]);
    }

    #[Test]
    public function send_notification_returns_error_for_missing_user(): void
    {
        // Remock with user not found
        $mock = Mockery::mock(DocumentStoreServiceInterface::class);
        $mock->shouldReceive('getClient')->andReturnSelf();
        $mock->shouldReceive('collection')->with('users')->andReturnSelf();
        $mock->shouldReceive('document')->andReturnSelf();
        $mock->shouldReceive('snapshot')->andReturn(new class
        {
            public function exists()
            {
                return false;
            }
        });
        $this->app->instance(DocumentStoreServiceInterface::class, $mock);

        $response = $this->post(route('admin.send.notification'), [
            'message' => 'Test message',
            'user_id' => 'notfound',
        ]);
        $response->assertSessionHasErrors(['user_id']);
    }

    #[Test]
    public function send_notification_requires_message(): void
    {
        $response = $this->post(route('admin.send.notification'), [
            'user_id' => 'user123',
        ]);
        $response->assertSessionHasErrors(['message']);
    }
}
