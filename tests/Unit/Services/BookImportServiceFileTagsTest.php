<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceFileTagsTest extends TestCase
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
    public function extractMetadataFromFileTagsSetsGenreAsArray(): void
    {
        $fileTags = [
            'track1.mp3' => [
                'album' => 'Test Book',
                'genre' => 'Fantasy',
            ],
        ];

        $result = $this->service->extractMetadataFromFileTags($fileTags);

        $this->assertEquals(['Fantasy'], $result['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractMetadataFromFileTagsSetsAuthorAsArray(): void
    {
        $fileTags = [
            'track1.mp3' => [
                'artist' => 'Brandon Sanderson',
            ],
        ];

        $result = $this->service->extractMetadataFromFileTags($fileTags);

        $this->assertEquals(['Brandon Sanderson'], $result['author']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractMetadataFromFileTagsSetsNarratorFromWriter(): void
    {
        $fileTags = [
            'track1.mp3' => [
                'album' => 'Test Book',
                'writer' => 'Michael Kramer, Kate Reading',
            ],
        ];

        $result = $this->service->extractMetadataFromFileTags($fileTags);

        $this->assertEquals(['Michael Kramer', 'Kate Reading'], $result['narrator']);
    }
}
