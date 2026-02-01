<?php

namespace Tests\Cli\Unit\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use App\Services\BookImportService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ImportBooksFromDownloadsEditMetadataPrefillTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function editMetadataFieldsPrefillsFromAlternateKeys(): void
    {
        $command = new ImportBooksFromDownloadsEditMetadataPrefillTestDouble();

        $command->askInlineResponses = [
            'New Title',
            'Author One, Author Two',
            'Narrator One',
            'Series Name',
            '7',
            '2024',
            '/tmp/dir',
        ];

        $metadata = [
            'book_title' => 'Old Title',
            'authors' => ['Old Author'],
            'narrators' => ['Old Narrator'],
            'series_name' => 'Old Series',
            'seriesNumber' => 3,
            'published_date' => '2023-01-01',
            'custom_directory_path' => '/tmp/old',
            'genre' => 'Other',
        ];

        $result = $command->exposeEditMetadataFields($metadata);

        $this->assertSame('New Title', $result['title']);
        $this->assertSame(['Author One', 'Author Two'], $result['author']);
        $this->assertSame(['Narrator One'], $result['narrator']);
        $this->assertSame('Series Name', $result['series']);
        $this->assertSame('7', $result['series_number']);
        $this->assertSame('2024', $result['year']);
        $this->assertSame('/tmp/dir', $result['custom_directory_path']);

        $this->assertSame(
            [
                ['Title', 'Old Title'],
                ['Author(s) (comma-separated)', 'Old Author'],
                ['Narrator(s) (comma-separated)', 'Old Narrator'],
                ['Series', 'Old Series'],
                ['Series Number', '3'],
                ['Year', '2023'],
                ['Directory', '/tmp/old'],
            ],
            $command->askInlineCalls,
        );
    }
}

class ImportBooksFromDownloadsEditMetadataPrefillTestDouble extends ImportBooksFromDownloads
{
    public array $askInlineResponses = [];

    /** @var array<int, array{0:string,1:string}> */
    public array $askInlineCalls = [];

    public function __construct()
    {
        parent::__construct(null);
    }

    public function exposeEditMetadataFields(array $metadata): array
    {
        return $this->editMetadataFields($metadata, []);
    }

    protected function askInline(string $question, ?string $default = ''): string
    {
        $this->askInlineCalls[] = [$question, $default];

        if (count($this->askInlineResponses) === 0) {
            return '';
        }

        return array_shift($this->askInlineResponses);
    }

    public function getValidGenres(): array
    {
        return ['Other'];
    }

    protected function selectWithImmediateInterrupt(string $question, array $options, string $default = ''): string
    {
        return $default !== '' ? $default : (string) array_key_first($options);
    }

    protected function getFirstNonEmptyMetadataValue(array $metadata, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($metadata[$key]) && !empty($metadata[$key])) {
                return $metadata[$key];
            }
        }
        return null;
    }

    protected function extractSeriesNumberFromTitle(array &$metadata): void
    {
        if (!isset($metadata['title']) || !is_string($metadata['title'])) {
            return;
        }

        $title = $metadata['title'];
        if (preg_match('/\b(\d+(?:\.\d+)?)\b/', $title, $matches)) {
            $metadata['series_number'] = $matches[1];
        }
    }

    protected function editMetadataFields(array $metadata, array $audiobook, bool $sequential = false): array
    {
        $currentTitle = $this->getFirstNonEmptyMetadataValue($metadata, ['title', 'book_title', 'name']);
        $metadata['title'] = $this->askInline('Title', is_string($currentTitle) ? $currentTitle : (string) ($metadata['title'] ?? ''));

        $currentAuthor = $this->getFirstNonEmptyMetadataValue($metadata, ['author', 'authors', 'authorName', 'author_name']);
        if (is_array($currentAuthor)) {
            $currentAuthor = implode(', ', $currentAuthor);
        }
        $newAuthor = $this->askInline("Author(s) (comma-separated)", $currentAuthor);
        $metadata['author'] = array_map('trim', explode(',', $newAuthor));

        $currentNarrator = $this->getFirstNonEmptyMetadataValue($metadata, ['narrator', 'narrators', 'narratorName', 'narrator_name']);
        if (is_array($currentNarrator)) {
            $currentNarrator = implode(', ', $currentNarrator);
        }
        $newNarrator = $this->askInline('Narrator(s) (comma-separated)', is_string($currentNarrator) ? $currentNarrator : '');
        $metadata['narrator'] = array_map('trim', explode(',', $newNarrator));

        $currentSeries = $this->getFirstNonEmptyMetadataValue($metadata, ['series', 'seriesName', 'series_name']);
        $metadata['series'] = $this->askInline('Series', is_string($currentSeries) ? $currentSeries : (string) ($metadata['series'] ?? ''));

        $currentSeriesNumber = $this->getFirstNonEmptyMetadataValue($metadata, ['series_number', 'seriesNumber', 'series_num', 'seriesNum']);
        $metadata['series_number'] = $this->askInline(
            'Series Number',
            is_scalar($currentSeriesNumber) ? (string) $currentSeriesNumber : (string) ($metadata['series_number'] ?? '')
        );

        $currentYear = $this->getFirstNonEmptyMetadataValue($metadata, ['year', 'publishedYear', 'published_year', 'published_date']);
        if (is_string($currentYear) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $currentYear)) {
            $currentYear = substr($currentYear, 0, 4);
        }
        $metadata['year'] = $this->askInline('Year', is_scalar($currentYear) ? (string) $currentYear : (string) ($metadata['year'] ?? ''));

        $currentDirectory = (string) ($metadata['custom_directory_path'] ?? '');
        $metadata['custom_directory_path'] = $this->askInline('Directory', $currentDirectory);

        $this->extractSeriesNumberFromTitle($metadata);

        return $metadata;
    }

    protected function getImportService(): BookImportService
    {
        /** @var MockInterface $mock */
        $mock = Mockery::mock(BookImportService::class);
        $mock->shouldReceive('generateDirectoryPath')
            ->andReturn('/tmp/generated');

        /** @var BookImportService $service */
        $service = $mock;

        return $service;
    }
}
