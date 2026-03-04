<?php

/**
 * Fix and import Very Short Introductions (VSI) books.
 *
 * Part A — Fix existing books already in Non Fiction/VA/:
 *   1. Moves books into Non Fiction/VA/Very Short Introductions/[Title] ([Author]).
 *   2. Fixes titles that were imported as "A Very Short Introduction".
 *   3. Creates "Very Short Introductions" collection and links all books.
 *   4. Updates DB directory_path, title, series links, and librarian.json.
 *
 * Part B — Import new books from download directory:
 *   Source: /media/lyra_data/download/Bolinda and Oxford Very Short Introductions/
 *   Two source formats:
 *     - Subdirectories (multi-file): dirname is the title, ID3 artist tag = author.
 *     - Flat mp3 files: filename is the title, no author available.
 *   Skips any book whose normalised title already exists in the VSI library.
 *   Moves source files into Non Fiction/VA/Very Short Introductions/[Title] ([Author])/.
 *
 * Usage:
 *   php scripts/fix_vsi_books.php [--dry-run] [--import-only] [--fix-only]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Author;
use App\Models\Book;
use App\Models\Series;
use App\Services\BookImportService;
use Illuminate\Support\Facades\DB;

$dryRun      = in_array('--dry-run', $argv, true);
$importOnly  = in_array('--import-only', $argv, true);
$fixOnly     = in_array('--fix-only', $argv, true);

if ($dryRun) {
    echo "[DRY RUN] No changes will be written.\n\n";
}

$bookRoot           = config('app.book_root', '/media/lyra_data1/audiobooks/books');
$vsiCollectionName  = 'Very Short Introductions';
$targetSubDir       = 'Non Fiction/VA/Very Short Introductions';
$downloadSourceDir  = '/media/lyra_data/download/Bolinda and Oxford Very Short Introductions';

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Extract the real book title from the audio filename.
 * Audio filenames often look like: "Title_ A Very Short Introduction.m4b"
 * or "Author - Title A Very Short Introduction.mp3"
 */
function stripVsiSuffix(string $base): string
{
    // Remove "A Very Short Intro[duction[s]]..." with any separator (or none) before it
    $base = preg_replace('/[\s:,()\-]*\bA Very Short Intro\w*\b.*$/i', '', $base);
    // Remove bare "Very Short Intro[duction[s]]" suffix with any separator before it
    // (covers "Philosophy of Science Very Short Introduction")
    $base = preg_replace('/[\s:,()\-]+Very Short Intro\w*.*$/i', '', $base);
    // Strip leftover trailing separators: " -", ":", ","
    $base = preg_replace('/[\s:,\-]+$/', '', $base);
    return $base;
}

function extractTitleFromFilename(string $filename): ?string
{
    // Remove extension
    $base = pathinfo($filename, PATHINFO_FILENAME);

    // Replace underscores with spaces and normalise
    $base = str_replace('_', ' ', $base);
    $base = preg_replace('/\s+/', ' ', trim($base));

    // Remove numeric prefix like "7 - "
    $base = preg_replace('/^\d+\s*-\s*/', '', $base);

    // Strip the VSI suffix first so we can check if anything meaningful remains
    $stripped = stripVsiSuffix($base);
    $stripped = preg_replace('/\s+/', ' ', trim($stripped));

    // If stripping VSI left us with nothing or the whole string was VSI, bail
    if (strlen($stripped) < 2) {
        return null;
    }

    // Strip author prefix from the VSI-stripped result: "Firstname [M.] Lastname - Title"
    // Also handles "Author & Author - Title" — match up to the " - " separator
    if (preg_match('/^.+\s+-\s+(.+)$/u', $stripped, $m)) {
        // Only treat as author prefix if the part before " - " looks like a name
        // (contains at least one capital letter sequence, no lowercase-start words except conjunctions)
        $prefix = substr($stripped, 0, strlen($stripped) - strlen($m[1]) - 3);
        if (preg_match('/^[A-Z][^\-]+$/u', trim($prefix))) {
            $stripped = $m[1];
        }
    }

    $stripped = preg_replace('/\s+/', ' ', trim($stripped));

    if (strlen($stripped) < 2) {
        return null;
    }

    return $stripped;
}

