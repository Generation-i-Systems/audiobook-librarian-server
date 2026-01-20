<?php

namespace Tests\Cli\Unit\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use Tests\TestCase;

class ImportBooksFromDownloadsReviewOptionsTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function buildReviewOptionsIncludesUpdateCoverGenreAndDirectory(): void
    {
        $command = new ImportBooksFromDownloadsReviewOptionsTestDouble();

        $options = $command->exposeBuildReviewOptions(
            'https://example.com/cover.jpg',
            'Fantasy',
            'Some/Directory/Path',
            false
        );

        $this->assertArrayHasKey('4', $options);
        $this->assertStringContainsString('Update cover', $options['4']);

        $this->assertArrayHasKey('5', $options);
        $this->assertStringContainsString('Update genre', $options['5']);

        $this->assertArrayHasKey('6', $options);
        $this->assertStringContainsString('Update directory', $options['6']);
        $this->assertStringContainsString('Some/Directory/Path', $options['6']);
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function buildReviewOptionsTruncatesLongGenreAndDirectoryValues(): void
    {
        $command = new ImportBooksFromDownloadsReviewOptionsTestDouble();

        $options = $command->exposeBuildReviewOptions(
            '',
            'Historical Fiction', // Long enough to truncate (> 16)
            'A/Very/Long/Directory/Path/That/Will/Never/Fit',
            false
        );

        $this->assertStringContainsString('…', $options['5']);
        $this->assertStringContainsString('…', $options['6']);
        $this->assertStringContainsString('Update directory', $options['6']);
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
