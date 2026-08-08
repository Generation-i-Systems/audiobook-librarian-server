<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceDirectoryExistingPrefixTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $genreMappingService = $this->app->make(GenreMappingService::class);
        $sourceTrashService = $this->app->make(SourceTrashService::class);
        $this->service = new BookImportService($genreMappingService, $sourceTrashService);
    }

    private function createTempBookRoot(): string
    {
        $path = sys_get_temp_dir() . '/book_root_' . uniqid('', true);
        File::makeDirectory($path, 0775, true);
        config(['app.book_root' => $path]);
        config(['filesystems.disks.books.root' => $path]);

        return $path;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function marksOnlyTheSegmentsThatAlreadyExistOnDisk(): void
    {
        $bookRoot = $this->createTempBookRoot();
        File::makeDirectory(
            $bookRoot . '/Fantasy/J.M. Clarke & C.J. Thompson/Rune Seeker',
            0775,
            true
        );

        $uiMetadata = $this->service->buildUiMetadata(
            ['title' => 'Placeholder'],
            null,
            fn ($metadata, $options) => 'Fantasy/J.M. Clarke & C.J. Thompson/Rune Seeker/04 Rune Seeker 4'
        );

        $this->assertSame(
            'Fantasy/J.M. Clarke & C.J. Thompson/Rune Seeker/',
            $uiMetadata['directory_existing_prefix']
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returnsEmptyStringWhenNothingInThePathExistsYet(): void
    {
        $this->createTempBookRoot();

        $uiMetadata = $this->service->buildUiMetadata(
            ['title' => 'Placeholder'],
            null,
            fn ($metadata, $options) => 'Horror/Brand New Author/Brand New Series/01 Title'
        );

        $this->assertSame('', $uiMetadata['directory_existing_prefix']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returnsTheFullPathWithTrailingSlashWhenEverythingAlreadyExists(): void
    {
        $bookRoot = $this->createTempBookRoot();
        File::makeDirectory($bookRoot . '/Fantasy/Author/Series/01 Title', 0775, true);

        $uiMetadata = $this->service->buildUiMetadata(
            ['title' => 'Placeholder'],
            null,
            fn ($metadata, $options) => 'Fantasy/Author/Series/01 Title'
        );

        $this->assertSame('Fantasy/Author/Series/01 Title/', $uiMetadata['directory_existing_prefix']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNotSetTheKeyWhenNoDirectoryPathCallbackIsProvided(): void
    {
        $this->createTempBookRoot();

        $uiMetadata = $this->service->buildUiMetadata(['title' => 'Placeholder']);

        $this->assertArrayNotHasKey('directory_existing_prefix', $uiMetadata);
    }
}
