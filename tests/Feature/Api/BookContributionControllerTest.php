<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Mail\BookContributionSubmittedMail;
use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookContributionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_emails_each_admin_but_not_regular_users(): void
    {
        Mail::fake();
        $submitter = User::factory()->create(['email' => 'submitter@example.com', 'role' => 'library-user']);
        $admin = User::factory()->create(['email' => 'admin@example.com', 'role' => 'admin']);
        $superAdmin = User::factory()->create(['email' => 'super-admin@example.com', 'role' => 'super-admin']);
        User::factory()->create(['email' => 'reader@example.com', 'role' => 'user']);
        $book = Book::factory()->create(['title' => 'Original Title']);

        Sanctum::actingAs($submitter);

        $response = $this->postJson('/api/v1/books/' . $book->id . '/contributions', [
            'changes' => [
                'title' => ['original' => 'Original Title', 'new' => 'Corrected Title'],
            ],
        ]);

        $response->assertCreated();
        Mail::assertSent(BookContributionSubmittedMail::class, 2);
        Mail::assertSent(BookContributionSubmittedMail::class, function (BookContributionSubmittedMail $mail) use ($admin): bool {
            return $mail->hasTo($admin->email);
        });
        Mail::assertSent(BookContributionSubmittedMail::class, function (BookContributionSubmittedMail $mail) use ($superAdmin): bool {
            return $mail->hasTo($superAdmin->email);
        });
    }
}
