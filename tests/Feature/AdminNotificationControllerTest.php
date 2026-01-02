<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\App\Http\Controllers\AdminNotificationController::class)]
class AdminNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set APP_KEY for testing
        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));

        // Disable debugging in tests
        $this->app['config']->set('app.debug', false);
        $this->app['config']->set('telescope.enabled', false);

        // Create an admin user for authentication
        $adminUser = new \App\Auth\DocumentstoreUser([
            'id' => 'admin-user',
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'is_admin' => true,
            'permissions' => ['admin.*'],
        ]);

        // Authenticate as admin user and bypass admin middleware
        $this->actingAs($adminUser);
        $this->withoutMiddleware(\App\Http\Middleware\CheckAdminRole::class);

        // Mock DocumentStoreServiceInterface
        $mock = Mockery::mock(DocumentStoreServiceInterface::class);

        // Mock getUserById method - returns user data when user exists
        $mock->shouldReceive('getUserById')
            ->with('user123')
            ->andReturn(['device_token' => 'testtoken', 'id' => 'user123']);

        // Mock getAllUsers method - returns array of users
        $mock->shouldReceive('getAllUsers')
            ->andReturn([
                ['device_token' => 'testtoken', 'id' => 'user123']
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

        $response->assertStatus(302); // Should redirect back
        $response->assertSessionHas('success', 'Notification sent to specific user!');

        // Verify the log call was made (only if device token exists)
        Log::shouldHaveReceived('info')->atLeast()->once()->withArgs(function ($message) {
            return str_contains($message, 'Sending push notification to user user123') &&
                   str_contains($message, 'Test message');
        });
    }

    #[Test]
    public function send_notification_sends_to_all_users(): void
    {
        $response = $this->post(route('admin.send.notification'), [
            'message' => 'Broadcast message',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('success', 'Notification sent to all users!');

        // Verify the log call was made for all users with device tokens
        Log::shouldHaveReceived('info')->atLeast()->once()->withArgs(function ($message) {
            return str_contains($message, 'Sending push notification to user user123') &&
                   str_contains($message, 'Broadcast message');
        });
    }

    #[Test]
    public function send_notification_returns_error_for_missing_user(): void
    {
        // Remock with user not found
        $mock = Mockery::mock(DocumentStoreServiceInterface::class);
        $mock->shouldReceive('getUserById')
            ->with('notfound')
            ->andReturn(null);
        $this->app->instance(DocumentStoreServiceInterface::class, $mock);

        $response = $this->post(route('admin.send.notification'), [
            'message' => 'Test message',
            'user_id' => 'notfound',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['user_id']);
    }

    #[Test]
    public function send_notification_requires_message(): void
    {
        $response = $this->post(route('admin.send.notification'), [
            'user_id' => 'user123',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['message']);
    }
}
