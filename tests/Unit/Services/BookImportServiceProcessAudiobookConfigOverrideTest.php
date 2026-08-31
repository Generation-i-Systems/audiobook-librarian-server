<?php

namespace Tests\Unit\Services;

use App\Models\Genre;
use App\Services\AIBookProcessor;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceProcessAudiobookConfigOverrideTest extends TestCase
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function processAudiobookForcesConfiguredAuthorNarratorSeriesGenreAndTags(): void
    {
        // Regression test: --author/--narrator/--series/--tags (and --genre) only forced
        // metadata via BookImportService::$config on the multi-book-split path
        // (processSingleBook()). The main single-book path (processAudiobook(), used for
        // the vast majority of imports) only ever force-applied genre, so e.g. --tags was
        // silently ignored and the confirmation screen showed "Tags: N/A".
        $bookRoot = sys_get_temp_dir() . '/book_root_' . uniqid('', true);
        File::makeDirectory($bookRoot, 0775, true);
        config(['app.book_root' => $bookRoot]);
        config(['filesystems.disks.books.root' => $bookRoot]);

        Genre::create(['name' => 'Science Fiction']);

        $this->service->setConfig([
            'genre' => 'Science Fiction',
            'author' => 'Forced Author',
            'narrator' => 'Forced Narrator',
            'series' => 'Forced Series',
            'tags' => ['forced-tag'],
        ]);

        $sourceDir = sys_get_temp_dir() . '/source_book_' . uniqid('', true);
        File::makeDirectory($sourceDir, 0775, true);
        $sourceFile = $sourceDir . '/track.mp3';
        File::put($sourceFile, str_repeat('0', 1024));

        $audiobook = [
            'name' => 'Original Title',
            'path' => $sourceDir,
            'files' => [$sourceFile],
            'total_size' => filesize($sourceFile),
        ];

        $aiMetadata = [
            'title' => 'Original Title',
            'author' => ['Original Author'],
            'narrator' => 'Original Narrator',
            'series' => 'Original Series',
            'tags' => ['original-tag'],
            'genre' => 'Other',
            'confidence' => 100,
        ];

        $skippedBooks = [];
        $processedBooks = [];

        $this->service->processAudiobook(
            audiobook: $audiobook,
            aiProcessor: new AIBookProcessor(),
            buildUiMetadataCallback: fn (array $m) => $m,
            uiServiceLogCallback: fn (string $m) => null,
            infoCallback: fn (string $m) => null,
            lineCallback: fn (string $m) => null,
            newLineCallback: fn () => null,
            warnCallback: fn (string $m) => null,
            displayEnrichedMetadataCallback: fn (array $m) => null,
            reviewAndApproveCallback: fn (array $m, array $a) => true,
            hasEnrichmentDataCallback: fn (array $m) => true,
            getFileOperationCallback: fn () => 'copy',
            enrichWithExternalDataCallback: fn (array $m) => null,
            getEnrichmentServiceCallback: fn () => null,
            findExistingBookCallback: fn (string $path, array $m) => null,
            compareDirectoriesCallback: fn (string $a, string $b) => [],
            displayDirectoryComparisonCallback: fn (array $c) => null,
            promptForDuplicateActionCallback: fn () => null,
            cleanupSourceDirectoryCallback: fn (array $a, bool $forceCleanup = false) => null,
            formatBytesCallback: fn (int $bytes) => (string) $bytes,
            extractSeriesNumberFromTitleCallback: fn (array &$m) => null,
            detectMultiBookPatternCallback: fn (string $name) => null,
            analyzeMultiBookFilesCallback: fn (array $a, array $info) => [],
            processMultiBookSplitCallback: fn (array $a, array $info, array $groups, array $m) => null,
            handleLowConfidenceMetadataCallback: fn (array $a, array $m) => false,
            processWithAICallback: fn (array $a) => $aiMetadata,
            skippedBooks: $skippedBooks,
            processedBooks: $processedBooks,
            isAutoMode: true,
            isDryRun: false,
            skipEnrichment: true,
        );

        $this->assertNotEmpty($processedBooks, 'Book should have been imported, not skipped');

        $book = \App\Models\Book::query()->findOrFail($processedBooks[0]['book_id']);

        $this->assertSame(['Forced Author'], $book->authors->pluck('name')->all());
        $this->assertSame(['Forced Narrator'], $book->narrators->pluck('name')->all());
        $this->assertSame(['Forced Series'], $book->series->pluck('name')->all());

        $systemTags = \App\Models\BookTag::query()
            ->where('book_id', $book->id)
            ->where('owner_key', 'system')
            ->first();
        $this->assertNotNull($systemTags, 'Forced tags must be persisted for the main (non-split) import path');
        $this->assertContains('forced-tag', $systemTags->tags);
        $this->assertContains('original-tag', $systemTags->tags);
    }
}
