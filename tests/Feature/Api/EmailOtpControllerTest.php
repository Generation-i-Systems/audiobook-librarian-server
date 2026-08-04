<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Mail\EmailOtpMail;
use App\Mail\RegistrationInvitationMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailOtpControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_otp_for_existing_user_sends_otp_email_and_creates_record(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/otp/request', [
            'email' => 'existing@example.com',
            'allow_signup' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'If an account is associated with that email, a sign-in code has been sent.',
        ]);

        $this->assertDatabaseHas('email_otps', [
            'email' => 'existing@example.com',
            'allow_signup' => 1,
            'attempts' => 0,
        ]);

        Mail::assertSent(EmailOtpMail::class, function (EmailOtpMail $mail) {
            return $mail->hasTo('existing@example.com');
        });

        Mail::assertNotSent(RegistrationInvitationMail::class);
    }

    public function test_request_otp_for_non_existent_user_sends_registration_invitation(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/otp/request', [
            'email' => 'newuser@example.com',
            'allow_signup' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'If an account is associated with that email, a sign-in code has been sent.',
        ]);

        $this->assertDatabaseMissing('email_otps', [
            'email' => 'newuser@example.com',
        ]);

        Mail::assertSent(RegistrationInvitationMail::class, function (RegistrationInvitationMail $mail) {
            return $mail->hasTo('newuser@example.com');
        });

        Mail::assertNotSent(EmailOtpMail::class);
    }

    public function test_verify_otp_with_valid_code_creates_user_if_allowed(): void
    {
        $code = '123456';
        $codeHash = hash('sha256', $code);
        $magicToken = bin2hex(random_bytes(32));
        $magicTokenHash = hash('sha256', $magicToken);

        $otp = EmailOtp::create([
            'email' => 'newuser@example.com',
            'code_hash' => $codeHash,
            'magic_token_hash' => $magicTokenHash,
            'allow_signup' => true,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'newuser@example.com',
            'code' => $code,
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'code' => 'ACCOUNT_PENDING_APPROVAL',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'role' => 'unverified',
        ]);

        $this->assertNotNull($otp->fresh()->used_at);
    }

    public function test_verify_otp_with_invalid_code_increments_attempts(): void
    {
        $code = '123456';
        $codeHash = hash('sha256', $code);

        $otp = EmailOtp::create([
            'email' => 'test@example.com',
            'code_hash' => $codeHash,
            'magic_token_hash' => hash('sha256', 'magic'),
            'allow_signup' => false,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'test@example.com',
            'code' => '999999',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'Invalid code.',
        ]);

        $this->assertEquals(1, $otp->fresh()->attempts);
        $this->assertNull($otp->fresh()->used_at);
    }

    public function test_verify_otp_with_valid_token_logs_in_existing_user(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'role' => 'library-user',
        ]);

        $magicToken = bin2hex(random_bytes(32));
        $magicTokenHash = hash('sha256', $magicToken);

        $otp = EmailOtp::create([
            'email' => 'existing@example.com',
            'code_hash' => hash('sha256', '123456'),
            'magic_token_hash' => $magicTokenHash,
            'allow_signup' => false,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'token' => $magicToken,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'id',
            'email',
            'token',
        ]);

        $this->assertNotNull($otp->fresh()->used_at);
    }

    public function test_verify_code_web_with_valid_code_logs_in_user_and_returns_redirect(): void
    {
        $user = User::factory()->create([
            'email' => 'webcode@example.com',
            'role' => 'library-user',
        ]);

        $code = '654321';
        $otp = EmailOtp::create([
            'email' => 'webcode@example.com',
            'code_hash' => hash('sha256', $code),
            'magic_token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'allow_signup' => false,
            'type' => 'login',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/auth/otp/verify', [
            'email' => 'webcode@example.com',
            'code' => $code,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['redirect' => '/']);

        $this->assertNotNull($otp->fresh()->used_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_verify_code_web_with_invalid_code_increments_attempts(): void
    {
        EmailOtp::create([
            'email' => 'webcode2@example.com',
            'code_hash' => hash('sha256', '111111'),
            'magic_token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'allow_signup' => false,
            'type' => 'login',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/auth/otp/verify', [
            'email' => 'webcode2@example.com',
            'code' => '999999',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Invalid code.']);
        $this->assertGuest();

        $this->assertDatabaseHas('email_otps', [
            'email' => 'webcode2@example.com',
            'attempts' => 1,
        ]);
    }

    public function test_verify_code_web_with_no_active_otp_returns_400(): void
    {
        $response = $this->postJson('/auth/otp/verify', [
            'email' => 'nootp@example.com',
            'code' => '123456',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Invalid or expired code.']);
        $this->assertGuest();
    }

    public function test_verify_code_web_for_unverified_user_returns_403(): void
    {
        User::factory()->create([
            'email' => 'pending@example.com',
            'role' => 'unverified',
        ]);

        $code = '222222';
        EmailOtp::create([
            'email' => 'pending@example.com',
            'code_hash' => hash('sha256', $code),
            'magic_token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'allow_signup' => false,
            'type' => 'login',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->postJson('/auth/otp/verify', [
            'email' => 'pending@example.com',
            'code' => $code,
        ]);

        $response->assertStatus(403);
        $this->assertGuest();
    }

    public function test_magic_landing_page_includes_api_url_in_deep_links_for_self_hosted_server(): void
    {
        $token = bin2hex(random_bytes(32));
        EmailOtp::create([
            'email' => 'deeplink@example.com',
            'code_hash' => hash('sha256', '000000'),
            'magic_token_hash' => hash('sha256', $token),
            'allow_signup' => false,
            'type' => 'login',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->get('https://self-hosted.example.test/auth/magic/' . $token);

        $response->assertOk();
        $apiUrl = rawurlencode('https://self-hosted.example.test/api/v1');
        $response->assertSee('ablibrarian://auth/magic?token=' . $token . '&amp;apiUrl=' . $apiUrl, false);
        $response->assertSee('ablibrarian-library://auth/magic?token=' . $token . '&amp;apiUrl=' . $apiUrl, false);
    }
}