/**
 * Extract the real book title from the description text.
 */
function extractTitleFromDescription(string $desc): ?string
{
    // "TITLE: A Very Short Introduction..."
    if (preg_match('/^(.+?):\s*A Very Short Introduction/i', $desc, $m)) {
        return trim($m[1]);
    }
    // "In TITLE: A Very Short Introduction, ..."
    if (preg_match('/^In (.+?):\s*A Very Short Introduction/i', $desc, $m)) {
        return trim($m[1]);
    }
    // "TITLE. A Very Short Introduction ..." (period separator)
    if (preg_match('/^(.+?)\.\s+A Very Short Introduction/i', $desc, $m)) {
        $candidate = trim($m[1]);
        // Only accept if it looks like a title (no lowercase start after first word)
        if (strlen($candidate) < 80) {
            return $candidate;
        }
    }
    // "This Very Short Introduction to SUBJECT is..."
    if (preg_match('/^This Very Short Introduction (?:to|on) (.+?) (?:is|from|has|provides|offers)/i', $desc, $m)) {
        return ucfirst(trim($m[1]));
    }

    return null;
}

/**
 * Sanitize a string for use as a filesystem component.
 */
function sanitizeForFilesystem(string $name): string
{
    return preg_replace('/[\/\\\\:*?"<>|]/', '_', $name);
}

/**
 * Parse author(s) from a directory name like "Title (Author Name)" → "Author Name"
 */
function parseAuthorFromDir(string $dirName): string
{
    if (preg_match('/\(([^)]+)\)\s*$/', $dirName, $m)) {
        return trim($m[1]);
    }
    return '';
}

// ── Get or create VSI collection ─────────────────────────────────────────────

if (!$dryRun) {
    $vsiSeries = Series::firstOrCreate(
        ['name' => $vsiCollectionName],
        ['is_collection' => true]
    );

    if (!$vsiSeries->is_collection) {
        $vsiSeries->is_collection = true;
        $vsiSeries->save();
    }
    echo "VSI Collection: id={$vsiSeries->id} name=\"{$vsiSeries->name}\"\n\n";
} else {
    $vsiSeries = Series::where('name', $vsiCollectionName)->first();
    if ($vsiSeries) {
        echo "[dry-run] VSI Collection exists: id={$vsiSeries->id}\n\n";
    } else {
        echo "[dry-run] VSI Collection would be created: \"{$vsiCollectionName}\"\n\n";
    }
}

// ── Find all VSI books ────────────────────────────────────────────────────────

$books = Book::with(['series', 'authors'])
    ->where('directory_path', 'like', '%Non Fiction/VA/%')
    ->orderBy('id')
    ->get();

echo "Found " . $books->count() . " books to process.\n\n";

$fixed = 0;
$skipped = 0;
$needsUserInput = [];

