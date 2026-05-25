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
}
