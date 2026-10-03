<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports;

use App\Services\BookImportService;
use App\Services\Imports\ImportBookDiscovery;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the book:import discovery rules (scanForAudiobooks / processSpecificPaths) on real temp trees, then
 * proves the filesystem-free ImportBookDiscovery proposes the same books from a names-and-sizes listing.
 */
class ImportBookDiscoveryTest extends TestCase
{
    private const BIG = 11 * 1024 * 1024;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/discovery-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/dl', 0777, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /** @param array<string, int> $files relative path (under dl/) => bytes */
    private function tree(array $files): void
    {
        foreach ($files as $path => $bytes) {
            $full = $this->base . '/dl/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            $handle = fopen($full, 'wb');
            if ($bytes > 0) {
                ftruncate($handle, $bytes);
            }
            fclose($handle);
        }
    }

    /** @return array<int, array{path: string, bytes: int, kind: string}> */
    private function listing(string $selection): array
    {
        $entries = [];
        $root = $this->base . '/dl/' . $selection;
        if (is_file($root)) {
            return [['path' => $selection, 'kind' => 'file', 'bytes' => filesize($root)]];
        }
        $entries[] = ['path' => $selection, 'kind' => 'dir', 'bytes' => 0];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = $selection . '/' . substr($item->getPathname(), strlen($root) + 1);
            $entries[] = ['path' => $relative, 'kind' => $item->isDir() ? 'dir' : 'file', 'bytes' => $item->isFile() ? $item->getSize() : 0];
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $books
     * @return array<string, array<int, string>>
     */
    private function normalizeOld(array $books): array
    {
        $out = [];
        foreach ($books as $book) {
            $files = array_map(fn (string $f): string => substr($f, strlen($this->base . '/dl/')), $book['files']);
            sort($files);
            $out[substr((string) $book['path'], strlen($this->base . '/dl/'))] = $files;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $books
     * @return array<string, array<int, string>>
     */
    private function normalizeNew(array $books): array
    {
        $out = [];
        foreach ($books as $book) {
            $files = array_map(fn (array $f): string => $f['path'], $book['files']);
            sort($files);
            $out[$book['relative_path']] = $files;
        }
        ksort($out);

        return $out;
    }

    /** @param array<int, string> $selections */
    private function oldSpecific(array $selections): array
    {
        $service = app(BookImportService::class);
        $books = $service->processSpecificPaths(
            array_map(fn (string $s): string => $this->base . '/dl/' . $s, $selections),
            fn (string $p) => $service->processSingleAudioFile($p),
            fn (string $d) => $service->processAudiobookDirectory($d),
            fn (string $m) => null
        );

        return $this->normalizeOld($books);
    }

    /** @param array<int, string> $selections */
    private function oldScan(array $selections): array
    {
        $service = app(BookImportService::class);

        return $this->normalizeOld($service->scanForAudiobooks(array_map(fn (string $s): string => $this->base . '/dl/' . $s, $selections)));
    }

    /** @param array<int, string> $selections */
    private function discover(string $mode, array $selections, array $extra = []): array
    {
        $entries = [];
        foreach ($selections as $selection) {
            $entries = array_merge($entries, $this->listing($selection));
        }

        return app(ImportBookDiscovery::class)->discover(array_merge([
            'mode' => $mode,
            'selections' => $selections,
            'entries' => $entries,
        ], $extra));
    }

    /** @return array<string, array{array<string, int>, array<int, string>}> */
    public static function layouts(): array
    {
        $big = self::BIG;

        return [
            'one folder, one m4b and a cover' => [
                ['16 - USS Crusader-Mark Wayne McGinnis/USS_Crusader.m4b' => $big, '16 - USS Crusader-Mark Wayne McGinnis/folder.jpg' => 40_000],
                ['16 - USS Crusader-Mark Wayne McGinnis'],
            ],
            'container with two books' => [
                ['Series/Book 1/a.mp3' => $big, 'Series/Book 2/b.mp3' => $big, 'Series/Extras/c.mp3' => $big],
                ['Series'],
            ],
            'container with extras only counted once' => [
                ['Series/Book 1/a.mp3' => $big, 'Series/Extras/c.mp3' => $big],
                ['Series'],
            ],
            'multi disc folders' => [
                ['Big Book/CD1/01.mp3' => $big, 'Big Book/CD2/01.mp3' => $big],
                ['Big Book'],
            ],
            'author series book nesting' => [
                ['Author/Series/Book 1/a.mp3' => $big, 'Author/Series/Book 2/b.mp3' => $big, 'Author/Other/c.m4b' => $big],
                ['Author'],
            ],
            'flat folder of several m4b files' => [
                ['Archive/one.m4b' => $big, 'Archive/two.m4b' => $big, 'Archive/three.m4b' => $big],
                ['Archive'],
            ],
            'loose big file' => [
                ['Author - Title.m4b' => $big],
                ['Author - Title.m4b'],
            ],
            'loose small file is skipped' => [
                ['small.m4b' => 1024],
                ['small.m4b'],
            ],
            'small folder is skipped' => [
                ['Tiny/a.mp3' => 2048],
                ['Tiny'],
            ],
            'two selections' => [
                ['A/a.mp3' => $big, 'B/b.mp3' => $big],
                ['A', 'B'],
            ],
        ];
    }

    /**
     * @param array<string, int> $files
     * @param array<int, string> $selections
     */
    #[Test]
    #[DataProvider('layouts')]
    public function specificPathsMatchBookImport(array $files, array $selections): void
    {
        $this->tree($files);

        $this->assertSame($this->oldSpecific($selections), $this->normalizeNew($this->discover('paths', $selections)['books']));
    }

    /**
     * @param array<string, int> $files
     * @param array<int, string> $selections
     */
    #[Test]
    #[DataProvider('layouts')]
    public function scanMatchesBookImport(array $files, array $selections): void
    {
        $directories = array_values(array_filter($selections, fn (string $s): bool => !str_contains($s, '.')));
        if ($directories === []) {
            $this->markTestSkipped('scan only takes directories');
        }
        $this->tree($files);

        $this->assertSame($this->oldScan($directories), $this->normalizeNew($this->discover('scan', $directories)['books']));
    }

    /** @return array<int, array{path: string, bytes: int, kind: string}> only what the client sends for a base directory */
    private function audioOnlyListing(string $selection): array
    {
        return array_values(array_filter(
            $this->listing($selection),
            fn (array $entry): bool => $entry['kind'] === 'file'
                && in_array(strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION)), ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac', 'aax', 'opus', 'wav'], true)
        ));
    }

    /** A base directory as the client sends it (audio files only, no folder entries) finds the same books as the old scan. */
    #[Test]
    public function scanFromAnAudioOnlyListingMatchesBookImport(): void
    {
        $this->tree([
            'Library/Author A/Series/Book 1/01.mp3' => self::BIG,
            'Library/Author A/Series/Book 1/cover.jpg' => 10,
            'Library/Author A/Series/Book 2/01.mp3' => self::BIG,
            'Library/Author B/Loose Book/one.m4b' => self::BIG,
            'Library/Author B/Big Disc Book/CD1/01.mp3' => self::BIG,
            'Library/Author B/Big Disc Book/CD2/01.mp3' => self::BIG,
            'Library/Direct Book/part.mp3' => self::BIG,
            'Library/Tiny/tiny.mp3' => 100,
            'Library/software/tool.iso' => self::BIG,
            'Library/software/docs/readme.txt' => 10,
        ]);

        $books = app(ImportBookDiscovery::class)->discover([
            'mode' => 'scan',
            'selections' => ['Library'],
            'entries' => $this->audioOnlyListing('Library'),
        ])['books'];

        $this->assertSame($this->oldScan(['Library']), $this->normalizeNew($books));
    }

    /** A big base directory sent in several requests (whole subtrees together) finds exactly the same books. */
    #[Test]
    public function scanInSeveralRequestsFindsTheSameBooks(): void
    {
        $this->tree([
            'Library/A/Book 1/01.mp3' => self::BIG,
            'Library/A/Book 2/01.mp3' => self::BIG,
            'Library/B/Disc Book/CD 1/01.mp3' => self::BIG,
            'Library/B/Disc Book/CD 2/01.mp3' => self::BIG,
            'Library/C/Book 3/01.mp3' => self::BIG,
            'Library/Direct/01.mp3' => self::BIG,
        ]);
        $all = $this->audioOnlyListing('Library');
        $groups = [[], []];
        foreach ($all as $entry) {
            $groups[str_starts_with($entry['path'], 'Library/A/') || str_starts_with($entry['path'], 'Library/B/') ? 0 : 1][] = $entry;
        }

        $merged = [];
        foreach ($groups as $entries) {
            $merged = array_merge($merged, app(ImportBookDiscovery::class)->discover([
                'mode' => 'scan',
                'selections' => ['Library'],
                'entries' => $entries,
            ])['books']);
        }

        $this->assertSame($this->oldScan(['Library']), $this->normalizeNew($merged));
    }

    #[Test]
    public function aFolderWithOneM4bIsOneBookNamedByItsFolder(): void
    {
        $this->tree(['16 - USS Crusader-Mark Wayne McGinnis/USS_Crusader.m4b' => self::BIG]);

        $books = $this->discover('paths', ['16 - USS Crusader-Mark Wayne McGinnis'])['books'];

        $this->assertCount(1, $books);
        $this->assertSame('16 - USS Crusader-Mark Wayne McGinnis', $books[0]['name']);
        $this->assertSame('book', $books[0]['kind']);
    }

    #[Test]
    public function selectingAContainerAsOneBookKeepsItsNestedAudioTogether(): void
    {
        $this->tree(['Archive/Part One/a.m4b' => self::BIG, 'Archive/Part Two/b.m4b' => self::BIG]);

        $books = $this->discover('paths', ['Archive'], [
            'overrides' => [['path' => 'Archive', 'action' => 'single']],
        ])['books'];

        $this->assertCount(1, $books);
        $this->assertSame('Archive', $books[0]['relative_path']);
        $this->assertSame(['Archive/Part One/a.m4b', 'Archive/Part Two/b.m4b'], array_column($books[0]['files'], 'path'));
    }

    #[Test]
    public function aLooseFileIsASingleFileBookNamedWithoutItsExtension(): void
    {
        $this->tree(['Author - Title.m4b' => self::BIG, 'Small.mp3' => 10]);

        $result = $this->discover('paths', ['Author - Title.m4b', 'Small.mp3']);

        $this->assertSame(['Author - Title'], array_column($result['books'], 'name'));
        $this->assertSame(['single_file'], array_column($result['books'], 'kind'));
        $this->assertSame('too_small', $result['skipped'][0]['reason']);
    }

    #[Test]
    public function multiDiscFoldersBecomeTheirParentBook(): void
    {
        $this->tree(['Library/Big Book/CD1/01.mp3' => self::BIG, 'Library/Big Book/CD2/01.mp3' => self::BIG]);

        $books = $this->discover('scan', ['Library'])['books'];

        $this->assertSame(['multi_disc'], array_column($books, 'kind'));
        $this->assertSame(['Library/Big Book'], array_column($books, 'relative_path'));
        $this->assertSame($this->oldScan(['Library']), $this->normalizeNew($books));
    }

    #[Test]
    public function aSeriesRangeNameSplitsIntoOneBookPerNumberedFile(): void
    {
        $this->tree([
            'Saga [2-4]/Saga - Book 02 - Fire.m4b' => self::BIG,
            'Saga [2-4]/Saga - Book 03 - Water.m4b' => self::BIG,
            'Saga [2-4]/Saga - Book 04 - Air.m4b' => self::BIG,
        ]);

        $books = $this->discover('paths', ['Saga [2-4]'])['books'];

        $this->assertSame(['multi_book_part', 'multi_book_part', 'multi_book_part'], array_column($books, 'kind'));
        $this->assertSame([2, 3, 4], array_column(array_column($books, 'split'), 'number'));
        $this->assertSame(['Saga [2-4]'], array_unique(array_column($books, 'parent_path')));
        $this->assertCount(1, $books[0]['files']);
    }

    #[Test]
    public function aRangeNamedFileWithoutMatchingFilesStaysACombinedBook(): void
    {
        $this->tree(['Ascension Volume I (1-3)/all in one.m4b' => self::BIG]);

        $books = $this->discover('paths', ['Ascension Volume I (1-3)'])['books'];

        $this->assertCount(1, $books);
        $this->assertSame('multi_book_combined', $books[0]['kind']);
        $this->assertSame([1, 2, 3], $books[0]['split']['numbers']);
    }

    #[Test]
    public function partsOfOneBookAreNotSplitAsAFlatArchive(): void
    {
        $this->tree(['Book/01 - Intro.mp3' => self::BIG, 'Book/02 - Chapter One.mp3' => self::BIG, 'Book/03 - Chapter Two.mp3' => self::BIG]);

        $books = $this->discover('paths', ['Book'])['books'];

        $this->assertSame(['book'], array_column($books, 'kind'));
    }

    #[Test]
    public function aFlatFolderOfDifferentTitlesAsksForTagsBeforeSplitting(): void
    {
        $this->tree(['Mixed/Alpha Quest.m4b' => self::BIG, 'Mixed/Beta Saga Returns.m4b' => self::BIG, 'Mixed/Gamma Ray Rising.m4b' => self::BIG]);

        $first = $this->discover('paths', ['Mixed']);

        $this->assertSame(['book'], array_column($first['books'], 'kind'));
        $this->assertNotEmpty($first['evidence_needed']);
        $this->assertSame(['album', 'title'], $first['evidence_needed'][0]['fields']);

        $files = $first['evidence_needed'][0]['files'];
        $tags = array_map(fn (string $path): array => ['path' => $path, 'album' => null, 'title' => null], $files);
        $second = $this->discover('paths', ['Mixed'], ['tags' => $tags]);

        $this->assertSame(['multi_book_part', 'multi_book_part', 'multi_book_part'], array_column($second['books'], 'kind'));
        $this->assertSame([], $second['evidence_needed']);
    }

    #[Test]
    public function filesSharingAnAlbumTagAreOneBook(): void
    {
        $this->tree(['Mixed/Alpha Quest.m4b' => self::BIG, 'Mixed/Beta Saga Returns.m4b' => self::BIG]);
        $first = $this->discover('paths', ['Mixed']);
        $tags = array_map(fn (string $path): array => ['path' => $path, 'album' => 'One Book', 'title' => null], $first['evidence_needed'][0]['files']);

        $second = $this->discover('paths', ['Mixed'], ['tags' => $tags]);

        $this->assertSame(['book'], array_column($second['books'], 'kind'));
    }

    #[Test]
    public function mergeIntoParentKeepsASplitCandidateAsOneBook(): void
    {
        $this->tree(['Saga [2-3]/Saga 02.m4b' => self::BIG, 'Saga [2-3]/Saga 03.m4b' => self::BIG]);

        $books = $this->discover('paths', ['Saga [2-3]'], ['overrides' => [['path' => 'Saga [2-3]', 'action' => 'single']]])['books'];

        $this->assertSame(['book'], array_column($books, 'kind'));
        $this->assertCount(2, $books[0]['files']);
    }

    #[Test]
    public function reprocessAsMultiBookSplitsEveryFileIntoItsOwnBook(): void
    {
        $this->tree(['Folder/a one.m4b' => self::BIG, 'Folder/b two.m4b' => self::BIG]);

        $books = $this->discover('paths', ['Folder'], ['overrides' => [['path' => 'Folder', 'action' => 'split']]])['books'];

        $this->assertSame(['multi_book_part', 'multi_book_part'], array_column($books, 'kind'));
    }

    #[Test]
    public function partsCarryTheirCompanionMetadataAndCover(): void
    {
        $this->tree([
            'Saga [1-2]/Saga 01.m4b' => self::BIG,
            'Saga [1-2]/Saga 02.m4b' => self::BIG,
            'Saga [1-2]/cover.jpg' => 1000,
            'Saga [1-2]/metadata.json' => 50,
        ]);

        $books = $this->discover('paths', ['Saga [1-2]'])['books'];

        $paths = array_map(fn (array $f): string => $f['path'], $books[0]['files']);
        $this->assertContains('Saga [1-2]/cover.jpg', $paths);
        $this->assertContains('Saga [1-2]/metadata.json', $paths);
    }
}
