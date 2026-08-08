<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceAlternateSeriesDirectoryTest extends TestCase
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
    public function findsSameSeriesUnderADifferentGenreAndCountsItsBooks(): void
    {
        $bookRoot = $this->createTempBookRoot();
        File::makeDirectory($bookRoot . '/LitRPG/J.M. Clarke & C.J. Thompson/Rune Seeker/01 Rune Seeker', 0775, true);
        File::makeDirectory($bookRoot . '/LitRPG/J.M. Clarke & C.J. Thompson/Rune Seeker/02 Rune Seeker 2', 0775, true);

        $alternates = $this->service->findAlternateSeriesDirectories(
            ['J.M. Clarke', 'C.J. Thompson'],
            'Rune Seeker',
            'Fantasy',
            'J.M. Clarke & C.J. Thompson'
        );

        $this->assertCount(1, $alternates);
        $this->assertSame('LitRPG/J.M. Clarke & C.J. Thompson/Rune Seeker', $alternates[0]['relative_path']);
        $this->assertSame(2, $alternates[0]['book_count']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findsSameSeriesUnderASubsetOfTheCurrentAuthors(): void
    {
        $bookRoot = $this->createTempBookRoot();
        File::makeDirectory($bookRoot . '/Fantasy/J.M. Clarke/Rune Seeker/01 Rune Seeker', 0775, true);

        $alternates = $this->service->findAlternateSeriesDirectories(
            ['J.M. Clarke', 'C.J. Thompson'],
            'Rune Seeker',
            'Fantasy',
            'J.M. Clarke & C.J. Thompson'
        );

        $this->assertCount(1, $alternates);
        $this->assertSame('Fantasy/J.M. Clarke/Rune Seeker', $alternates[0]['relative_path']);
        $this->assertSame(1, $alternates[0]['book_count']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function excludesTheExpectedGenreAuthorComboItself(): void
    {
        $bookRoot = $this->createTempBookRoot();
        File::makeDirectory($bookRoot . '/Fantasy/J.M. Clarke & C.J. Thompson/Rune Seeker/04 Rune Seeker 4', 0775, true);

        $alternates = $this->service->findAlternateSeriesDirectories(
            ['J.M. Clarke', 'C.J. Thompson'],
            'Rune Seeker',
            'Fantasy',
            'J.M. Clarke & C.J. Thompson'
        );

        $this->assertSame([], $alternates);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function ignoresASameNamedSeriesByCompletelyUnrelatedAuthors(): void
    {
        $bookRoot = $this->createTempBookRoot();
        File::makeDirectory($bookRoot . '/Fantasy/Someone Else/Rune Seeker/01 Rune Seeker', 0775, true);

        $alternates = $this->service->findAlternateSeriesDirectories(
            ['J.M. Clarke', 'C.J. Thompson'],
            'Rune Seeker',
            'Fantasy',
            'J.M. Clarke & C.J. Thompson'
        );

        $this->assertSame([], $alternates);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkForAlternateSeriesDirectoryDoesNotPromptWhenNothingAlternateExists(): void
    {
        $this->createTempBookRoot();

        $selectCalls = 0;
        $selectCallback = function () use (&$selectCalls) {
            $selectCalls++;
            return '0';
        };

        $metadata = [
            'title' => 'Rune Seeker 4',
            'author' => ['J.M. Clarke', 'C.J. Thompson'],
            'series' => 'Rune Seeker',
            'series_number' => 4,
            'genre' => 'Fantasy',
        ];

        $result = $this->service->checkForAlternateSeriesDirectory(
            $metadata,
            $selectCallback,
            function (): void {
            }
        );

        $this->assertSame(0, $selectCalls);
        $this->assertSame($metadata, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkForAlternateSeriesDirectoryUsesTheChosenAlternateLocationAndGenre(): void
    {
        $bookRoot = $this->createTempBookRoot();
        File::makeDirectory($bookRoot . '/LitRPG/J.M. Clarke & C.J. Thompson/Rune Seeker/01 Rune Seeker', 0775, true);

        $logs = [];
        $selectCallback = fn (string $question, array $options, string $default): string => '1';

        $metadata = [
            'title' => 'Rune Seeker 4',
            'author' => ['J.M. Clarke', 'C.J. Thompson'],
            'series' => 'Rune Seeker',
            'series_number' => 4,
            'genre' => 'Fantasy',
        ];

        $result = $this->service->checkForAlternateSeriesDirectory(
            $metadata,
            $selectCallback,
            function ($message) use (&$logs): void {
                $logs[] = $message;
            }
        );

        $this->assertSame('LitRPG', $result['genre']);
        $this->assertSame(
            'LitRPG/J.M. Clarke & C.J. Thompson/Rune Seeker/04 Rune Seeker 4',
            $result['custom_directory_path']
        );
        $this->assertTrue(
            collect($logs)->contains(fn ($message) => str_contains((string) $message, 'already exists elsewhere'))
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkForAlternateSeriesDirectoryKeepsTheComputedPathWhenChosen(): void
    {
        $bookRoot = $this->createTempBookRoot();
        File::makeDirectory($bookRoot . '/LitRPG/J.M. Clarke & C.J. Thompson/Rune Seeker/01 Rune Seeker', 0775, true);

        $selectCallback = fn (string $question, array $options, string $default): string => '0';

        $metadata = [
            'title' => 'Rune Seeker 4',
            'author' => ['J.M. Clarke', 'C.J. Thompson'],
            'series' => 'Rune Seeker',
            'series_number' => 4,
            'genre' => 'Fantasy',
        ];

        $result = $this->service->checkForAlternateSeriesDirectory(
            $metadata,
            $selectCallback,
            function (): void {
            }
        );

        $this->assertSame('Fantasy', $result['genre']);
        $this->assertArrayNotHasKey('custom_directory_path', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checkForAlternateSeriesDirectoryNoOpsWithoutASeries(): void
    {
        $this->createTempBookRoot();

        $selectCalls = 0;
        $selectCallback = function () use (&$selectCalls) {
            $selectCalls++;
            return '0';
        };

        $metadata = [
            'title' => 'Standalone Book',
            'author' => ['Some Author'],
            'genre' => 'Fantasy',
        ];

        $result = $this->service->checkForAlternateSeriesDirectory(
            $metadata,
            $selectCallback,
            function (): void {
            }
        );

        $this->assertSame(0, $selectCalls);
        $this->assertSame($metadata, $result);
    }
}
