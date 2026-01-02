<?php

namespace Tests\Feature\Admin;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\TestCase;

class BookControllerNeedsReviewTest extends TestCase
{
    use RefreshDatabase;
    use WithoutMiddleware;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function uncheckingAllReasonssClearsNeedsReviewFlag(): void
    {
        // Create a book with needs_review flag and reasons
        $book = Book::factory()->create([
            'title' => 'Test Book',
            'needs_review' => true,
            'needs_review_reasons' => ['invalid directoryPath', 'missing cover'],
        ]);

        // Submit form with all checkboxes unchecked (empty needsReviewReasons array)
        $response = $this->actingAs($this->user)->put(route('admin.books.update', $book->id), [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'genre' => ['Test Genre'],
            'needsReviewPresent' => '1',
            'needsReviewReasons' => [], // All unchecked
        ]);

        $response->assertRedirect();

        // Verify needs_review is cleared
        $book->refresh();
        $this->assertFalse($book->needs_review);
        $this->assertEmpty($book->needs_review_reasons);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function keepingSomeReasonsPreservesNeedsReviewFlag(): void
    {
        // Create a book with needs_review flag and multiple reasons
        $book = Book::factory()->create([
            'title' => 'Test Book',
            'needs_review' => true,
            'needs_review_reasons' => ['invalid directoryPath', 'missing cover', 'wrong author'],
        ]);

        // Submit form with only some checkboxes checked
        $response = $this->actingAs($this->user)->put(route('admin.books.update', $book->id), [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'genre' => ['Test Genre'],
            'needsReviewPresent' => '1',
            'needsReviewReasons' => ['missing cover'], // Only one kept
        ]);

        $response->assertRedirect();

        // Verify needs_review is still true with updated reasons
        $book->refresh();
        $this->assertTrue($book->needs_review);
        $this->assertEquals(['missing cover'], $book->needs_review_reasons);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function booksWithoutNeedsReviewAreNotAffected(): void
    {
        // Create a book without needs_review flag
        $book = Book::factory()->create([
            'title' => 'Normal Book',
            'needs_review' => false,
            'needs_review_reasons' => null,
        ]);

        // Submit form without needsReviewPresent (form doesn't show the section)
        $response = $this->actingAs($this->user)->put(route('admin.books.update', $book->id), [
            'title' => 'Updated Book',
        ]);

        $response->assertRedirect();

        // Verify needs_review remains false
        $book->refresh();
        $this->assertFalse($book->needs_review);
        $this->assertNull($book->needs_review_reasons);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function clearNeedsReviewCheckboxClearsFlag(): void
    {
        // Create a book with needs_review but no reasons
        $book = Book::factory()->create([
            'title' => 'Test Book',
            'needs_review' => true,
            'needs_review_reasons' => null,
        ]);

        // Submit form with clearNeedsReview checkbox checked
        $response = $this->actingAs($this->user)->put(route('admin.books.update', $book->id), [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'genre' => ['Test Genre'],
            'needsReviewPresent' => '1',
            'clearNeedsReview' => '1',
        ]);

        $response->assertRedirect();

        // Verify needs_review is cleared
        $book->refresh();
        $this->assertFalse($book->needs_review);
        $this->assertEmpty($book->needs_review_reasons);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function invalidReasonsAreFilteredOut(): void
    {
        // Create a book with needs_review flag
        $book = Book::factory()->create([
            'title' => 'Test Book',
            'needs_review' => true,
            'needs_review_reasons' => ['valid reason'],
        ]);

        // Try to submit with invalid/malicious reasons
        $response = $this->actingAs($this->user)->put(route('admin.books.update', $book->id), [
            'title' => 'Test Book',
            'needsReviewPresent' => '1',
            'needsReviewReasons' => [
                'valid reason',
                '<script>alert("xss")</script>',
                '',
                null,
            ],
        ]);

        $response->assertRedirect();

        // Verify only valid, non-empty reasons are kept
        $book->refresh();
        $this->assertTrue($book->needs_review);
        $this->assertIsArray($book->needs_review_reasons);
        $this->assertContains('valid reason', $book->needs_review_reasons);
    }
}
