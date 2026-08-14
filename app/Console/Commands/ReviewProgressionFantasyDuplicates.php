<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\AIBookProcessor;
use App\Services\ProgressionFantasySeedParser;
use Illuminate\Support\Facades\File;

/**
 * Interactive reviewer for the "Progression Fantasy Collection Seed" leftover files that are
 * slot-duplicates of an already-existing library book but not confirmed byte-identical (see
 * book:import-progression-fantasy-seed). For each pair, shows size/duration/narrator, offers to
 * play either file with mplayer, and lets the user decide: keep existing (discard seed), replace
 * existing with the seed, or import the seed as a second, distinctly-named copy.
 */
class ReviewProgressionFantasyDuplicates extends ImportBooksFromDownloads
{
    private const AUDIO_EXTENSIONS = ['m4b', 'mp3', 'm4a'];
    private const DEFAULT_PATH = '/media/lyra_data1/audiobooks/unsorted/Progression Fantasy Collection Seed';
    private const EXCLUDED_SERIES = ['Legend of the Arch Magus', 'Divine Dungeon'];
    private const GENRE = 'LitRPG';
    private const COLLECTION_NAME = 'Progression Fantasy Collection Seed';

    protected $signature = 'book:review-progression-fantasy-duplicates
                            {path?* : Unused here; declared because the parent class ImportBooksFromDownloads reads it in inherited methods}
                            {--path= : Override the collection directory to scan}
                            {--list : Just list the pairs non-interactively, do not prompt}
                            {--copy-files : Copy files instead of moving them}
                            {--dry-run : Unused here; declared because the parent class ImportBooksFromDownloads reads it in inherited methods}
                            {--no-backup : Unused here; declared for the same reason}
                            {--directory=* : Unused here; declared for the same reason}
                            {--model= : Unused here; declared for the same reason}
                            {--min-confidence= : Unused here; declared for the same reason}
                            {--auto : Unused here; declared for the same reason}
                            {--limit= : Unused here; declared for the same reason}
                            {--force-include : Unused here; declared for the same reason}
                            {--skip-enrichment : Unused here; declared for the same reason}
                            {--no-cache : Unused here; declared for the same reason}
                            {--clear-cache : Unused here; declared for the same reason}
                            {--include-narrator : Unused here; declared for the same reason}
                            {--include-old : Unused here; declared for the same reason}
                            {--collection= : Unused here; declared for the same reason}
                            {--genre= : Unused here; declared for the same reason}
                            {--pattern= : Unused here; declared for the same reason}
                            {--repair-title-mismatch-date= : Unused here; declared for the same reason}
                            {--repair-expected= : Unused here; declared for the same reason}
                            {--ui= : Unused here; declared for the same reason}';

    protected $description = 'Interactively review remaining Progression Fantasy Collection Seed '
        . 'duplicate-slot leftovers: compare size/duration/narrator, optionally play with mplayer, '
        . 'and choose to keep, replace, or import-as-second-copy';

