<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Mail\EmailOtpMail;
use App\Mail\WelcomeMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminUserControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_send_otp_requires_admin(): void
    {
        $user = User::factory()->create(['email' => 'target@example.com', 'role' => 'user']);
        $this->actingAs($user);

        $response = $this->postJson('/api/v1/admin/users/' . $user->id . '/send-otp');

        $response->assertStatus(403);
    }

    public function test_send_otp_emails_target_user(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $target = User::factory()->create(['email' => 'target@example.com']);

        $response = $this->postJson('/api/v1/admin/users/' . $target->id . '/send-otp');

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Sign-in email sent to target@example.com.']);

        Mail::assertSent(EmailOtpMail::class, function (EmailOtpMail $mail) {
            return $mail->hasTo('target@example.com');
        });

        $this->assertDatabaseHas('email_otps', ['email' => 'target@example.com']);
    }

    public function test_generate_login_qr_returns_url_without_sending_email(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $target = User::factory()->create(['email' => 'qrtarget@example.com']);

        $response = $this->postJson('/api/v1/admin/users/' . $target->id . '/login-qr');

        $response->assertStatus(200);
        $response->assertJsonStructure(['url', 'expires_in_seconds']);
        $response->assertJsonPath('expires_in_seconds', EmailOtp::TTL_MINUTES * 60);
        $this->assertStringContainsString('/auth/magic/', $response->json('url'));

        Mail::assertNothingSent();

        $token = basename((string) parse_url($response->json('url'), PHP_URL_PATH));
        $otp = EmailOtp::where('magic_token_hash', hash('sha256', $token))->first();
        $this->assertNotNull($otp);
        $this->assertEquals('qrtarget@example.com', $otp->email);
        $this->assertTrue($otp->isRedeemable());
    }

    public function test_generate_login_qr_requires_admin(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user);

        $response = $this->postJson('/api/v1/admin/users/' . $user->id . '/login-qr');

        $response->assertStatus(403);
    }

    public function test_generate_login_qr_for_unknown_user_returns_404(): void
    {
        $this->actingAs($this->admin());

        $response = $this->postJson('/api/v1/admin/users/999999/login-qr');

        $response->assertStatus(404);
    }

    public function test_store_sends_welcome_mail_not_otp_mail_for_new_user(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'username' => 'newuser',
        ]);

        $response->assertStatus(201);

        Mail::assertSent(WelcomeMail::class, function (WelcomeMail $mail) {
            return $mail->hasTo('newuser@example.com');
        });
        Mail::assertNotSent(EmailOtpMail::class);
    }

    public function test_store_does_not_send_mail_when_send_otp_email_false(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'No Mail User',
            'email' => 'nomail@example.com',
            'username' => 'nomailuser',
            'send_otp_email' => false,
        ]);

        $response->assertStatus(201);

        Mail::assertNothingSent();
    }

    public function test_verify_transitions_unverified_user_and_sends_welcome_mail(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $target = User::factory()->create(['email' => 'pending@example.com', 'role' => 'unverified']);

        $response = $this->postJson('/api/v1/admin/users/' . $target->id . '/verify');

        $response->assertStatus(200)
            ->assertJsonPath('user.role', 'user')
            ->assertJsonPath('message', 'User verified successfully.');

        $target->refresh();
        $this->assertSame('user', $target->role);
        $this->assertNotNull($target->email_verified_at);

        Mail::assertSent(WelcomeMail::class, function (WelcomeMail $mail) {
            return $mail->hasTo('pending@example.com');
        });
    }

    public function test_verify_accepts_explicit_role(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $target = User::factory()->create(['email' => 'pendingadmin@example.com', 'role' => 'unverified']);

        $response = $this->postJson('/api/v1/admin/users/' . $target->id . '/verify', ['role' => 'admin']);

        $response->assertStatus(200)->assertJsonPath('user.role', 'admin');
    }

    public function test_verify_rejects_invalid_role(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $target = User::factory()->create(['email' => 'badrole@example.com', 'role' => 'unverified']);

        $response = $this->postJson('/api/v1/admin/users/' . $target->id . '/verify', ['role' => 'not-a-role']);

        $response->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_verify_is_a_no_op_for_already_verified_user(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $target = User::factory()->create(['email' => 'already@example.com', 'role' => 'user']);

        $response = $this->postJson('/api/v1/admin/users/' . $target->id . '/verify');

        $response->assertStatus(200)->assertJsonPath('message', 'User is already verified.');
        $this->assertArrayNotHasKey('user', $response->json());
        Mail::assertNothingSent();
    }

    public function test_verify_for_unknown_user_returns_404(): void
    {
        $this->actingAs($this->admin());

        $response = $this->postJson('/api/v1/admin/users/999999/verify');

        $response->assertStatus(404);
    }

    public function test_verify_requires_admin(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user);

        $target = User::factory()->create(['role' => 'unverified']);

        $response = $this->postJson('/api/v1/admin/users/' . $target->id . '/verify');

        $response->assertStatus(403);
    }
}