foreach ($books as $book) {
    $bookId = $book->id;
    $currentTitle = $book->title ?? '';
    $currentDirPath = $book->directory_path ?? '';
    $currentDesc = $book->description ?? '';
    $currentFullDir = $bookRoot . '/' . $currentDirPath;

    // Already in the VSI sub-collection directory — just needs series link + possible title fix
    $alreadyMoved = str_contains($currentDirPath, 'Non Fiction/VA/Very Short Introductions/');

    // ── Step 1: Determine the correct title ──────────────────────────────────

    $titleNeedsFix = (
        $currentTitle === 'A Very Short Introduction'
        || preg_match('/^A Very Short Introduction[,\s]/i', $currentTitle)
        || $currentTitle === 'A Very Short Introduction, 2nd Edition'
    );

    $newTitle = $currentTitle;

    if ($titleNeedsFix) {
        // Try audio filename first
        $fileTags = $book->file_tags ?? [];
        $audioFilename = is_array($fileTags) ? (array_key_first($fileTags) ?? '') : '';
        $fromFile = $audioFilename ? extractTitleFromFilename($audioFilename) : null;

        // Try description
        $fromDesc = $currentDesc ? extractTitleFromDescription($currentDesc) : null;

        if ($fromFile && strlen($fromFile) > 2 && !preg_match('/^(this|the|a|in|an)\s+very\s+short/i', $fromFile)) {
            $newTitle = $fromFile;
        } elseif ($fromDesc && strlen($fromDesc) > 2) {
            $newTitle = $fromDesc;
        } else {
            // Cannot determine automatically — queue for user input
            $needsUserInput[] = [
                'book'          => $book,
                'audioFilename' => $audioFilename,
                'dirPath'       => $currentDirPath,
            ];
            continue;
        }
    }

    // ── Step 2: Determine the author string (from directory name) ────────────

    $dirBasename = basename($currentDirPath);
    $authorStr = parseAuthorFromDir($dirBasename);
    if (empty($authorStr) && $book->authors->isNotEmpty()) {
        $authorStr = $book->authors->first()->name;
    }

    // ── Step 3: Determine new directory path ────────────────────────────────

    $safeTitleDir = sanitizeForFilesystem($newTitle);
    $safeAuthorDir = sanitizeForFilesystem($authorStr);
    $newDirName = $safeAuthorDir ? "{$safeTitleDir} ({$safeAuthorDir})" : $safeTitleDir;
    $newDirPath = "Non Fiction/VA/Very Short Introductions/{$newDirName}";
    $newFullDir = $bookRoot . '/' . $newDirPath;

    $dirChanged = ($currentDirPath !== $newDirPath);
    $titleChanged = ($currentTitle !== $newTitle);

    // Check if book already has VSI series
    $hasVsiSeries = $vsiSeries && $book->series->contains('id', $vsiSeries->id);

    if (!$dirChanged && !$titleChanged && $hasVsiSeries) {
        echo "[{$bookId}] Already correct, skipping.\n";
        $skipped++;
        continue;
    }

    echo "── [{$bookId}] ─────────────────────────────────────────────────────────\n";
    if ($titleChanged) {
        echo "  title:  \"{$currentTitle}\" → \"{$newTitle}\"\n";
    } else {
        echo "  title:  \"{$currentTitle}\" (unchanged)\n";
    }
    if ($dirChanged) {
        echo "  dir:    \"{$currentDirPath}\"\n";
        echo "        → \"{$newDirPath}\"\n";
    } else {
        echo "  dir:    \"{$currentDirPath}\" (unchanged)\n";
    }
    if (!$hasVsiSeries) {
        echo "  series: will link to \"{$vsiCollectionName}\"\n";
    }

    if ($dryRun) {
        echo "  [dry-run] Would apply changes.\n";
        $fixed++;
        continue;
    }

    // ── Step 4: Move the directory on disk ────────────────────────────────────

    if ($dirChanged) {
        // Ensure target parent exists
        $parentDir = $bookRoot . '/Non Fiction/VA/Very Short Introductions';
        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true)) {
            echo "  ERROR: Could not create parent directory {$parentDir}\n";
            $skipped++;
            continue;
        }

        if (is_dir($currentFullDir)) {
            if (is_dir($newFullDir)) {
                echo "  WARN: Target directory already exists: {$newFullDir} — skipping move\n";
            } else {
                if (!rename($currentFullDir, $newFullDir)) {
                    echo "  ERROR: Could not rename directory.\n";
                    $skipped++;
                    continue;
                }
                echo "  → Moved directory on disk.\n";
            }
        } else {
            echo "  WARN: Source directory not found on disk: {$currentFullDir}\n";
        }
    }

    // ── Step 5: Update librarian.json ─────────────────────────────────────────

    $workingDir = $dirChanged ? $newFullDir : $currentFullDir;
    $jsonPath = $workingDir . '/librarian.json';

    if (file_exists($jsonPath)) {
        $jsonData = json_decode(file_get_contents($jsonPath), true) ?? [];
        $jsonChanged = false;

        if ($titleChanged) {
            $jsonData['title'] = $newTitle;
            $jsonChanged = true;
        }
        if ($dirChanged) {
            $jsonData['directoryPath'] = $newDirPath;
            $jsonChanged = true;
        }

        if ($jsonChanged) {
            if (!isset($jsonData['metadata'])) {
                $jsonData['metadata'] = [];
            }
            $jsonData['metadata']['updated_at'] = now()->toISOString();
            file_put_contents(
                $jsonPath,
                json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            );
            echo "  → Updated librarian.json.\n";
        }
    } else {
        echo "  WARN: librarian.json not found at {$jsonPath}\n";
    }

    // ── Step 6: Update database ───────────────────────────────────────────────

    DB::transaction(function () use ($book, $newTitle, $newDirPath, $vsiSeries, $titleChanged, $dirChanged, $hasVsiSeries) {
        if ($titleChanged) {
            $book->title = $newTitle;
        }
        if ($dirChanged) {
            $book->directory_path = $newDirPath;
        }
        if ($titleChanged || $dirChanged) {
            $book->save();
        }

        if (!$hasVsiSeries && $vsiSeries) {
            // Attach VSI collection — series_number null (it's a collection not an ordered series)
            $book->series()->syncWithoutDetaching([$vsiSeries->id => ['series_number' => null]]);
        }
    });

    echo "  → Updated DB.\n";
    $fixed++;
}

