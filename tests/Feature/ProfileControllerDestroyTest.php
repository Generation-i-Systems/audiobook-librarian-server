<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AccountDeletionScheduledMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileControllerDestroyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_cannot_delete_an_account(): void
    {
        $response = $this->delete('/profile', ['confirm_email' => 'nobody@example.com']);

        $response->assertRedirect('/login');
    }

    #[Test]
    public function wrong_confirmation_email_does_not_delete_the_account(): void
    {
        $user = User::create([
            'name' => 'Keep Me',
            'username' => 'keepme' . uniqid('', true),
            'email' => 'keepme' . uniqid('', true) . '@example.com',
            'password' => Hash::make('password'),
            'role' => 'library-user',
        ]);

        $response = $this->actingAs($user)->from('/profile')
            ->delete('/profile', ['confirm_email' => 'not-my-email@example.com']);

        $response->assertRedirect('/profile');
        $response->assertSessionHasErrors('confirm_email');
        $this->assertNull(User::find($user->id)->deleted_at);
    }

    #[Test]
    public function authenticated_user_can_delete_their_account_via_the_web(): void
    {
        Mail::fake();

        $user = User::create([
            'name' => 'Delete Me Web',
            'username' => 'deleteweb' . uniqid('', true),
            'email' => 'deleteweb' . uniqid('', true) . '@example.com',
            'password' => Hash::make('password'),
            'role' => 'library-user',
        ]);

        $response = $this->actingAs($user)
            ->delete('/profile', ['confirm_email' => $user->email]);

        $response->assertRedirect('/account-deletion/scheduled');

        $deletedUser = User::withTrashed()->findOrFail($user->id);
        $this->assertNotNull($deletedUser->deleted_at);
        $this->assertEquals(
            now()->addDays(30)->toDateString(),
            $deletedUser->deletion_scheduled_for->toDateString(),
        );

        Mail::assertSent(AccountDeletionScheduledMail::class, function (AccountDeletionScheduledMail $mail) use ($user): bool {
            return $mail->hasTo($user->email);
        });

        // Session no longer authenticated after deletion.
        $this->assertGuest();
    }
}
