<?php

namespace Tests\Unit\Services;

use App\Models\PendingDownload;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceBundleTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $genreMappingService = $this->app->make(GenreMappingService::class);
        $sourceTrashService = $this->app->make(\App\Services\SourceTrashService::class);
        $this->service = new BookImportService($genreMappingService, $sourceTrashService);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createAdditionalBundleBookSharesDirectoryAndFlagsForReview(): void
    {
        $pendingDownload = PendingDownload::create([
            'magnet_uri' => 'magnet:?xt=urn:btih:ABCDEF',
            'abb_url' => 'https://audiobookbay.example/post/bundle',
            'release_name' => 'Series Bundle',
            'book_count' => 2,
            'status' => 'pending',
        ]);

        $siblingPendingBook = $pendingDownload->books()->create([
            'sort_order' => 1,
            'title' => 'Second Book In Bundle',
            'authors' => ['Jane Author'],
            'genre' => 'Fantasy',
            'series_name' => 'Great Series',
            'series_number' => '2',
        ]);

        $book = $this->service->createAdditionalBundleBook($siblingPendingBook, 'Fantasy/Jane Author/Great Series/Book 1');

        $this->assertSame('Second Book In Bundle', $book->title);
        $this->assertSame('Fantasy/Jane Author/Great Series/Book 1', $book->directory_path);
        $this->assertTrue($book->needs_review);
        $this->assertContains('Jane Author', $book->authors->pluck('name'));
        $this->assertSame('Great Series', $book->series->first()->name);
    }
}
