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

class BookContributionNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function testSubmissionEmailsEveryAdminAndSuperAdmin(): void
    {
        Mail::fake();

        $submitter = User::factory()->create(['role' => 'library-user']);
        $admin = User::factory()->create(['role' => 'admin']);
        $superAdmin = User::factory()->create(['role' => 'super-admin']);
        $nonAdmin = User::factory()->create(['role' => 'user']);
        $book = Book::factory()->create(['title' => 'Original title']);

        Sanctum::actingAs($submitter);

        $this->postJson("/api/v1/books/{$book->id}/contributions", [
            'changes' => [
                'title' => ['original' => 'Original title', 'new' => 'Corrected title'],
            ],
        ])->assertCreated();

        Mail::assertSent(BookContributionSubmittedMail::class, 2);
        Mail::assertSent(BookContributionSubmittedMail::class, function (BookContributionSubmittedMail $mail) use ($admin): bool {
            return $mail->hasTo($admin->email);
        });
        Mail::assertSent(BookContributionSubmittedMail::class, function (BookContributionSubmittedMail $mail) use ($superAdmin): bool {
            return $mail->hasTo($superAdmin->email);
        });
        Mail::assertNotSent(BookContributionSubmittedMail::class, function (BookContributionSubmittedMail $mail) use ($nonAdmin): bool {
            return $mail->hasTo($nonAdmin->email);
        });
    }
}
