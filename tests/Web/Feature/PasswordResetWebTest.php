<?php

declare(strict_types=1);

namespace Tests\Web\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetWebTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function testForgotPasswordSendsResetEmailAndRedirects(): void
    {
        Notification::fake();

        /** @var User $user */
        $user = User::factory()->create([
            'email' => 'web-reset@example.com',
        ]);

        $response = $this->post('/password/email', [
            'email' => $user->email,
        ]);

        $response->assertStatus(302);

        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class);
    }

    #[Test]
    public function testResetPasswordUpdatesPasswordAndRedirectsHome(): void
    {
        Notification::fake();

        /** @var User $user */
        $user = User::factory()->create([
            'email' => 'web-reset-success@example.com',
        ]);

        $this->post('/password/email', [
            'email' => $user->email,
        ])->assertStatus(302);

        $token = null;
        Notification::assertSentTo(
            $user,
            \Illuminate\Auth\Notifications\ResetPassword::class,
            function ($notification) use (&$token): bool {
                /** @var \Illuminate\Auth\Notifications\ResetPassword $notification */
                $token = $notification->token;

                return $token !== '';
            }
        );

        $response = $this->post('/password/reset', [
            'token' => (string) $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('password.reset.success'));

        $user->refresh();
        $this->assertTrue(password_verify('new-password-123', (string) $user->password));
    }
}
