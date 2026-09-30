<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookImportServiceNfoContentTest extends TestCase
{
    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('nfoContentProvider')]
    public function testParseNfoContentMatchesFormat(string $content, array $expected): void
    {
        $service = app(BookImportService::class);

        $this->assertSame($expected, $service->parseNfoContent($content));
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('nfoContentProvider')]
    public function testExtractNfoDataDelegatesToParseNfoContent(string $content, array $expected): void
    {
        $directory = sys_get_temp_dir() . '/nfo-content-test-' . uniqid();
        File::makeDirectory($directory);
        File::put($directory . '/book.nfo', $content);

        try {
            $this->assertSame($expected, app(BookImportService::class)->extractNfoData($directory));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function nfoContentProvider(): array
    {
        return [
            'xml' => [
                '<audiobook><title>Dust Road</title><author>Jane Author</author>'
                    . '<series>Road Saga</series><seriesNumber>2</seriesNumber><genre>Fantasy</genre></audiobook>',
                [
                    'title' => 'Dust Road',
                    'author' => 'Jane Author',
                    'series' => 'Road Saga',
                    'series_number' => '2',
                    'genre' => 'Fantasy',
                ],
            ],
            'plain text' => [
                "Title: Dust Road\nAuthor: Jane Author\nRead by: Sam Voice\nSeries: Road Saga\n",
                [
                    'title' => 'Dust Road',
                    'author' => 'Jane Author',
                    'narrator' => 'Sam Voice',
                    'series' => 'Road Saga',
                ],
            ],
        ];
    }
}
