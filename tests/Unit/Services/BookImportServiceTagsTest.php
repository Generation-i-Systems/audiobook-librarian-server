<?php

namespace Tests\Unit\Services;

use App\Models\BookTag;
use App\Services\BookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceTagsTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BookImportService::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createBookFromMetadataStoresConfirmedTagsAsSystemScope(): void
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'genre' => 'Romance',
            'tags' => ['Spicy', 'RH'],
        ];

        $book = $this->service->createBookFromMetadata($metadata, ['path' => '/test/path', 'files' => []]);

        $this->assertNotNull($book);
        $bookTag = BookTag::query()->where('book_id', $book->id)->where('owner_key', 'system')->first();
        $this->assertNotNull($bookTag);
        $this->assertSame('system', $bookTag->scope);
        $this->assertSame(['Spicy', 'RH'], $bookTag->tags);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createBookFromMetadataWithoutTagsDoesNotCreateBookTagRow(): void
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'genre' => 'Romance',
        ];

        $book = $this->service->createBookFromMetadata($metadata, ['path' => '/test/path', 'files' => []]);

        $this->assertNotNull($book);
        $this->assertSame(0, BookTag::query()->where('book_id', $book->id)->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookFromMetadataDeduplicatesTagsCaseInsensitively(): void
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'genre' => 'Romance',
            'tags' => ['spicy', 'Spicy', 'litrpg'],
        ];

        $book = $this->service->createBookFromMetadata($metadata, ['path' => '/test/path', 'files' => []]);

        $updateMetadata = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'genre' => 'Romance',
            'tags' => ['spicy', 'dark romance'],
        ];
        $updated = $this->service->updateBookFromMetadata($book, $updateMetadata, ['path' => '/test/path', 'files' => []]);

        $bookTag = BookTag::query()->where('book_id', $updated->id)->where('owner_key', 'system')->first();
        $this->assertSame(['spicy', 'dark romance'], $bookTag->tags);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function displayEnrichedMetadataIncludesTagsRow(): void
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'narrator' => [],
            'genre' => ['Romance'],
            'tags' => ['Spicy'],
            'publisher' => '',
            'confidence' => 90,
        ];

        $capturedRows = null;
        $this->service->displayEnrichedMetadata(
            $metadata,
            function ($headers, $rows) use (&$capturedRows): void {
                $capturedRows = $rows;
            }
        );

        $this->assertContains(['Tags', 'Spicy'], $capturedRows);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function buildMetadataFromBookIncludesExistingSystemTags(): void
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'genre' => 'Romance',
            'tags' => ['Spicy'],
        ];

        $book = $this->service->createBookFromMetadata($metadata, ['path' => '/test/path', 'files' => []]);

        $rebuilt = $this->service->buildMetadataFromBook($book->fresh());

        $this->assertSame(['Spicy'], $rebuilt['tags']);
    }
}
