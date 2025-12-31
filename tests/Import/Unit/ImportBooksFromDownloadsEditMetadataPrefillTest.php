<?php

namespace Tests\Import\Unit\Commands;

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

    protected function askInline(string $question, string $default = ''): string
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
