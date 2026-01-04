<?php

declare(strict_types=1);

namespace Tests\Api\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function testForgotPasswordReturnsOkAndSendsNotificationForExistingUser(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset-user@example.com',
        ]);

        $response = $this->postJson('/api/v1/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);

        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class);
    }

    #[Test]
    public function testForgotPasswordReturnsOkForUnknownEmail(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/forgot-password', [
            'email' => 'unknown@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);

        Notification::assertNothingSent();
    }

    #[Test]
    public function testResetPasswordResetsPasswordWithValidToken(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset-success@example.com',
        ]);

        $this->postJson('/api/v1/forgot-password', [
            'email' => $user->email,
        ])->assertStatus(200);

        $token = null;
        Notification::assertSentTo(
            $user,
            \Illuminate\Auth\Notifications\ResetPassword::class,
            function ($notification) use (&$token): bool {
                $token = $notification->token ?? null;

                return $token !== null && $token !== '';
            }
        );

        $response = $this->postJson('/api/v1/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Password has been reset successfully.',
        ]);

        $user->refresh();
        $this->assertTrue(password_verify('new-password-123', (string) $user->password));
    }

    #[Test]
    public function testResetPasswordRejectsInvalidToken(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-fail@example.com',
        ]);

        $response = $this->postJson('/api/v1/reset-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'Invalid token or email.',
        ]);
    }
}
