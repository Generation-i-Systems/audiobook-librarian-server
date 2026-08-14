<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\AIBookProcessor;
use App\Services\ProgressionFantasySeedParser;
use Illuminate\Support\Facades\File;

/**
 * One-off importer for "Progression Fantasy Collection Seed": deterministically parses
 * "[series] - [author] - [narrator]" folder names and "[series] - Book [number]{ - [title]}"
 * file names, forces genre=LitRPG, and reuses the existing single-book import pipeline
 * (enrichment + DB write + file move) instead of AI-based metadata detection.
 */
class ImportProgressionFantasyCollectionSeed extends ImportBooksFromDownloads
{
    private const AUDIO_EXTENSIONS = ['m4b', 'mp3', 'm4a'];
    private const DEFAULT_PATH = '/media/lyra_data1/audiobooks/unsorted/Progression Fantasy Collection Seed';
    private const COLLECTION_NAME = 'Progression Fantasy Collection Seed';
    private const GENRE = 'LitRPG';

    /**
     * Series excluded from this automated run, needing manual/interactive handling instead:
     * - Legend of the Arch Magus: existing entries use positional index, not book-range label,
     *   making automatic slot-matching unreliable.
     * - Divine Dungeon: already fully present in the library under "The Divine Dungeon" (slot
     *   matching missed it due to the "The" prefix) AND has a separate orphaned on-disk-only
     *   directory (not in the books table) with old Audiobookshelf-style metadata for books 2-5 —
     *   too tangled to resolve automatically.
     *
     * @var array<int, string>
     */
    private const EXCLUDED_SERIES = [
        'Legend of the Arch Magus',
        'Divine Dungeon',
    ];

    protected $signature = 'book:import-progression-fantasy-seed
                            {path?* : Unused here; declared because the parent class ImportBooksFromDownloads reads it in inherited methods}
                            {--path= : Override the collection directory to scan}
                            {--series=* : Only process series whose folder name matches exactly (repeatable)}
                            {--dry-run : Show what would be imported without making changes}
                            {--copy-files : Copy files instead of moving them}
                            {--no-backup : Skip automatic database backup}
                            {--directory=* : Unused here; declared because the parent class ImportBooksFromDownloads reads it in inherited methods}
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

    protected $description = 'Import the "Progression Fantasy Collection Seed" batch using '
        . 'hardcoded directory/file-name pattern parsing (genre forced to LitRPG)';

