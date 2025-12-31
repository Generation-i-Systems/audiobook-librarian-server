<?php

namespace Tests\Import\Unit\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use Tests\TestCase;

class ImportBooksFromDownloadsTagMetadataMergeTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function tagMetadataFromM4bWriterAndGenreFillsMissingFields(): void
    {
        $command = new ImportBooksFromDownloadsTagMetadataMergeTestDouble();

        $fileTags = [
            'Book.m4b' => [
                'album' => 'Kingdom Come',
                'artist' => 'Mark Waid, Alex Ross',
                'genre' => 'Science Fiction & Fantasy / Science Fiction / Adventure',
                'writer' => 'Edoardo Ballerini, Kerry Shale, Marc Thompson, Full Cast',
                'year' => '2025',
            ],
        ];

        $tagMetadata = $command->exposeExtractMetadataFromFileTags($fileTags);

        $this->assertSame(['Science Fiction & Fantasy / Science Fiction / Adventure'], $tagMetadata['genre']);
        $this->assertSame(['Edoardo Ballerini', 'Kerry Shale', 'Marc Thompson'], $tagMetadata['narrator']);

        $merged = $command->exposeMergeMetadataFillMissing([
            'title' => 'Kingdom Come',
            'author' => ['Mark Waid'],
            'narrator' => [],
            'genre' => [],
        ], $tagMetadata);

        $this->assertSame(['Science Fiction & Fantasy / Science Fiction / Adventure'], $merged['genre']);
        $this->assertSame(['Edoardo Ballerini', 'Kerry Shale', 'Marc Thompson'], $merged['narrator']);

        $merged2 = $command->exposeMergeMetadataFillMissing([
            'genre' => ['Fantasy'],
            'narrator' => ['Someone Else'],
        ], $tagMetadata);

        $this->assertSame(['Fantasy'], $merged2['genre']);
        $this->assertSame(['Someone Else'], $merged2['narrator']);
    }
}

class ImportBooksFromDownloadsTagMetadataMergeTestDouble extends ImportBooksFromDownloads
{
    public function __construct()
    {
        parent::__construct(null);
    }

    public function exposeExtractMetadataFromFileTags(array $fileTags): array
    {
        return $this->extractMetadataFromFileTags($fileTags);
    }

    public function exposeMergeMetadataFillMissing(array $base, array $fill): array
    {
        return $this->mergeMetadataFillMissing($base, $fill);
    }
}
