<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookImportServiceCoverPriorityTest extends TestCase
{
    #[Test]
    public function audible_cover_beats_google_and_generic_cover_url(): void
    {
        $service = $this->makeService();

        $metadata = [
            'cover_url' => 'https://images.google.com/generic.jpg',
            'audible_raw' => [
                'coverImageUrl' => 'https://m.media-amazon.com/images/audible.jpg',
            ],
            'google_books_raw' => [
                'coverImageUrl' => 'https://books.google.com/google.jpg',
            ],
        ];

        [$url, $source] = $service->exposeSelectBestCoverUrl($metadata);

        $this->assertSame('https://m.media-amazon.com/images/audible.jpg', $url);
        $this->assertSame('audible', $source);
    }

    #[Test]
    public function local_cover_flag_beats_audible(): void
    {
        $service = $this->makeService();

        $metadata = [
            'cover_url' => 'file:///tmp/cover.png',
            'cover_is_local_file' => true,
            'audible_raw' => [
                'coverImageUrl' => 'https://m.media-amazon.com/images/audible.jpg',
            ],
        ];

        [$url, $source] = $service->exposeSelectBestCoverUrl($metadata);

        $this->assertSame('file:///tmp/cover.png', $url);
        $this->assertSame('local', $source);
    }

    #[Test]
    public function google_cover_used_when_no_audible(): void
    {
        $service = $this->makeService();

        $metadata = [
            'google_books_raw' => [
                'imageLinks' => [
                    'thumbnail' => 'https://books.google.com/thumbnail.jpg',
                ],
            ],
        ];

        [$url, $source] = $service->exposeSelectBestCoverUrl($metadata);

        $this->assertSame('https://books.google.com/thumbnail.jpg', $url);
        $this->assertSame('googlebooks', $source);
    }

    #[Test]
    public function falls_back_to_unknown_cover_url(): void
    {
        $service = $this->makeService();

        $metadata = [
            'cover_url' => 'https://example.com/fallback.jpg',
        ];

        [$url, $source] = $service->exposeSelectBestCoverUrl($metadata);

        $this->assertSame('https://example.com/fallback.jpg', $url);
        $this->assertSame('unknown', $source);
    }

    protected function makeService(): BookImportServiceTestDouble
    {
        $genreMapping = app(GenreMappingService::class);
        $sourceTrashService = app(\App\Services\SourceTrashService::class);

        return new BookImportServiceTestDouble($genreMapping, $sourceTrashService);
    }
}

class BookImportServiceTestDouble extends BookImportService
{
    public function exposeSelectBestCoverUrl(array $metadata): array
    {
        return $this->selectBestCoverUrl($metadata);
    }
}