    public function handle(): int
    {
        $path = rtrim((string) ($this->option('path') ?: self::DEFAULT_PATH), '/');
        $isDryRun = (bool) $this->option('dry-run');

        if (!File::isDirectory($path)) {
            $this->error("Directory not found: {$path}");
            return self::FAILURE;
        }

        if (!$isDryRun && !$this->option('no-backup')) {
            $this->info('Creating a database backup before importing books...');
            $this->callSilent('backup:database', ['--suffix' => 'import-progression-fantasy-seed']);
        }

        $this->getImportService()->setConfig([
            'genre' => self::GENRE,
            'collection' => self::COLLECTION_NAME,
        ]);

        $parser = new ProgressionFantasySeedParser();
        $seriesFilter = array_map('mb_strtolower', $this->option('series'));

        $imported = 0;
        $skipped = [];
        $skippedExisting = [];
        $failed = [];
        $occupiedSlotsBySeries = [];

        foreach (File::directories($path) as $seriesDir) {
            $dirName = basename($seriesDir);
            $parsedDir = $parser->parseDirectoryName($dirName);
            $series = $parsedDir['series'];

            if (!empty($seriesFilter) && !in_array(mb_strtolower($series), $seriesFilter, true)) {
                continue;
            }

            $audioFiles = collect(File::files($seriesDir))
                ->filter(fn ($file) => in_array(strtolower($file->getExtension()), self::AUDIO_EXTENSIONS, true))
                ->values();

            if ($audioFiles->isEmpty()) {
                $this->warn("📚 {$series}: no audio files found, skipping");
                continue;
            }

            if (in_array($series, self::EXCLUDED_SERIES, true)) {
                $this->warn("📚 {$series}: excluded from this run (unreliable slot-matching), skipping all {$audioFiles->count()} file(s)");
                continue;
            }

            $this->info("📚 {$series} ({$audioFiles->count()} file(s))");

            foreach ($audioFiles as $file) {
                $filePath = $file->getPathname();
                $fileName = $file->getFilename();

                $parsedFile = $parser->parseFileName($fileName, $series);

                if ($parsedFile === null) {
                    if ($audioFiles->count() === 1) {
                        $parsedFile = ['number' => null, 'title' => $series];
                    } else {
                        $skipped[] = $filePath;
                        $this->warn("  ⚠️  Skipping unmatched file: {$fileName}");
                        continue;
                    }
                }

                $metadata = [
                    'title' => $parsedFile['title'],
                    'author' => $parsedDir['author'],
                    'narrator' => $parsedDir['narrator'],
                    'series' => $parsedFile['number'] !== null ? $series : null,
                    'series_number' => $parsedFile['number'],
                    'genre' => self::GENRE,
                ];

                $audiobook = [
                    'path' => $seriesDir,
                    'display_path' => $filePath,
                    'name' => $parsedFile['title'],
                    'files' => [$filePath],
                    'total_size' => $file->getSize(),
                    'is_multi_book_part' => true,
                    // REQUIRED: without this, moveFilesToLibrary() ignores 'files' and copies/moves
                    // the *entire* shared parent series directory (every book's audio) into this
                    // book's target folder, since is_multi_book_part alone isn't enough to trigger
                    // its "only move these specific files" branch.
                    'multi_book_files_only' => [$filePath],
                ];

                if ($parsedFile['number'] !== null) {
                    // Series book: match by series + book-number slot, not title text — several
                    // existing entries are mistitled from a prior bad AI import, so title/author
                    // text matching (findExistingBook()'s usual path) misses real duplicates here.
                    if (!array_key_exists($series, $occupiedSlotsBySeries)) {
                        $occupiedSlotsBySeries[$series] = $this->findOccupiedSeriesSlots($series);
                    }

                    $primaryNumber = $this->extractPrimaryNumber($parsedFile['number']);
                    if ($primaryNumber !== null && in_array($primaryNumber, $occupiedSlotsBySeries[$series], true)) {
                        $skippedExisting[] = "{$series} #{$parsedFile['number']} (parsed as \"{$metadata['title']}\")";
                        $this->line("  ⏭️  Slot already occupied, skipping: {$metadata['title']}");
                        continue;
                    }
                } else {
                    // Standalone (non-series) book: fall back to the normal title+author match.
                    // A missing directory (data loss, not yet recovered) isn't a real conflict.
                    $existingBook = $this->getImportService()->findExistingBook($seriesDir, $metadata);
                    if ($existingBook !== null && $this->bookDirectoryExists($existingBook)) {
                        $skippedExisting[] = "#{$existingBook->id} \"{$existingBook->title}\" (parsed as \"{$metadata['title']}\")";
                        $this->line("  ⏭️  Already exists (#{$existingBook->id}), skipping: {$metadata['title']}");
                        continue;
                    }
                }

                try {
                    $book = $this->processSingleBookForSeed($audiobook, $metadata, $isDryRun);
                    if ($book !== null || $isDryRun) {
                        $imported++;
                    }
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    // Other book:import processes run concurrently on this system; if a name
                    // collision persists across all retries, don't let one book take down the
                    // whole batch — flag it for a follow-up pass and keep going.
                    $failed[] = "{$metadata['title']} ({$filePath}): {$e->getMessage()}";
                    $this->error("  ❌ Failed after retries, skipping: {$metadata['title']}");
                }
            }
        }

        $this->newLine();
        $this->info(($isDryRun ? '[DRY RUN] Would import' : 'Imported') . " {$imported} book(s).");

        if (!empty($skippedExisting)) {
            $this->line(count($skippedExisting) . ' already-existing book(s) left untouched:');
            foreach ($skippedExisting as $label) {
                $this->line("  - {$label}");
            }
        }

        if (!empty($skipped)) {
            $this->warn(count($skipped) . ' file(s) skipped (unmatched pattern):');
            foreach ($skipped as $path) {
                $this->line("  - {$path}");
            }
        }

        if (!empty($failed)) {
            $this->error(count($failed) . ' file(s) failed and need a follow-up pass:');
            foreach ($failed as $label) {
                $this->line("  - {$label}");
            }
        }

        return self::SUCCESS;
    }

    private function processSingleBookForSeed(array $audiobook, array $metadata, bool $isDryRun): ?Book
    {
        // Enrich and extract embedded cover art ourselves (rather than letting
        // processSingleBook() do enrichment internally) so we have the fully-merged
        // metadata available afterward to hand to processCoverImage() — processSingleBook()
        // never calls it (that step lives in the AI orchestrator's single-book path only),
        // so without this, none of these books would get a cover.
        //
        // Embedded cover art: BookImportService::extractFileTagsFromFiles() only reads text
        // tags (title/artist/album) — it never reads the embedded picture stream. The picture
        // is read via AIBookProcessor::extractFileTags(), which is what the standard AI import
        // path uses for covers; that's a plain getID3 wrapper with no AI/network call involved.
        $fileTags = (new AIBookProcessor())->extractFileTags($audiobook['files'][0]);
        if (!empty($fileTags['picture']['data'])) {
            $metadata['cover_data'] = $fileTags['picture']['data'];
        }

        // External enrichment (description/year/publisher, and cover_url as a fallback when
        // there's no embedded cover) is flaky under rapid back-to-back calls (observed transient
        // failures), so retry a couple of times before giving up on it entirely.
        $needsCoverUrl = empty($metadata['cover_data']);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $enriched = $this->enrichWithExternalData($metadata);
            $gotDescription = !empty($enriched['description']);
            $gotCoverIfNeeded = !$needsCoverUrl || !empty($enriched['cover_url']);
            if ($gotDescription && $gotCoverIfNeeded) {
                $metadata = array_merge($metadata, $enriched);
                break;
            }
            if ($attempt === 3 && $enriched) {
                // Last attempt: take whatever we got rather than nothing.
                $metadata = array_merge($metadata, $enriched);
                break;
            }
            usleep(500000);
        }

