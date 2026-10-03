<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports;

use App\Services\Imports\ImportObservationInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImportObservationInterpreterTest extends TestCase
{
    private ImportObservationInterpreter $interpreter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->interpreter = app(ImportObservationInterpreter::class);
    }

    /**
     * @param array<string, mixed> $rawTags
     * @param array<string, mixed> $expected
     */
    #[DataProvider('rawTagProvider')]
    public function testLegacyTagsAdaptRawTagLists(array $rawTags, array $expected): void
    {
        $this->assertSame($expected, $this->interpreter->legacyTags($rawTags));
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function rawTagProvider(): array
    {
        return [
            'single values become scalars' => [
                ['Album' => ['Dust Road'], 'ARTIST' => ['Jane Author']],
                ['album' => 'Dust Road', 'artist' => 'Jane Author'],
            ],
            'multiple artists stay a list' => [
                ['artist' => ['Jane Author', 'John Writer'], 'title' => ['A', 'B']],
                ['artist' => ['Jane Author', 'John Writer'], 'title' => 'A'],
            ],
            'blank and non-scalar values are dropped' => [
                ['album' => ['  '], 'genre' => [['nested']], 'year' => [' 2020 ']],
                ['year' => '2020'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $legacy
     * @param array<string, mixed> $expected
     */
    #[DataProvider('legacyMetadataProvider')]
    public function testContractMetadataMapsLegacyShape(array $legacy, array $expected): void
    {
        $this->assertSame($expected, $this->interpreter->contractMetadata($legacy, 'cover_01'));
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function legacyMetadataProvider(): array
    {
        return [
            'full' => [
                [
                    'title' => ' Dust Road ',
                    'author' => ['Jane Author', 'Jane Author'],
                    'narrator' => 'Sam Voice & Ann Reader',
                    'series' => 'Road Saga',
                    'series_number' => '2.5',
                    'genre' => 'Fantasy',
                    'language' => 'en',
                    'description' => '<p>A long walk.</p>',
                    'year' => '2020-09-01',
                ],
                [
                    'title' => 'Dust Road',
                    'authors' => ['Jane Author'],
                    'narrators' => ['Sam Voice', 'Ann Reader'],
                    'series' => ['name' => 'Road Saga', 'number' => 2.5],
                    'genres' => ['Fantasy'],
                    'tags' => [],
                    'language' => 'en',
                    'description' => 'A long walk.',
                    'year' => 2020,
                    'cover_artifact_id' => 'cover_01',
                ],
            ],
            'empty' => [
                [],
                [
                    'title' => null,
                    'authors' => [],
                    'narrators' => [],
                    'series' => null,
                    'genres' => [],
                    'tags' => [],
                    'language' => null,
                    'description' => null,
                    'year' => null,
                    'cover_artifact_id' => 'cover_01',
                ],
            ],
            'series without number' => [
                ['title' => 'T', 'series' => 'Saga', 'series_number' => 'first'],
                [
                    'title' => 'T',
                    'authors' => [],
                    'narrators' => [],
                    'series' => ['name' => 'Saga', 'number' => null],
                    'genres' => [],
                    'tags' => [],
                    'language' => null,
                    'description' => null,
                    'year' => null,
                    'cover_artifact_id' => 'cover_01',
                ],
            ],
        ];
    }

    public function testProvenanceListsSourcesWinnerFirstAndMarksNormalization(): void
    {
        $sources = [
            ImportObservationInterpreter::SOURCE_EMBEDDED_TAG => ['title' => 'Dust Road (Unabridged)'],
            ImportObservationInterpreter::SOURCE_FILENAME => ['title' => 'Dust Road', 'author' => ['Jane Author']],
        ];
        $metadata = $this->interpreter->contractMetadata(['title' => 'Dust Road', 'author' => ['Jane Author']]);

        $provenance = $this->interpreter->provenance($sources, $metadata);

        $this->assertSame([
            ['source' => 'folder name', 'source_id' => 'filename', 'value' => 'Dust Road', 'confidence' => 0.5],
            ['source' => 'file tags', 'source_id' => 'embedded_tag', 'value' => 'Dust Road (Unabridged)', 'confidence' => 1.0],
        ], $provenance['title']);
        $this->assertSame([
            ['source' => 'folder name', 'source_id' => 'filename', 'value' => ['Jane Author'], 'confidence' => 0.5],
        ], $provenance['authors']);
        $this->assertArrayNotHasKey('series', $provenance);
    }

    /**
     * @param array<string, mixed> $tags
     * @param array<string, mixed> $extracted
     * @param array<string, mixed> $expected
     */
    #[DataProvider('tagTitleProvider')]
    public function testTagTitleIsCheckedAgainstTheSourceName(array $tags, string $sourceName, array $extracted, array $expected): void
    {
        $this->assertSame($expected, $this->interpreter->reconcileTagTitle($extracted, $tags, $sourceName));
    }

    /**
     * @return array<string, array{array<string, mixed>, string, array<string, mixed>, array<string, mixed>}>
     */
    public static function tagTitleProvider(): array
    {
        return [
            'album from another book loses to the title tag that matches the folder' => [
                ['album' => 'USS Hamilton', 'title' => 'USS Crusader: Echoes of Sheentah', 'artist' => 'Mark Wayne McGinnis'],
                '16 - USS Crusader-Mark Wayne McGinnis',
                ['title' => 'USS Hamilton', 'author' => ['Mark Wayne McGinnis']],
                ['title' => 'USS Crusader: Echoes of Sheentah', 'author' => ['Mark Wayne McGinnis']],
            ],
            'album that matches the folder is kept' => [
                ['album' => 'Dust Road', 'title' => 'Chapter 1'],
                'Dust Road',
                ['title' => 'Dust Road'],
                ['title' => 'Dust Road'],
            ],
            'nothing matches so the tag extraction stands' => [
                ['album' => 'Alpha', 'title' => 'Beta'],
                'Gamma',
                ['title' => 'Alpha'],
                ['title' => 'Alpha'],
            ],
            'no title tag leaves the extraction alone' => [
                ['album' => 'USS Hamilton'],
                '16 - USS Crusader',
                ['title' => 'USS Hamilton'],
                ['title' => 'USS Hamilton'],
            ],
        ];
    }
}
