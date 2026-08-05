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
}