echo "\n";

// ── Handle books requiring user input ────────────────────────────────────────

if (!empty($needsUserInput)) {
    echo "══ Books needing manual title input (" . count($needsUserInput) . ") ══════════════════════════\n\n";

    foreach ($needsUserInput as $item) {
        /** @var Book $book */
        $book = $item['book'];
        $bookId = $book->id;
        $currentDirPath = $book->directory_path ?? '';
        $audioFilename = $item['audioFilename'];
        $currentFullDir = $bookRoot . '/' . $currentDirPath;

        echo "── [{$bookId}] ─────────────────────────────────────────────────────────\n";
        echo "  Current title:   \"{$book->title}\"\n";
        echo "  Directory:       {$currentDirPath}\n";
        echo "  Audio filename:  {$audioFilename}\n";
        echo "  Description:     " . substr($book->description ?? '', 0, 200) . "\n";

        // Show cover image path
        $coverPath = $currentFullDir . '/cover.jpg';
        if (!file_exists($coverPath)) {
            $coverPath = $currentFullDir . '/cover_audible.jpg';
        }
        if (!file_exists($coverPath)) {
            $covers = glob($currentFullDir . '/*.{jpg,png,jpeg}', GLOB_BRACE);
            $coverPath = $covers[0] ?? null;
        }
        if ($coverPath && file_exists($coverPath)) {
            echo "  Cover:           {$coverPath}\n";
        }

        echo "\n  Enter the correct title for this book (or press Enter to skip): ";
        $handle = fopen('php://stdin', 'r');
        $userTitle = trim(fgets($handle));
        fclose($handle);

        if ($userTitle === '') {
            echo "  Skipped.\n\n";
            $skipped++;
            continue;
        }

        $dirBasename = basename($currentDirPath);
        $authorStr = parseAuthorFromDir($dirBasename);
        if (empty($authorStr) && $book->authors->isNotEmpty()) {
            $authorStr = $book->authors->first()->name;
        }

        $safeTitleDir = sanitizeForFilesystem($userTitle);
        $safeAuthorDir = sanitizeForFilesystem($authorStr);
        $newDirName = $safeAuthorDir ? "{$safeTitleDir} ({$safeAuthorDir})" : $safeTitleDir;
        $newDirPath = "Non Fiction/VA/Very Short Introductions/{$newDirName}";
        $newFullDir = $bookRoot . '/' . $newDirPath;

        if ($dryRun) {
            echo "  [dry-run] Would rename to \"{$userTitle}\" → {$newDirPath}\n\n";
            $fixed++;
            continue;
        }

        // Move directory
        $parentDir = $bookRoot . '/Non Fiction/VA/Very Short Introductions';
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        if (is_dir($currentFullDir)) {
            if (!is_dir($newFullDir)) {
                rename($currentFullDir, $newFullDir);
                echo "  → Moved directory.\n";
            } else {
                echo "  WARN: Target dir exists, skipping move.\n";
            }
        }

        // Update librarian.json
        $jsonPath = (is_dir($newFullDir) ? $newFullDir : $currentFullDir) . '/librarian.json';
        if (file_exists($jsonPath)) {
            $jsonData = json_decode(file_get_contents($jsonPath), true) ?? [];
            $jsonData['title'] = $userTitle;
            $jsonData['directoryPath'] = $newDirPath;
            if (!isset($jsonData['metadata'])) {
                $jsonData['metadata'] = [];
            }
            $jsonData['metadata']['updated_at'] = now()->toISOString();
            file_put_contents(
                $jsonPath,
                json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            );
            echo "  → Updated librarian.json.\n";
        }

        // Update DB
        $hasVsiSeries = $vsiSeries && $book->series->contains('id', $vsiSeries->id);
        DB::transaction(function () use ($book, $userTitle, $newDirPath, $vsiSeries, $hasVsiSeries) {
            $book->title = $userTitle;
            $book->directory_path = $newDirPath;
            $book->save();

            if (!$hasVsiSeries && $vsiSeries) {
                $book->series()->syncWithoutDetaching([$vsiSeries->id => ['series_number' => null]]);
            }
        });

        echo "  → Updated DB.\n\n";
        $fixed++;
    }
}

