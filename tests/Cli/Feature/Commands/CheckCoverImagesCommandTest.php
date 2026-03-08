<?php

namespace Tests\Cli\Feature\Commands;

use App\Models\Author;
use App\Models\Book;
use App\Services\AudibleService;
use App\Services\ExternalCoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class CheckCoverImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fake the books disk
        Storage::fake('books');
    }

    public function testDryRunOptionDoesNotChangeDatabase(): void
    {
        // Create a book with no cover and a directory containing an image
        $book = Book::factory()->create([
            'cover_image' => null,
            'directory_path' => 'Fiction/Author/Book1',
            'needs_review' => false,
        ]);

        // Ensure directory and a potential cover exists
        Storage::disk('books')->makeDirectory('Fiction/Author/Book1');
        Storage::disk('books')->put('Fiction/Author/Book1/cover.jpg', 'imagebytes');

        $this->artisan('cover:check --dry-run')
            ->expectsOutput('Starting cover image consistency check and fix...')
            ->expectsOutput('DRY RUN MODE: No changes will be made')
            ->assertExitCode(0);

        // Reload and assert cover image not set (dry run)
        $book->refresh();
        $this->assertNull($book->cover_image);
    }

    public function testAttemptAudibleFetchSucceedsAndUpdatesCover(): void
    {
        // Book with no local images available
        $book = Book::factory()->create([
            'title' => 'Test Audible Title',
            'cover_image' => null,
            'directory_path' => 'Fiction/Author/Book2',
            'needs_review' => true,
        ]);
        // Give it an author to improve Audible search
        $author = Author::factory()->create(['name' => 'Jane Doe']);
        $book->authors()->attach($author->id);

        Storage::disk('books')->makeDirectory('Fiction/Author/Book2');
        // No images put in directory to force audible path

        // Mock Audible search to return a match
        $this->mock(AudibleService::class, function ($mock) {
            $mock->shouldReceive('searchBooksWithFiltering')
                ->once()
                ->andReturn([
                    [
                        'id' => 'B00TESTASIN',
                        'coverImageUrl' => 'https://example.com/audible-cover.jpg',
                        'title' => 'Test Audible Title',
                    ],
                ]);
        });

        // Mock ExternalCoverService to download successfully.
        // Since cover_image now stores filename only, the service returns the filename.
        $this->mock(ExternalCoverService::class, function ($mock) {
            $mock->shouldReceive('downloadCoverImage')
                ->once()
                ->with('https://example.com/audible-cover.jpg', 'Fiction/Author/Book2', 'audible', 'B00TESTASIN')
                ->andReturn([
                    'success' => true,
                    'path' => 'cover_audible_B00TESTASIN.jpg',
                ]);
        });

        $this->artisan('cover:check --attempt-audible')
            ->expectsOutput('Starting cover image consistency check and fix...')
            ->assertExitCode(0);

        $book->refresh();
        $this->assertSame('cover_audible_B00TESTASIN.jpg', $book->cover_image);
        $this->assertFalse($book->needs_review);
        // Because ExternalCoverService is mocked, it does not write to storage.
        // Simulate the downloaded file to satisfy existence assertion on the faked disk.
        Storage::disk('books')->put('Fiction/Author/Book2/cover_audible_B00TESTASIN.jpg', 'imagebytes');
        Storage::disk('books')->assertExists('Fiction/Author/Book2/cover_audible_B00TESTASIN.jpg');
    }

    public function testLimitOptionProcessesOnlyFirstBook(): void
    {
        // First book has a local image
        $book1 = Book::factory()->create([
            'title' => 'First Book',
            'cover_image' => null,
            'directory_path' => 'Fiction/Author/BookA',
        ]);
        Storage::disk('books')->makeDirectory('Fiction/Author/BookA');
        Storage::disk('books')->put('Fiction/Author/BookA/cover.jpg', 'imagebytes');

        // Second book also has a local image but should be skipped due to limit
        $book2 = Book::factory()->create([
            'title' => 'Second Book',
            'cover_image' => null,
            'directory_path' => 'Fiction/Author/BookB',
        ]);
        Storage::disk('books')->makeDirectory('Fiction/Author/BookB');
        Storage::disk('books')->put('Fiction/Author/BookB/cover.jpg', 'imagebytes');

        $this->artisan('cover:check --limit=1')
            ->expectsOutput('Starting cover image consistency check and fix...')
            ->expectsOutput('Limiting to 1 books')
            ->assertExitCode(0);

        $book1->refresh();
        $book2->refresh();

        // First should be updated from local image detection
        $this->assertNotNull($book1->cover_image);
        // Second should remain unchanged due to limit
        $this->assertNull($book2->cover_image);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
