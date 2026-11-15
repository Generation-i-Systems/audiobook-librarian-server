<?php

declare(strict_types=1);

namespace Tests\Web\Feature;

use App\Models\Book;
use App\Models\BookTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookIndexTagsViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->create(['role' => 'library-user']);
        $this->actingAs($this->user);
    }

    #[Test]
    public function everyBookRowIncludesTagsRowSpanningTitleThroughLength(): void
    {
        $bookWithTags = Book::factory()->create(['title' => 'Book With Tags']);
        BookTag::create([
            'book_id' => $bookWithTags->id,
            'scope' => 'system',
            'owner_key' => 'system',
            'tags' => ['cyberpunk', 'ai'],
        ]);

        $bookWithoutTags = Book::factory()->create(['title' => 'Book Without Tags']);

        $response = $this->get(route('books.index', ['view_type' => 'list']));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertIsString($html);

        // Verify colspan="6" tags rows exist for every book row
        $this->assertSame(2, substr_count($html, 'class="book-tags-row"'));
        $this->assertSame(2, substr_count($html, 'colspan="6"'));

        // Verify tags appear and link to tag filter
        $this->assertStringContainsString('cyberpunk', $html);
        $this->assertStringContainsString('ai', $html);
        $this->assertStringContainsString(e(route('books.index', ['tag' => 'cyberpunk'])), $html);
        $this->assertStringContainsString(e(route('books.index', ['tag' => 'ai'])), $html);
    }
}