// ── Summary (Part A) ─────────────────────────────────────────────────────────

echo "\n══ Part A Summary ════════════════════════════════════════════════════════════\n";
echo "Books fixed:   {$fixed}\n";
echo "Books skipped: {$skipped}\n";
if (!empty($needsUserInput) && $dryRun) {
    echo "Need input:    " . count($needsUserInput) . "\n";
}

if ($fixOnly) {
    if ($dryRun) {
        echo "\n[DRY RUN] Re-run without --dry-run to apply changes.\n";
    }
    exit(0);
}

// ── Part B: Import new books from download directory ─────────────────────────

echo "\n══ Part B: Importing from download directory ═════════════════════════════════\n";
echo "Source: {$downloadSourceDir}\n\n";

if (!is_dir($downloadSourceDir)) {
    echo "ERROR: Download directory not found: {$downloadSourceDir}\n";
    exit(1);
}

// Build a normalised set of titles already in the VSI library (for dedup)
$existingTitles = DB::table('books')
    ->where('directory_path', 'like', '%Non Fiction/VA/Very Short Introductions/%')
    ->pluck('title')
    ->map(fn ($t) => mb_strtolower(trim($t)))
    ->flip()
    ->all();

$importService = app(BookImportService::class);

$imported  = 0;
$importSkipped = 0;

/**
 * Get the duration in seconds of a set of audio files using ffprobe.
 */
function getAudioDuration(array $files): int
{
    $total = 0.0;
    foreach ($files as $file) {
        $out = shell_exec('ffprobe -v quiet -print_format json -show_format ' . escapeshellarg($file) . ' 2>/dev/null');
        if ($out) {
            $data = json_decode($out, true);
            $total += (float) ($data['format']['duration'] ?? 0);
        }
    }
    return (int) round($total);
}

/**
 * Get ID3 artist from the first audio file in a set.
 */
function getArtistFromFile(string $file): string
{
    $out = shell_exec('ffprobe -v quiet -print_format json -show_format ' . escapeshellarg($file) . ' 2>/dev/null');
    if ($out) {
        $data = json_decode($out, true);
        $artist = $data['format']['tags']['artist'] ?? '';
        // ffprobe sometimes puts it under 'ARTIST'
        if (!$artist) {
            $artist = $data['format']['tags']['ARTIST'] ?? '';
        }
        return trim($artist);
    }
    return '';
}

/**
 * Collect audio files from a directory.
 */
function collectAudioFiles(string $dir): array
{
    $files = [];
    $exts  = ['mp3', 'm4b', 'm4a', 'mp4', 'aac', 'ogg', 'flac'];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($ext, $exts, true)) {
            $files[] = $dir . '/' . $entry;
        }
    }
    sort($files);
    return $files;
}

/**
 * Write a librarian.json for a newly imported VSI book.
 */
