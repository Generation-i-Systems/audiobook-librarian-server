<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Mail\EmailOtpMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UserControllerOtpTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_edit_page_shows_send_login_email_and_qr_buttons_when_user_has_email(): void
    {
        $this->actingAs($this->admin());
        $target = User::factory()->create(['email' => 'edituser@example.com']);

        $response = $this->get('/admin/users/' . $target->id . '/edit');

        $response->assertOk();
        $response->assertSee('Send login email');
        $response->assertSee('Show QR code');
        $response->assertSee('login-qr-modal', false);
    }

    public function test_send_otp_route_delegates_and_flashes_success(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());
        $target = User::factory()->create(['email' => 'weblogin@example.com']);

        $response = $this->post('/admin/users/' . $target->id . '/send-otp');

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Mail::assertSent(EmailOtpMail::class, function (EmailOtpMail $mail) {
            return $mail->hasTo('weblogin@example.com');
        });
    }

    public function test_login_qr_route_returns_json_url(): void
    {
        $this->actingAs($this->admin());
        $target = User::factory()->create(['email' => 'webqr@example.com']);

        $response = $this->postJson('/admin/users/' . $target->id . '/login-qr');

        $response->assertStatus(200);
        $response->assertJsonStructure(['url', 'expires_in_seconds']);

        $token = basename((string) parse_url($response->json('url'), PHP_URL_PATH));
        $this->assertTrue(
            EmailOtp::where('magic_token_hash', hash('sha256', $token))->exists()
        );
    }

    public function test_admin_routes_require_admin_role(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user);

        $this->post('/admin/users/' . $user->id . '/send-otp')->assertStatus(403);
        $this->postJson('/admin/users/' . $user->id . '/login-qr')->assertStatus(403);
    }

    public function test_create_user_defaults_to_sending_otp_email_without_a_password(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $response = $this->post('/admin/users', [
            'name' => 'New Guy',
            'username' => 'newguy',
            'email' => 'newguy@example.com',
            'role' => 'user',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['email' => 'newguy@example.com', 'must_change_password' => true]);

        Mail::assertSent(EmailOtpMail::class, function (EmailOtpMail $mail) {
            return $mail->hasTo('newguy@example.com');
        });
    }

    public function test_create_user_requires_password_when_otp_email_unchecked(): void
    {
        $this->actingAs($this->admin());

        $response = $this->post('/admin/users', [
            'name' => 'Password User',
            'username' => 'pwuser',
            'email' => 'pwuser@example.com',
            'role' => 'user',
            'send_otp_email' => '0',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'pwuser@example.com']);
    }

    public function test_create_user_with_password_and_otp_unchecked_skips_email(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $response = $this->post('/admin/users', [
            'name' => 'Password User',
            'username' => 'pwuser2',
            'email' => 'pwuser2@example.com',
            'role' => 'user',
            'send_otp_email' => '0',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['email' => 'pwuser2@example.com', 'must_change_password' => false]);
        Mail::assertNothingSent();
    }
}