        // Other book:import processes run concurrently on this system against the same
        // "unsorted" tree, and Author/Narrator::firstOrCreate() (select-then-insert, not
        // atomic) can lose a race to one of them. Retry a few times rather than crash the
        // whole batch over a transient unique-constraint collision.
        $book = null;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $book = $this->getImportService()->processSingleBook(
                    $audiobook,
                    $metadata,
                    fn ($metadata) => $this->enrichWithExternalData($metadata),
                    fn ($metadata, $enrichedData) => $this->getEnrichmentService()->isValidEnrichment($metadata, $enrichedData),
                    fn ($metadata) => $this->getImportService()->generateDirectoryPath($metadata),
                    fn ($metadata, $audiobook) => $this->getImportService()->createBookFromMetadata($metadata, $audiobook),
                    // No directory/file-conflict callbacks: those (handleDirectoryConflict/
                    // handleFileConflict) call into $this->uiService, which this non-interactive
                    // command never initializes. Passing null lets moveFilesToLibraryInternal
                    // fall back to its own automatic, non-interactive conflict resolution.
                    fn ($audiobook, $book, $options) => $this->getImportService()->moveFilesToLibrary(
                        $audiobook,
                        $book,
                        $options
                    ),
                    fn () => $this->getFileOperation(),
                    fn ($message) => $this->info("  {$message}"),
                    null,
                    null,
                    // Metadata here is deterministically parsed from directory/file names, not
                    // AI-guessed, so we don't want processSingleBook's "no enrichment data found
                    // -> skip" auto-mode safety check (it exists to catch likely-wrong AI
                    // guesses; most of these obscure webfiction titles simply won't be on
                    // Audible/Google Books).
                    null,
                    true,
                    true,
                    $isDryRun
                );
                break;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt === 5) {
                    throw $e;
                }
                usleep($attempt * 500000);
            }
        }

        if ($book !== null && !$isDryRun) {
            $this->getImportService()->processCoverImage($book, $metadata);
        }

        return $book;
    }

    private function bookDirectoryExists(Book $book): bool
    {
        if (empty($book->directory_path)) {
            return false;
        }

        $storageRoot = rtrim((string) (config('filesystems.disks.books.root') ?? config('app.book_root')), '/');
        return File::isDirectory($storageRoot . '/' . $book->directory_path);
    }

    /**
     * Book-number slots already occupied by existing library books for this series, found via
     * the real storage convention "{Genre}/{Author}/{Series}/{Number} {Title}" — matching on the
     * series path segment rather than title text, since several existing entries are mistitled.
     * Slots are normalized strings (leading zeros stripped from the integer part only, decimal
     * digits left untouched) rather than floats, so e.g. "00.1" and "00.10" (side stories 1 and
     * 10) never collapse onto the same value.
     *
     * @return array<int, string>
     */
    private function findOccupiedSeriesSlots(string $series): array
    {
        // Also check with/without a leading "The " — several existing entries are stored under
        // a "The X" variant of the parsed series name (e.g. "Divine Dungeon" vs. "The Divine
        // Dungeon"), which an exact match would silently miss.
        $withoutThe = preg_replace('/^The\s+/i', '', $series);
        $seriesVariants = array_unique([$series, 'The ' . $withoutThe, $withoutThe]);

        $query = Book::query();
        foreach ($seriesVariants as $variant) {
            $query->orWhere('directory_path', 'like', '%/' . $variant . '/%')
                ->orWhere('directory_path', 'like', $variant . '/%');
        }
        $existing = $query->pluck('directory_path');
        $storageRoot = rtrim((string) (config('filesystems.disks.books.root') ?? config('app.book_root')), '/');

        $slots = [];
        foreach ($existing as $directoryPath) {
            // A book whose directory no longer exists on disk (data loss, not yet recovered)
            // isn't a real conflict — don't block a fresh import from restoring it.
            if (!File::isDirectory($storageRoot . '/' . $directoryPath)) {
                continue;
            }

            $lastSegment = basename((string) $directoryPath);
            if (preg_match('/^(\d+(?:\.\d+)?)/', $lastSegment, $matches)) {
                $slots[] = $this->normalizeSlotNumber($matches[1]);
            }
        }

        return array_values(array_unique($slots));
    }

    /**
     * The primary (first) numeric token out of a possibly-combined series number, normalized
     * ("13-14" -> "13", "1+2" -> "1", "3, 4" -> "3", "14.5" -> "14.5", "00.10" -> "0.10").
     */
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
}