function writeVsiLibrarianJson(
    string $targetDir,
    Book $book,
    string $dirPath,
    array $audioFiles
): void {
    $fileCount = count($audioFiles);
    $fileTags  = [];
    foreach ($audioFiles as $f) {
        $fileTags[basename($f)] = [];
    }

    $data = [
        'id'               => $book->id,
        'title'            => $book->title,
        'description'      => $book->description,
        'isbn'             => null,
        'asin'             => null,
        'release_date'     => null,
        'language'         => 'en',
        'duration'         => $book->duration,
        'cover_image'      => is_file($targetDir . '/folder.jpg') ? 'folder.jpg'
                             : (is_file($targetDir . '/cover.jpg') ? 'cover.jpg' : null),
        'directoryPath'    => $dirPath,
        'audioFileCount'   => $fileCount,
        'durationFormatted' => null,
        'needsReview'      => false,
        'dateAdded'        => now()->toISOString(),
        'source'           => 'import',
        'fileTags'         => $fileTags,
        'metadata'         => [
            'updated_at' => now()->toISOString(),
        ],
        'chapters'         => [],
        'authors'          => $book->authors->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()->toArray(),
        'narrators'        => [],
        'genres'           => [],
        'series'           => $book->series->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->toArray(),
    ];

    file_put_contents(
        $targetDir . '/librarian.json',
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
    );
}

/**
 * Clean a title string from the download directory — handles both dirname and filename patterns.
 *
 * Patterns encountered:
 *   - "AncientEgypt_AVeryShortIntroduction_mp332.mp3"  (CamelCase + underscores)
 *   - "Ancient Egypt A Very Short Introduction 2nd Ed"  (spaced dirname)
 *   - "AestheticsBolindaBeginnerGuides_mp332.mp3"        (Bolinda Beginner Guides series)
 *   - "International Migration VSI 2nd Edition"          (VSI abbreviation in dirname)
 *   - "Philosophy of Science Very Short Introduction 2nd Ed" (no "A" before Very Short)
 */
function cleanDownloadTitle(string $raw): string
{
    // Step 1: strip file extension
    $base = pathinfo($raw, PATHINFO_EXTENSION) ? pathinfo($raw, PATHINFO_FILENAME) : $raw;

    // Step 2: strip quality/format suffixes BEFORE touching underscores
    $base = preg_replace('/[\s_]+mp3\d+\s*$/i', '', $base);
    $base = preg_replace('/[\s_]+\d+kbps\s*$/i', '', $base);
    // Run-on suffix without underscore: "introductionmp332" or bare "mp3"
    $base = preg_replace('/mp3\d*\s*$/i', '', $base);

    // Step 3: strip BolindaBeginnerGuides label (CamelCase or spaced, Beginner/Beginners)
    $base = preg_replace('/[\s_]*Bolinda[\s_]*Beginners?[\s_]*Guides?/i', '', $base);
    // Strip PartN multi-part suffix
    $base = preg_replace('/[\s_]*Part\s*\d+\s*$/i', '', $base);
    // Strip "Audiobook" label
    $base = preg_replace('/[\s_]*Audiobook\s*$/i', '', $base);

    // Step 4a: Fix known short-word CamelCase glue patterns explicitly before splitting
    $base = preg_replace('/(?<=[a-z]{4})(and|or|of)(?=[A-Z])/u', ' $1 ', $base);
    // Handle short words: "Bookof", "Lifein", "as" glued to next capitalised word
    $base = preg_replace('/\bBook(of)([A-Z])/u', 'Book $1 $2', $base);
    $base = preg_replace('/\bLife(in)(the)([A-Z])/u', 'Life $1 $2 $3', $base);
    $base = preg_replace('/(\w+)(as)(Literature|History|Art)/u', '$1 $2 $3', $base);

    // Step 4b: CamelCase split — "TheColdWar" → "The Cold War"
    $base = preg_replace('/(?<=[a-z])(?=[A-Z])/u', ' ', $base);
    $base = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/u', ' ', $base);

    // Step 4c: Insert space after dot followed by uppercase (acronym): "U.S.Congress" → "U.S. Congress"
    $base = preg_replace('/(\.)(?=[A-Z][a-z])/u', '$1 ', $base);
    $base = preg_replace('/\s+/', ' ', trim($base));

    // Step 5: replace underscores and hyphens with spaces, normalise
    $base = str_replace(['_', '-'], ' ', $base);
    $base = preg_replace('/\s+/', ' ', trim($base));

    // Step 6: strip VSI abbreviation and all suffix forms (spaced and previously-CamelCase)
    $base = preg_replace('/\s+VSI\b.*/i', '', $base);
    // Fix run-on "Avery short introduction" → strip from "Avery" onward
    $base = preg_replace('/\s+Avery\s+short\s+intro\w*/i', '', $base);
    $base = stripVsiSuffix($base);

    // Step 7: strip edition suffixes
    $base = preg_replace('/\s+\d+(?:st|nd|rd|th)\s+Ed(?:ition)?\s*$/i', '', $base);

    return trim(preg_replace('/\s+/', ' ', $base));
}

