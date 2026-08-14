<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceEditMetadataFieldsTest extends TestCase
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
    public function editingSeriesAfterTitleDoesNotStripManuallyEditedTitleNumber(): void
    {
        $metadata = [
            'title' => 'Old Title',
            'author' => ['Old Author'],
            'series' => 'Old Series',
        ];

        $askResponses = [
            'Mistborn 3',
            'Mistborn',
        ];
        $askInlineCallback = function (string $question, string $default) use (&$askResponses): string {
            return array_shift($askResponses) ?? $default;
        };

        $choices = ['1', '4', '9'];
        $selectCallback = function (string $question, array $options, string $default) use (&$choices): string {
            return array_shift($choices) ?? $default;
        };

        $getFirstNonEmptyCallback = function (array $metadata, array $keys) {
            foreach ($keys as $key) {
                if (!empty($metadata[$key] ?? null)) {
                    return $metadata[$key];
                }
            }
            return null;
        };

        $extractSeriesNumberFromTitleCallback = function (array &$metadata): void {
            if (!isset($metadata['title']) || !is_string($metadata['title'])) {
                return;
            }
            if (preg_match('/^(.+?)\s+([\d.]+)$/', $metadata['title'], $matches)) {
                $metadata['title'] = trim($matches[1]);
                $metadata['series_number'] = $matches[2];
            }
        };

        $result = $this->service->editMetadataFields(
            $metadata,
            [],
            $askInlineCallback,
            $selectCallback,
            $getFirstNonEmptyCallback,
            $extractSeriesNumberFromTitleCallback,
            fn () => ['Other'],
            function (): void {
            },
            fn (array $metadata) => $metadata
        );

        $this->assertSame('Mistborn 3', $result['title']);
        $this->assertSame('Mistborn', $result['series']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function editingTagsFromTheMenuSetsCommaSeparatedTagsTrimmedAndDeduplicated(): void
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'tags' => ['old-tag'],
        ];

        $askResponses = [' Spicy , RH , Spicy '];
        $askInlineCallback = function (string $question, string $default) use (&$askResponses): string {
            return array_shift($askResponses) ?? $default;
        };

        // 't' = edit tags, then done.
        $choices = ['t', '9'];
        $selectCallback = function (string $question, array $options, string $default) use (&$choices): string {
            return array_shift($choices) ?? $default;
        };

        $getFirstNonEmptyCallback = function (array $metadata, array $keys) {
            foreach ($keys as $key) {
                if (!empty($metadata[$key] ?? null)) {
                    return $metadata[$key];
                }
            }
            return null;
        };

        $result = $this->service->editMetadataFields(
            $metadata,
            [],
            $askInlineCallback,
            $selectCallback,
            $getFirstNonEmptyCallback,
            function (array &$metadata): void {
            },
            fn () => ['Other'],
            function (): void {
            },
            fn (array $metadata) => $metadata
        );

        $this->assertSame(['Spicy', 'RH'], $result['tags']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function addingNarratorToDirectoryNameRefreshesTheCurrentBookDetailsPanel(): void
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'narrator' => ['Great Narrator'],
            'custom_directory_path' => 'Fiction/Test Author/Test Book',
        ];

        $askInlineCallback = fn (string $question, string $default): string => $default;

        // 'n' = add narrator to directory name, then done.
        $choices = ['n', '9'];
        $selectCallback = function (string $question, array $options, string $default) use (&$choices): string {
            return array_shift($choices) ?? $default;
        };

        $uiCalls = [];
        $uiServiceLogCallback = function ($message, $data = null) use (&$uiCalls): void {
            $uiCalls[] = [$message, $data];
        };

        $getFirstNonEmptyCallback = function (array $metadata, array $keys) {
            foreach ($keys as $key) {
                if (!empty($metadata[$key] ?? null)) {
                    return $metadata[$key];
                }
            }
            return null;
        };

        $result = $this->service->editMetadataFields(
            $metadata,
            [],
            $askInlineCallback,
            $selectCallback,
            $getFirstNonEmptyCallback,
            function (array &$metadata): void {
            },
            fn () => ['Other'],
            $uiServiceLogCallback,
            fn (array $metadata) => $metadata
        );

        $this->assertSame('Fiction/Test Author/Test Book (Great Narrator)', $result['custom_directory_path']);

        // The "Current Book Details" panel must be refreshed immediately after the
        // directory path changes, same as every other edit in this menu.
        $refreshCalls = array_filter($uiCalls, fn (array $call): bool => $call[0] === 'setCurrentBook');
        $this->assertNotEmpty($refreshCalls, 'setCurrentBook must be called after updating the directory name');
        $lastRefresh = end($refreshCalls);
        $this->assertSame('Fiction/Test Author/Test Book (Great Narrator)', $lastRefresh[1]['custom_directory_path']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function editingAnUnrelatedFieldDoesNotRunSeriesNumberExtractionOrTouchTitle(): void
    {
        $metadata = [
            'title' => 'My Big Fat Supernatural Honeymoon',
            'author' => ['P. N. Elrod (Editor)'],
            'narrator' => [''],
        ];

        $askResponses = ['Full Cast'];
        $askInlineCallback = function (string $question, string $default) use (&$askResponses): string {
            return array_shift($askResponses) ?? $default;
        };

        // '3' = edit narrator (unrelated to title/series), then done.
        $choices = ['3', '9'];
        $selectCallback = function (string $question, array $options, string $default) use (&$choices): string {
            return array_shift($choices) ?? $default;
        };

        $getFirstNonEmptyCallback = function (array $metadata, array $keys) {
            foreach ($keys as $key) {
                if (!empty($metadata[$key] ?? null)) {
                    return $metadata[$key];
                }
            }
            return null;
        };

        $extractionCallCount = 0;
        $extractSeriesNumberFromTitleCallback = function (array &$metadata) use (&$extractionCallCount): void {
            $extractionCallCount++;
        };

        $result = $this->service->editMetadataFields(
            $metadata,
            [],
            $askInlineCallback,
            $selectCallback,
            $getFirstNonEmptyCallback,
            $extractSeriesNumberFromTitleCallback,
            fn () => ['Other'],
            function (): void {
            },
            fn (array $metadata) => $metadata
        );

        $this->assertSame(0, $extractionCallCount, 'Editing an unrelated field must not trigger series-number extraction');
        $this->assertSame('My Big Fat Supernatural Honeymoon', $result['title']);
        $this->assertSame(['Full Cast'], $result['narrator']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function editingSeriesAloneStillRunsSeriesNumberExtraction(): void
    {
        $metadata = [
            'title' => 'Some Title',
            'author' => ['Some Author'],
        ];

        $askResponses = ['Mistborn'];
        $askInlineCallback = function (string $question, string $default) use (&$askResponses): string {
            return array_shift($askResponses) ?? $default;
        };

        // '4' = edit series, then done.
        $choices = ['4', '9'];
        $selectCallback = function (string $question, array $options, string $default) use (&$choices): string {
            return array_shift($choices) ?? $default;
        };

        $getFirstNonEmptyCallback = function (array $metadata, array $keys) {
            foreach ($keys as $key) {
                if (!empty($metadata[$key] ?? null)) {
                    return $metadata[$key];
                }
            }
            return null;
        };

        $extractionCallCount = 0;
        $extractSeriesNumberFromTitleCallback = function (array &$metadata) use (&$extractionCallCount): void {
            $extractionCallCount++;
        };

        $this->service->editMetadataFields(
            $metadata,
            [],
            $askInlineCallback,
            $selectCallback,
            $getFirstNonEmptyCallback,
            $extractSeriesNumberFromTitleCallback,
            fn () => ['Other'],
            function (): void {
            },
            fn (array $metadata) => $metadata
        );

        $this->assertSame(1, $extractionCallCount, 'Editing series must still trigger series-number extraction');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function swapTitleAndSeriesChoiceSwapsBothFields(): void
    {
        $metadata = [
            'title' => 'The Forgotten Five',
            'author' => ['Some Author'],
            'series' => 'Rebel Undercover',
        ];

        $choices = ['s', '9'];
        $selectCallback = function (string $question, array $options, string $default) use (&$choices): string {
            return array_shift($choices) ?? $default;
        };

        $extractSeriesNumberFromTitleCallback = function (array &$metadata): void {
        };

        $result = $this->service->editMetadataFields(
            $metadata,
            [],
            fn (string $question, string $default) => $default,
            $selectCallback,
            fn (array $metadata, array $keys) => null,
            $extractSeriesNumberFromTitleCallback,
            fn () => ['Other'],
            function (): void {
            },
            fn (array $metadata) => $metadata
        );

        $this->assertSame('Rebel Undercover', $result['title']);
        $this->assertSame('The Forgotten Five', $result['series']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function acceptAndImportChoiceExitsTheMenuAndSignalsTheAction(): void
    {
        $metadata = [
            'title' => 'A Book',
            'author' => ['Some Author'],
        ];

        $choices = ['y'];
        $selectCallback = function (string $question, array $options, string $default) use (&$choices): string {
            return array_shift($choices) ?? $default;
        };

        $result = $this->service->editMetadataFields(
            $metadata,
            [],
            fn (string $question, string $default) => $default,
            $selectCallback,
            fn (array $metadata, array $keys) => null,
            function (array &$metadata): void {
            },
            fn () => ['Other'],
            function (): void {
            },
            fn (array $metadata) => $metadata
        );

        $this->assertSame('accept_and_import', $result['_action']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function editAllFieldsSequentialKeepsFilteredPrimaryGenreSelectionAndEditsExtras(): void
    {
        $metadata = [
            'title' => 'Old Title',
            'author' => ['Old Author'],
            'narrator' => ['Old Narrator'],
            'series' => 'Old Series',
            'series_number' => '1',
            'year' => '2020',
            'genre' => 'Fantasy',
        ];

        $askResponses = [
            'New Title',
            'New Author',
            'New Narrator',
            'New Series',
            '2',
            '2021',
            'Fantasy, Other',
            '',
            '/library/New Title',
        ];
        $askInlineCallback = function (string $question, string $default) use (&$askResponses): string {
            return array_shift($askResponses) ?? $default;
        };

        // 'a' picks "Edit all fields (sequential)"; '9' finishes afterward.
        $menuChoices = ['a', '9'];
        $plainSelectCalls = [];
        $plainSelectCallback = function (string $question, array $options, string $default) use (&$menuChoices, &$plainSelectCalls): string {
            $plainSelectCalls[] = $question;
            return array_shift($menuChoices) ?? $default;
        };

        $filteredSelectCalls = [];
        $filteredSelectCallback = function (string $question, array $options, string $default) use (&$filteredSelectCalls): string {
            $filteredSelectCalls[] = $question;
            return '2';
        };

        $getFirstNonEmptyCallback = function (array $metadata, array $keys) {
            foreach ($keys as $key) {
                if (!empty($metadata[$key] ?? null)) {
                    return $metadata[$key];
                }
            }
            return null;
        };

        $result = $this->service->editMetadataFields(
            $metadata,
            [],
            $askInlineCallback,
            $plainSelectCallback,
            $getFirstNonEmptyCallback,
            function (array &$metadata): void {
            },
            fn () => ['Fantasy', 'Horror', 'Other'],
            function (): void {
            },
            fn (array $metadata) => $metadata,
            selectFilteredCallback: $filteredSelectCallback
        );

        $this->assertSame(['Genre'], $filteredSelectCalls);
        $this->assertSame(['Horror', 'Fantasy'], $result['genre']);
        $this->assertSame('New Title', $result['title']);
        $this->assertSame('/library/New Title', $result['custom_directory_path']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function escapeCancellingGenreDuringSequentialEditSkipsDirectoryPathButKeepsOtherEdits(): void
    {
        $metadata = [
            'title' => 'Old Title',
            'author' => ['Old Author'],
            'narrator' => ['Old Narrator'],
            'series' => 'Old Series',
            'series_number' => '1',
            'year' => '2020',
            'genre' => 'Fantasy',
        ];

        $askQuestions = [];
        $askResponses = [
            'New Title',
            'New Author',
            'New Narrator',
            'New Series',
            '2',
            '2021',
            '',
        ];
        $askInlineCallback = function (string $question, string $default) use (&$askResponses, &$askQuestions): string {
            $askQuestions[] = $question;
            return array_shift($askResponses) ?? $default;
        };

        $menuChoices = ['a', '9'];
        $plainSelectCallback = function (string $question, array $options, string $default) use (&$menuChoices): string {
            return array_shift($menuChoices) ?? $default;
        };

        $filteredSelectCallback = fn (string $question, array $options, string $default): string => '';

        $getFirstNonEmptyCallback = function (array $metadata, array $keys) {
            foreach ($keys as $key) {
                if (!empty($metadata[$key] ?? null)) {
                    return $metadata[$key];
                }
            }
            return null;
        };

        $result = $this->service->editMetadataFields(
            $metadata,
            [],
            $askInlineCallback,
            $plainSelectCallback,
            $getFirstNonEmptyCallback,
            function (array &$metadata): void {
            },
            fn () => ['Fantasy', 'Horror', 'Other'],
            function (): void {
            },
            fn (array $metadata) => $metadata,
            selectFilteredCallback: $filteredSelectCallback
        );

        $this->assertNotContains('Directory Path', $askQuestions, 'Escape on Genre must skip the Directory Path prompt');
        $this->assertSame('Fantasy', $result['genre'], 'Escape on Genre must leave the original genre untouched');
        $this->assertSame('New Title', $result['title'], 'Fields collected before the cancelled Genre step must still apply');
        $this->assertSame('New Author', $result['author'][0] ?? null);
        $this->assertArrayNotHasKey('custom_directory_path', $result, 'Directory path must be left untouched, not defaulted');
    }
}
