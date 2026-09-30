<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Mail\BookContributionSubmittedMail;
use App\Models\Book;
use App\Models\BookContribution;
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

    public function test_rejects_unsupported_contribution_fields(): void
    {
        $submitter = User::factory()->create(['role' => 'library-user']);
        $book = Book::factory()->create();
        Sanctum::actingAs($submitter);

        $this->postJson("/api/v1/books/{$book->id}/contributions", [
            'changes' => [
                'directory_path' => ['original' => 'old', 'new' => 'new'],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('changes');
    }

    public function test_admin_approval_applies_a_current_contribution(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $book = Book::factory()->create(['title' => 'Original Title']);
        $contribution = BookContribution::create([
            'book_id' => $book->id,
            'user_id' => User::factory()->create()->id,
            'changes' => [
                'title' => ['original' => 'Original Title', 'new' => 'Corrected Title'],
            ],
            'status' => 'pending',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/contributions/{$contribution->id}/approve", [
            'reviewer_notes' => 'Confirmed against the cover.',
        ])->assertOk();

        $this->assertDatabaseHas('books', ['id' => $book->id, 'title' => 'Corrected Title']);
        $this->assertDatabaseHas('book_contributions', [
            'id' => $contribution->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);
    }

    public function test_admin_approval_refuses_a_stale_contribution(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $book = Book::factory()->create(['title' => 'Newer Title']);
        $contribution = BookContribution::create([
            'book_id' => $book->id,
            'user_id' => User::factory()->create()->id,
            'changes' => [
                'title' => ['original' => 'Original Title', 'new' => 'Suggested Title'],
            ],
            'status' => 'pending',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/contributions/{$contribution->id}/approve")
            ->assertConflict()
            ->assertJsonPath('stale_fields.0', 'title');

        $this->assertDatabaseHas('books', ['id' => $book->id, 'title' => 'Newer Title']);
        $this->assertDatabaseHas('book_contributions', ['id' => $contribution->id, 'status' => 'pending']);
    }
}