/**
 * Move a file across filesystem boundaries (copy + unlink, fallback to rename).
 */
function moveFileXfs(string $src, string $dst): bool
{
    if (@rename($src, $dst)) {
        return true;
    }
    if (!copy($src, $dst)) {
        return false;
    }
    unlink($src);
    return true;
}

// ── B1: Process subdirectories ────────────────────────────────────────────────

echo "── B1: Subdirectories ───────────────────────────────────────────────────────\n";

$entries = scandir($downloadSourceDir);

foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }

    $sourcePath = $downloadSourceDir . '/' . $entry;

    if (!is_dir($sourcePath)) {
        continue;
    }

    // Extract title from directory name
    $title = cleanDownloadTitle($entry);

    if (strlen($title) < 2) {
        echo "  SKIP (no title): {$entry}\n";
        $importSkipped++;
        continue;
    }

    // Dedup check
    if (isset($existingTitles[mb_strtolower($title)])) {
        echo "  SKIP (exists): {$title}\n";
        $importSkipped++;
        continue;
    }

    // Get audio files
    $audioFiles = collectAudioFiles($sourcePath);
    if (empty($audioFiles)) {
        echo "  SKIP (no audio): {$entry}\n";
        $importSkipped++;
        continue;
    }

    // Get author from ID3 tag of first file
    $author     = getArtistFromFile($audioFiles[0]);
    $authorStr  = $author ?: '';

    // Build target path
    $safeTitleDir  = sanitizeForFilesystem($title);
    $safeAuthorDir = $authorStr ? sanitizeForFilesystem($authorStr) : '';
    $newDirName    = $safeAuthorDir ? "{$safeTitleDir} ({$safeAuthorDir})" : $safeTitleDir;
    $newDirPath    = "Non Fiction/VA/Very Short Introductions/{$newDirName}";
    $targetDir     = $bookRoot . '/' . $newDirPath;

    $duration = getAudioDuration($audioFiles);

    printf("  [new] \"%s\"%s → %s\n", $title, $authorStr ? " ({$authorStr})" : '', $newDirPath);
    printf("        %d files, %ds\n", count($audioFiles), $duration);

    if ($dryRun) {
        $importSkipped++;
        // Add to dedup so we don't double-report
        $existingTitles[mb_strtolower($title)] = true;
        continue;
    }

    // Create target directory
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        echo "  ERROR: Could not create {$targetDir}\n";
        $importSkipped++;
        continue;
    }

    // Move all files (audio + cover images) — use copy+unlink for cross-filesystem moves
    foreach (scandir($sourcePath) as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        if (!moveFileXfs($sourcePath . '/' . $file, $targetDir . '/' . $file)) {
            echo "  WARN: Could not move file {$file}\n";
        }
    }
    // Remove source directory only if now empty
    $remaining = array_diff(scandir($sourcePath), ['.', '..']);
    if (empty($remaining)) {
        rmdir($sourcePath);
    }

    // Create DB record
    $newBook = null;
    DB::transaction(function () use (
        $title,
        $authorStr,
        $author,
        $duration,
        $newDirPath,
        $audioFiles,
        $vsiSeries,
        $targetDir,
        &$newBook,
        &$imported,
        &$existingTitles
    ) {
        $newBook                 = new Book();
        $newBook->title          = $title;
        $newBook->directory_path = $newDirPath;
        $newBook->duration       = $duration ?: null;
        $newBook->audio_file_count = count($audioFiles);
        $newBook->language       = 'en';
        $newBook->source         = 'import';

        $fileTags = [];
        foreach ($audioFiles as $f) {
            $fileTags[basename($f)] = [];
        }
        $newBook->file_tags = $fileTags;

        // Cover image
        foreach (['folder.jpg', 'cover.jpg', 'folder.png', 'cover.png'] as $coverFile) {
            if (is_file($targetDir . '/' . $coverFile)) {
                $newBook->cover_image = $coverFile;
                break;
            }
        }

        $newBook->save();

        if ($author) {
            $authorModel = Author::firstOrCreate(['name' => $author]);
            $newBook->authors()->attach($authorModel->id);
        }

        if ($vsiSeries) {
            $newBook->series()->attach($vsiSeries->id, ['series_number' => null]);
        }

        $newBook->load(['authors', 'series']);
        writeVsiLibrarianJson($targetDir, $newBook, $newDirPath, $audioFiles);

        $existingTitles[mb_strtolower($title)] = true;
    });

    echo "    → Imported (id=" . ($newBook ? $newBook->id : '?') . ")\n";
    $imported++;
}

