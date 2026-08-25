<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookImportServiceEmbeddedCoverTempPathTest extends TestCase
{
    #[Test]
    public function repeated_calls_with_the_same_cover_data_reuse_the_same_temp_file(): void
    {
        $service = $this->makeService();
        $coverData = base64_encode('fake-png-bytes-for-cover-a');

        $first = $service->getEmbeddedCoverTempPath($coverData);
        $second = $service->getEmbeddedCoverTempPath($coverData);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertFileExists($first);

        @unlink($first);
    }

    #[Test]
    public function different_cover_data_produces_different_temp_files(): void
    {
        $service = $this->makeService();

        $pathA = $service->getEmbeddedCoverTempPath(base64_encode('cover-a-bytes'));
        $pathB = $service->getEmbeddedCoverTempPath(base64_encode('cover-b-bytes'));

        $this->assertNotSame($pathA, $pathB);

        @unlink((string) $pathA);
        @unlink((string) $pathB);
    }

    #[Test]
    public function a_deleted_temp_file_is_recreated_instead_of_returning_a_stale_path(): void
    {
        $service = $this->makeService();
        $coverData = base64_encode('fake-png-bytes-for-cover-c');

        $first = $service->getEmbeddedCoverTempPath($coverData);
        $this->assertNotNull($first);
        unlink($first);

        $second = $service->getEmbeddedCoverTempPath($coverData);

        $this->assertNotNull($second);
        $this->assertFileExists($second);

        @unlink($second);
    }

    protected function makeService(): BookImportService
    {
        $genreMapping = app(GenreMappingService::class);
        $sourceTrashService = app(SourceTrashService::class);

        return new BookImportService($genreMapping, $sourceTrashService);
    }
}