    public function handle(): int
    {
        $path = rtrim((string) ($this->option('path') ?: self::DEFAULT_PATH), '/');
        $listOnly = (bool) $this->option('list');

        if (!File::isDirectory($path)) {
            $this->error("Directory not found: {$path}");
            return self::FAILURE;
        }

        $pairs = $this->findDuplicatePairs($path);

        if (empty($pairs)) {
            $this->info('No remaining duplicate-slot pairs found.');
            return self::SUCCESS;
        }

        $this->info(count($pairs) . ' pair(s) to review.');

        foreach ($pairs as $index => $pair) {
            $this->newLine();
            $this->line("[" . ($index + 1) . '/' . count($pairs) . "] {$pair['series']} #{$pair['number']}");
            $this->renderPair($pair);

            if ($listOnly) {
                continue;
            }

            $this->reviewLoop($pair);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{series: string, number: string, title: string, author: array<int,string>,
     *   narrator: array<int,string>, seed_path: string, seed_size: int, existing_book_id: int,
     *   existing_title: string, existing_paths: array<int,string>, existing_size: ?int}>
     */
    private function findDuplicatePairs(string $path): array
    {
        $parser = new ProgressionFantasySeedParser();
        $storageRoot = rtrim((string) (config('filesystems.disks.books.root') ?? config('app.book_root')), '/');
        $pairs = [];

        foreach (File::directories($path) as $seriesDir) {
            $dirName = basename($seriesDir);
            $parsedDir = $parser->parseDirectoryName($dirName);
            $series = $parsedDir['series'];

            if (in_array($series, self::EXCLUDED_SERIES, true)) {
                continue;
            }

            $audioFiles = collect(File::files($seriesDir))
                ->filter(fn ($file) => in_array(strtolower($file->getExtension()), self::AUDIO_EXTENSIONS, true))
                ->values();

            if ($audioFiles->isEmpty()) {
                continue;
            }

            foreach ($audioFiles as $file) {
                $filePath = $file->getPathname();
                $parsedFile = $parser->parseFileName($file->getFilename(), $series);

                if ($parsedFile === null) {
                    if ($audioFiles->count() === 1) {
                        $parsedFile = ['number' => null, 'title' => $series];
                    } else {
                        continue;
                    }
                }

                if ($parsedFile['number'] === null) {
                    continue;
                }

                $primaryNumber = $this->extractPrimaryNumber($parsedFile['number']);
                if ($primaryNumber === null) {
                    continue;
                }

                $matchedBook = $this->findBookOccupyingSlot($series, $primaryNumber);
                if ($matchedBook === null) {
                    continue;
                }

                $existingDir = $storageRoot . '/' . $matchedBook->directory_path;
                $existingFiles = glob($existingDir . '/*.{m4b,mp3,m4a,M4B,MP3,M4A}', GLOB_BRACE) ?: [];
                sort($existingFiles);
                $existingTotalSize = array_sum(array_map('filesize', $existingFiles));

                $seedSize = filesize($filePath);

                // Only treat as "already confirmed identical" when comparing one file to one
                // file — a single seed file can't be byte-identical to a multi-file existing
                // book, so those always fall through to the review list.
                if (count($existingFiles) === 1 && $seedSize === $existingTotalSize
                    && hash_file('sha256', $filePath) === hash_file('sha256', $existingFiles[0])) {
                    continue; // already confirmed identical elsewhere; nothing to review
                }

                $pairs[] = [
                    'series' => $series,
                    'number' => $parsedFile['number'],
                    'title' => $parsedFile['title'],
                    'author' => $parsedDir['author'],
                    'narrator' => $parsedDir['narrator'],
                    'seed_path' => $filePath,
                    'seed_size' => $seedSize,
                    'existing_book_id' => $matchedBook->id,
                    'existing_title' => $matchedBook->title,
                    'existing_paths' => $existingFiles,
                    'existing_size' => empty($existingFiles) ? null : $existingTotalSize,
                ];
            }
        }

        return $pairs;
    }

    private function findBookOccupyingSlot(string $series, string $primaryNumber): ?Book
    {
        $withoutThe = preg_replace('/^The\s+/i', '', $series);
        $variants = array_unique([$series, 'The ' . $withoutThe, $withoutThe]);

        $query = Book::query();
        foreach ($variants as $variant) {
            $query->orWhere('directory_path', 'like', '%/' . $variant . '/%')
                ->orWhere('directory_path', 'like', $variant . '/%');
        }

        foreach ($query->get() as $candidate) {
            $lastSegment = basename((string) $candidate->directory_path);
            if (preg_match('/^(\d+(?:\.\d+)?)/', $lastSegment, $m)
                && $this->normalizeSlotNumber($m[1]) === $primaryNumber) {
                // A missing directory (data loss, not yet recovered) isn't a real duplicate to
                // review — book:import-progression-fantasy-seed will restore it automatically.
                if (!$this->bookDirectoryExists($candidate)) {
                    return null;
                }
                return $candidate;
            }
        }

        return null;
    }

    private function bookDirectoryExists(Book $book): bool
    {
        if (empty($book->directory_path)) {
            return false;
        }

        $storageRoot = rtrim((string) (config('filesystems.disks.books.root') ?? config('app.book_root')), '/');
        return File::isDirectory($storageRoot . '/' . $book->directory_path);
    }

    private function extractPrimaryNumber(string $numberString): ?string
    {
        if (preg_match('/^(\d+(?:\.\d+)?)/', $numberString, $matches)) {
            return $this->normalizeSlotNumber($matches[1]);
        }
        return null;
    }

    private function normalizeSlotNumber(string $token): string
    {
        if (str_contains($token, '.')) {
            [$intPart, $decPart] = explode('.', $token, 2);
            $intPart = ltrim($intPart, '0');
            return ($intPart === '' ? '0' : $intPart) . '.' . $decPart;
        }
        $stripped = ltrim($token, '0');
        return $stripped === '' ? '0' : $stripped;
    }

    /**
     * @param array<string, mixed> $pair
     */
    private function renderPair(array $pair): void
    {
        $existingBook = Book::with('narrators')->find($pair['existing_book_id']);
        $existingNarrators = $existingBook !== null
            ? $existingBook->narrators->pluck('name')->implode(', ')
            : '(unknown)';
        $seedNarrators = implode(', ', $pair['narrator']) ?: '(unknown)';

        $seedDuration = $this->getImportService()->getAudioFileDuration($pair['seed_path']);
        $existingPaths = $pair['existing_paths'];
        $existingDuration = array_sum(array_map(
            fn ($path) => $this->getImportService()->getAudioFileDuration($path),
            $existingPaths
        ));

        $this->line('  SEED     : ' . $pair['title']);
        $this->line('    path     : ' . $pair['seed_path']);
        $this->line('    files    : 1');
        $this->line('    size     : ' . $this->formatBytesLabel($pair['seed_size']));
        $this->line('    duration : ' . $this->formatDuration($seedDuration));
        $this->line('    narrator : ' . $seedNarrators);
        $this->line('  EXISTING #' . $pair['existing_book_id'] . ': ' . $pair['existing_title']);
        $this->line('    path     : ' . (empty($existingPaths) ? '(no audio file found)' : implode(', ', array_map('basename', $existingPaths))));
        $this->line('    files    : ' . count($existingPaths));
        $this->line('    size     : ' . ($pair['existing_size'] !== null ? $this->formatBytesLabel($pair['existing_size']) : 'n/a'));
        $this->line('    duration : ' . (!empty($existingPaths) ? $this->formatDuration($existingDuration) : 'n/a'));
        $this->line('    narrator : ' . $existingNarrators);

        if ($seedDuration > 0 && $existingDuration > 0) {
            $diffSeconds = abs($seedDuration - $existingDuration);
            $diffPct = round(($diffSeconds / max($seedDuration, $existingDuration)) * 100, 1);
            $this->line("    duration diff: {$diffPct}% (" . $this->formatDuration($diffSeconds) . ')');
        }
    }

    /**
     * @param array<string, mixed> $pair
     */
    private function reviewLoop(array $pair): void
    {
        $options = [
            'p' => 'Play seed (mplayer)',
            'e' => 'Play existing (mplayer)',
            'd' => 'Delete seed file, keep existing as-is',
            'r' => 'Replace: move seed file into existing book (same ID), confirming any field differences',
            'i' => 'Import seed as a second, distinctly-named copy (keep both)',
            's' => 'Skip for now',
        ];

        while (true) {
            $this->line('  Options: ' . implode('  ', array_map(
                fn ($k, $v) => "[{$k}] {$v}",
                array_keys($options),
                $options
            )));
            $key = strtolower(trim($this->promptRaw('Action [s]: ')));
            if ($key === '') {
                $key = 's';
            }

            if (!isset($options[$key])) {
                $this->warn('Unrecognized choice, skipping.');
                return;
            }

            switch ($key) {
                case 'p':
                    $this->play([$pair['seed_path']]);
                    break;
                case 'e':
                    if (!empty($pair['existing_paths'])) {
                        $this->play($pair['existing_paths']);
                    } else {
                        $this->warn('No existing audio file to play.');
                    }
                    break;
                case 'd':
                    File::delete($pair['seed_path']);
                    $this->info('Deleted seed file.');
                    return;
                case 'r':
                    $this->replaceExisting($pair);
                    return;
                case 'i':
                    $this->importAsSecondCopy($pair);
                    return;
                case 's':
                default:
                    return;
            }
        }
    }

    /**
     * @param array<int, string> $filePaths Played in order; mplayer advances to the next file
     *   on its own when one finishes, so multi-file books play back-to-back.
     */
    private function play(array $filePaths): void
    {
        $this->info('Playing (q to stop, space to pause, arrows to seek, > / < to skip track): '
            . implode(', ', array_map('basename', $filePaths)));
        $escaped = implode(' ', array_map('escapeshellarg', $filePaths));
        passthru('mplayer ' . $escaped);
    }

    /**
     * Read a line directly from STDIN. ImportBooksFromDownloads overrides ask()/choice() to
     * route through $this->uiService (the ncurses/hybrid UI), which this command never
     * initializes — using the inherited ask() would crash with "Call to a member function
     * ask() on null".
     */
    private function promptRaw(string $prompt): string
    {
        fwrite(STDOUT, $prompt);
        $line = fgets(STDIN);
        return $line === false ? '' : trim($line);
    }

    /**
     * Move the seed audio file into the EXISTING book's directory, keeping the same book ID —
     * no trash, no re-creation. Any metadata field that differs between what's currently stored
     * and what the seed/enrichment suggests is shown to the user, who picks which value to keep.
     *
     * @param array<string, mixed> $pair
     */
    private function replaceExisting(array $pair): void
    {
        $book = Book::with(['authors', 'narrators', 'series'])->find($pair['existing_book_id']);
        if ($book === null) {
            $this->error("Book #{$pair['existing_book_id']} not found.");
            return;
        }

        $seedMetadata = $this->buildSeedMetadata($pair);
        $resolved = $this->resolveMetadataDifferences($book, $seedMetadata);

        // The seed is always a single consolidated file. If the existing book was split across
        // several chapter files in the same directory, remove them first — otherwise the old
        // chapters and the new combined file would end up coexisting side by side.
        foreach ($pair['existing_paths'] as $oldFile) {
            if (File::exists($oldFile)) {
                File::delete($oldFile);
            }
        }
        if (count($pair['existing_paths']) > 1) {
            $this->info('Removed ' . count($pair['existing_paths']) . ' old chapter file(s) before replacing.');
        }

        $audiobook = [
            'path' => dirname($pair['seed_path']),
            'display_path' => $pair['seed_path'],
            'name' => $resolved['title'],
            'files' => [$pair['seed_path']],
            'total_size' => filesize($pair['seed_path']),
            'is_multi_book_part' => true,
            'multi_book_files_only' => [$pair['seed_path']],
        ];

        $updated = $this->getImportService()->updateBookFromMetadata($book, $resolved, $audiobook);
        $this->getImportService()->moveFilesToLibrary($audiobook, $updated, [
            'operation' => $this->getFileOperation(),
        ]);
        $this->getImportService()->processCoverImage($updated, $resolved);

        $this->info("Updated book #{$updated->id} in place: {$updated->title}");
    }

    /**
     * Parse + enrich the seed file into a metadata array, the same shape createBookFromMetadata
     * expects, without touching any Book record yet.
     *
     * @param array<string, mixed> $pair
     * @return array<string, mixed>
     */
    private function buildSeedMetadata(array $pair): array
    {
        $this->getImportService()->setConfig([
            'genre' => self::GENRE,
            'collection' => self::COLLECTION_NAME,
        ]);

        $metadata = [
            'title' => $pair['title'],
            'author' => $pair['author'],
            'narrator' => $pair['narrator'],
            'series' => $pair['series'],
            'series_number' => $pair['number'],
            'genre' => self::GENRE,
        ];

        $fileTags = (new AIBookProcessor())->extractFileTags($pair['seed_path']);
        if (!empty($fileTags['picture']['data'])) {
            $metadata['cover_data'] = $fileTags['picture']['data'];
        }

        $enriched = $this->enrichWithExternalData($metadata);
        if ($enriched) {
            $metadata = array_merge($metadata, $enriched);
        }

        return $metadata;
    }

    /**
     * For each field where the seed's value differs from what's already stored on $book, ask the
     * user which one to keep. Genre is not offered — this whole collection is intentionally
     * forced to LitRPG. Returns a metadata array with the user's choices applied, ready for
     * updateBookFromMetadata().
     *
     * @param array<string, mixed> $seedMetadata
     * @return array<string, mixed>
     */
    private function resolveMetadataDifferences(Book $book, array $seedMetadata): array
    {
        $resolved = $seedMetadata;

        $fields = [
            'title' => [$book->title, $seedMetadata['title'] ?? null],
            'description' => [$book->description, $seedMetadata['description'] ?? null],
            'author' => [$book->authors->pluck('name')->all(), $seedMetadata['author'] ?? []],
            'narrator' => [$book->narrators->pluck('name')->all(), $seedMetadata['narrator'] ?? []],
        ];

        $existingSeries = $book->series->first();
        $existingSeriesNumber = $existingSeries?->pivot?->getAttribute('series_number');
        $fields['series_number'] = [$existingSeriesNumber, $seedMetadata['series_number'] ?? null];

        foreach ($fields as $field => [$existingValue, $seedValue]) {
            if ($this->valuesEqual($existingValue, $seedValue)) {
                continue;
            }
            if ($seedValue === null || $seedValue === '' || $seedValue === []) {
                continue; // nothing new to offer
            }

            $this->line("  Field \"{$field}\" differs:");
            $this->line('    [e] existing: ' . $this->describeValue($existingValue));
            $this->line('    [s] seed    : ' . $this->describeValue($seedValue));
            $choice = strtolower(trim($this->promptRaw('  Keep which? [e/s, default e]: ')));

            if ($choice === 's') {
                $resolved[$field] = $seedValue;
            } else {
                $resolved[$field] = $existingValue;
            }
        }

        return $resolved;
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            $a = array_map('strval', (array) $a);
            $b = array_map('strval', (array) $b);
            sort($a);
            sort($b);
            return $a === $b;
        }

        return (string) $a === (string) $b;
    }

    private function describeValue(mixed $value): string
    {
        if (is_array($value)) {
            return empty($value) ? '(none)' : implode(', ', $value);
        }

        return $value === null || $value === '' ? '(none)' : (string) $value;
    }

    /**
     * @param array<string, mixed> $pair
     */
    private function importAsSecondCopy(array $pair): void
    {
        $this->importSeedFile(
            $pair,
            forceNew: true,
            titleSuffix: ' (Alt Version)',
            includeNarratorInDirName: $this->narratorsDiffer($pair)
        );
    }

    /**
     * @param array<string, mixed> $pair
     */
    private function narratorsDiffer(array $pair): bool
    {
        $book = Book::with('narrators')->find($pair['existing_book_id']);
        $existingNarrators = $book !== null ? $book->narrators->pluck('name')->all() : [];

        return !$this->valuesEqual($existingNarrators, $pair['narrator']);
    }

    /**
     * @param array<string, mixed> $pair
     */
    private function importSeedFile(
        array $pair,
        bool $forceNew,
        string $titleSuffix = '',
        bool $includeNarratorInDirName = false
    ): void {
        $metadata = $this->buildSeedMetadata($pair);
        $metadata['title'] .= $titleSuffix;
        if ($forceNew) {
            $metadata['_force_rename_directory'] = true;
        }

        // Distinguish this copy's folder from the existing one by appending its narrator, e.g.
        // "01 Title (Narrator Name)" — only when the two versions actually have different
        // narrators. setConfig() replaces the whole config array rather than merging, so genre
        // and collection must be repeated here too; reset afterward so this doesn't leak into
        // other imports in this same run.
        $this->getImportService()->setConfig([
            'genre' => self::GENRE,
            'collection' => self::COLLECTION_NAME,
            'include_narrator' => $includeNarratorInDirName,
        ]);

        $audiobook = [
            'path' => dirname($pair['seed_path']),
            'display_path' => $pair['seed_path'],
            'name' => $metadata['title'],
            'files' => [$pair['seed_path']],
            'total_size' => filesize($pair['seed_path']),
            'is_multi_book_part' => true,
            'multi_book_files_only' => [$pair['seed_path']],
        ];

        try {
            $book = $this->getImportService()->processSingleBook(
                $audiobook,
                $metadata,
                fn ($metadata) => $this->enrichWithExternalData($metadata),
                fn ($metadata, $enrichedData) => $this->getEnrichmentService()->isValidEnrichment($metadata, $enrichedData),
                fn ($metadata) => $this->getImportService()->generateDirectoryPath($metadata),
                fn ($metadata, $audiobook) => $this->getImportService()->createBookFromMetadata($metadata, $audiobook),
                fn ($audiobook, $book, $options) => $this->getImportService()->moveFilesToLibrary($audiobook, $book, $options),
                fn () => $this->getFileOperation(),
                fn ($message) => $this->info("  {$message}"),
                null,
                null,
                null,
                true,
                true,
                false
            );
        } finally {
            // Never let this leak into the next pair processed in this same run.
            $this->getImportService()->setConfig([
                'genre' => self::GENRE,
                'collection' => self::COLLECTION_NAME,
                'include_narrator' => false,
            ]);
        }

        if ($book !== null) {
            $this->getImportService()->processCoverImage($book, $metadata);
            $this->info("Imported: {$book->title} (ID: {$book->id})");
        } else {
            $this->error('Import failed.');
        }
    }

    private function formatBytesLabel(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unitIndex = 0;
        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }
        return round($value, 1) . ' ' . $units[$unitIndex];
    }

    private function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        return sprintf('%dh %02dm %02ds', $hours, $minutes, $secs);
    }
}