// ── B2: Process flat mp3 files ────────────────────────────────────────────────

echo "\n── B2: Flat mp3 files ───────────────────────────────────────────────────────\n";

foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }

    $sourcePath = $downloadSourceDir . '/' . $entry;

    if (!is_file($sourcePath)) {
        continue;
    }

    $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp3', 'm4b', 'm4a'], true)) {
        continue;
    }

    // Extract title from filename
    $title = cleanDownloadTitle($entry);

    if (strlen($title) < 2) {
        echo "  SKIP (no title after clean): {$entry}\n";
        $importSkipped++;
        continue;
    }

    // Dedup check
    if (isset($existingTitles[mb_strtolower($title)])) {
        echo "  SKIP (exists): {$title}\n";
        $importSkipped++;
        continue;
    }

    $safeTitleDir = sanitizeForFilesystem($title);
    $newDirName   = $safeTitleDir;
    $newDirPath   = "Non Fiction/VA/Very Short Introductions/{$newDirName}";
    $targetDir    = $bookRoot . '/' . $newDirPath;

    // Duration from file
    $duration = getAudioDuration([$sourcePath]);

    printf("  [new] \"%s\" → %s (%ds)\n", $title, $newDirPath, $duration);

    if ($dryRun) {
        $importSkipped++;
        $existingTitles[mb_strtolower($title)] = true;
        continue;
    }

    // Create target directory and move file
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        echo "  ERROR: Could not create {$targetDir}\n";
        $importSkipped++;
        continue;
    }

    $newFilePath = $targetDir . '/' . $entry;
    if (!moveFileXfs($sourcePath, $newFilePath)) {
        echo "  ERROR: Could not move {$entry}\n";
        $importSkipped++;
        continue;
    }

    // Create DB record
    DB::transaction(function () use (
        $title,
        $duration,
        $newDirPath,
        $entry,
        $targetDir,
        $newFilePath,
        $vsiSeries,
        &$imported,
        &$existingTitles
    ) {
        $newBook                   = new Book();
        $newBook->title            = $title;
        $newBook->directory_path   = $newDirPath;
        $newBook->duration         = $duration ?: null;
        $newBook->audio_file_count = 1;
        $newBook->language         = 'en';
        $newBook->source           = 'import';
        $newBook->file_tags        = [$entry => []];
        $newBook->save();

        if ($vsiSeries) {
            $newBook->series()->attach($vsiSeries->id, ['series_number' => null]);
        }

        $newBook->load(['authors', 'series']);
        writeVsiLibrarianJson($targetDir, $newBook, $newDirPath, [$newFilePath]);

        $existingTitles[mb_strtolower($title)] = true;
    });

    echo "    → Imported\n";
    $imported++;
}

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n══ Part B Summary ════════════════════════════════════════════════════════════\n";
echo "Imported:      {$imported}\n";
echo "Skipped:       {$importSkipped}\n";

if ($dryRun) {
    echo "\n[DRY RUN] Re-run without --dry-run to apply changes.\n";
}
