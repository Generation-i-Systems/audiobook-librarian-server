<?php

/** @noinspection PhpUnhandledExceptionInspection */

namespace Tests\Cli\Unit\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use Tests\TestCase;

class ImportBooksFromDownloadsReviewOptionsTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function buildReviewOptionsIncludesStandardOptions(): void
    {
        $command = new ImportBooksFromDownloadsReviewOptionsTestDouble();

        $options = $command->exposeBuildReviewOptions(
            'https://example.com/cover.jpg',
            'Fantasy',
            'Some/Directory/Path',
            false
        );

        $this->assertArrayHasKey('1', $options);
        $this->assertStringContainsString('Accept all as correct', $options['1']);

        $this->assertArrayHasKey('2', $options);
        $this->assertStringContainsString('Edit all fields', $options['2']);

        $this->assertArrayHasKey('3', $options);
        $this->assertStringContainsString('Edit individual fields', $options['3']);

        $this->assertArrayHasKey('4', $options);
        $this->assertStringContainsString('Skip this book', $options['4']);

        $this->assertArrayHasKey('5', $options);
        $this->assertStringContainsString('Update cover', $options['5']);

        $this->assertArrayHasKey('6', $options);
        $this->assertStringContainsString('Request enrichment', $options['6']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function buildReviewOptionsHidesAcceptAllWhenGenreInvalid(): void
    {
        $command = new ImportBooksFromDownloadsReviewOptionsTestDouble();

        $options = $command->exposeBuildReviewOptions(
            '',
            'Unknown',
            'Some/Directory/Path',
            false
        );

        $this->assertArrayHasKey('1', $options);
        $this->assertStringContainsString("\e[9m", $options['1']);
        $this->assertArrayHasKey('2', $options);
        $this->assertArrayHasKey('3', $options);
    }
}

class ImportBooksFromDownloadsReviewOptionsTestDouble extends ImportBooksFromDownloads
{
    public function __construct()
    {
        parent::__construct(null);
    }

    public function exposeBuildReviewOptions(
        string $currentCoverUrl,
        string $currentGenre,
        string $currentDirectoryPath,
        bool $isFinalConfirmation
    ): array {
        return $this->buildReviewOptions($currentCoverUrl, $currentGenre, $currentDirectoryPath, $isFinalConfirmation);
    }
}
