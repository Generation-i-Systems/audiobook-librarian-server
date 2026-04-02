<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use ReflectionMethod;
use Tests\TestCase;

class BookImportServiceCoverAndTagsTest extends TestCase
{
    private BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookImportService(
            app(GenreMappingService::class),
            app(\App\Services\SourceTrashService::class)
        );
    }

    private function callExtractMetadata(array $fileTags): array
    {
        $method = new ReflectionMethod(BookImportService::class, 'extractMetadataFromFileTags');
        return $method->invoke($this->service, $fileTags);
    }

    private function callPostProcess(array $aiResult, array $audiobook): array
    {
        $method = new ReflectionMethod(BookImportService::class, 'postProcessAIResult');
        return $method->invoke($this->service, $aiResult, $audiobook);
    }

    private function callFindCoverInSource(string $path): ?string
    {
        $method = new ReflectionMethod(BookImportService::class, 'findCoverInSourceDirectory');
        return $method->invoke($this->service, $path);
    }

    // --- extractMetadataFromFileTags: series from title tag ---

    public function testExtractsSeriesFromTitleTagWithBookPattern(): void
    {
        $fileTags = [
            'file.m4b' => [
                'title' => 'The Messenger, Book 01',
                'album' => 'The Messenger',
                'artist' => 'J.N. Chaney',
                'year' => '2016',
            ],
        ];
        $metadata = $this->callExtractMetadata($fileTags);

        $this->assertSame('The Messenger', $metadata['title']);
        $this->assertSame('The Messenger', $metadata['series']);
        $this->assertSame(1, $metadata['series_number']);
        $this->assertSame(['J.N. Chaney'], $metadata['author']);
        $this->assertSame(2016, $metadata['year']);
    }

    public function testExtractsDecimalSeriesNumberFromTitleTag(): void
    {
        $fileTags = [
            'file.m4b' => [
                'title' => 'Inheritance, Book 3.5',
                'album' => 'Inheritance',
                'artist' => 'Christopher Paolini',
            ],
        ];
        $metadata = $this->callExtractMetadata($fileTags);

        $this->assertSame('Inheritance', $metadata['series']);
        $this->assertSame(3.5, $metadata['series_number']);
    }

    public function testFallsBackToAlbumAsTitleWhenNoSeriesPatternInTitle(): void
    {
        $fileTags = [
            'file.m4b' => [
                'title' => 'The Warded Man',
                'album' => 'The Warded Man',
                'artist' => 'Peter V. Brett',
            ],
        ];
        $metadata = $this->callExtractMetadata($fileTags);

        $this->assertSame('The Warded Man', $metadata['title']);
        $this->assertArrayNotHasKey('series', $metadata);
    }

    public function testUsesAlbumAsTitleWhenNoTitleTag(): void
    {
        $fileTags = [
            'file.m4b' => [
                'album' => 'Dune',
                'artist' => 'Frank Herbert',
            ],
        ];
        $metadata = $this->callExtractMetadata($fileTags);

        $this->assertSame('Dune', $metadata['title']);
        $this->assertArrayNotHasKey('series', $metadata);
    }

    // --- postProcessAIResult: space-only directory separator ---

    public function testDirectoryNameWithSpaceOnlySeparatorExtractsSeriesNumber(): void
    {
        $aiResult = ['title' => 'The Messenger', 'series' => '', 'author' => []];
        $audiobook = ['path' => '/books/The Messenger/01 The Messenger'];

        $result = $this->callPostProcess($aiResult, $audiobook);

        $this->assertSame(1, $result['series_number']);
    }

    public function testDirectoryNameWithDashSeparatorStillWorks(): void
    {
        $aiResult = ['title' => 'The Messenger', 'series' => '', 'author' => []];
        $audiobook = ['path' => '/books/The Messenger/01 - The Messenger'];

        $result = $this->callPostProcess($aiResult, $audiobook);

        $this->assertSame(1, $result['series_number']);
    }

    public function testDirectoryNameSpaceSeparatorDoesNotMatchNonNumericPrefix(): void
    {
        $aiResult = ['title' => 'Some Title', 'author' => []];
        $audiobook = ['path' => '/books/Some Title'];

        $result = $this->callPostProcess($aiResult, $audiobook);

        $this->assertArrayNotHasKey('series_number', $result);
    }

    // --- findCoverInSourceDirectory ---

    public function testFindsStandardCoverJpg(): void
    {
        $dir = sys_get_temp_dir() . '/cover_test_' . uniqid();
        mkdir($dir);
        touch($dir . '/cover.jpg');

        try {
            $result = $this->callFindCoverInSource($dir);
            $this->assertSame($dir . '/cover.jpg', $result);
        } finally {
            unlink($dir . '/cover.jpg');
            rmdir($dir);
        }
    }

    public function testFallsBackToArbitraryJpgWhenNoCoverJpgExists(): void
    {
        $dir = sys_get_temp_dir() . '/cover_test_' . uniqid();
        mkdir($dir);
        touch($dir . '/The Messenger.jpg');

        try {
            $result = $this->callFindCoverInSource($dir);
            $this->assertSame($dir . '/The Messenger.jpg', $result);
        } finally {
            unlink($dir . '/The Messenger.jpg');
            rmdir($dir);
        }
    }

    public function testPrefersStandardCoverOverArbitraryJpg(): void
    {
        $dir = sys_get_temp_dir() . '/cover_test_' . uniqid();
        mkdir($dir);
        touch($dir . '/The Messenger.jpg');
        touch($dir . '/cover.jpg');

        try {
            $result = $this->callFindCoverInSource($dir);
            $this->assertSame($dir . '/cover.jpg', $result);
        } finally {
            unlink($dir . '/The Messenger.jpg');
            unlink($dir . '/cover.jpg');
            rmdir($dir);
        }
    }

    public function testReturnsNullWhenNoImageExists(): void
    {
        $dir = sys_get_temp_dir() . '/cover_test_' . uniqid();
        mkdir($dir);
        touch($dir . '/audiobook.m4b');

        try {
            $result = $this->callFindCoverInSource($dir);
            $this->assertNull($result);
        } finally {
            unlink($dir . '/audiobook.m4b');
            rmdir($dir);
        }
    }

    public function testReturnsNullForNonExistentDirectory(): void
    {
        $result = $this->callFindCoverInSource('/nonexistent/path/that/does/not/exist');
        $this->assertNull($result);
    }
}
