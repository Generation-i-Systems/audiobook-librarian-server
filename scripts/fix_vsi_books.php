<?php

/**
 * Fix Very Short Introductions (VSI) books.
 *
 * Problems:
 *   1. Books were imported into Non Fiction/VA/ directly with pattern '[title] ([author])'
 *      but many have title "A Very Short Introduction" instead of the real subject title.
 *   2. Books should be in a "Very Short Introductions" collection (Series with is_collection=true).
 *   3. Directories should be moved to Non Fiction/VA/Very Short Introductions/[Title] ([Author]).
 *   4. DB directory_path, title, and series links must be updated.
 *   5. librarian.json files must be updated to reflect the new state.
 *
 * Identification: books with id >= 12928 in Non Fiction/VA/ (excluding sub-collections already fixed).
 *
 * Title extraction priority:
 *   1. Audio filename (most reliable — filename often contains the real title)
 *   2. Description patterns like "TITLE: A Very Short Introduction..."
 *   3. If unresolvable: show cover image + description and prompt user.
 *
 * Usage:
 *   php scripts/fix_vsi_books.php [--dry-run]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Book;
use App\Models\Series;
use Illuminate\Support\Facades\DB;

$dryRun = in_array('--dry-run', $argv, true);

if ($dryRun) {
    echo "[DRY RUN] No changes will be written.\n\n";
}

$bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
$vsiCollectionName = 'Very Short Introductions';
$targetSubDir = 'Non Fiction/VA/Very Short Introductions';

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
    // Remove "Very Short Intro[duction[s]]" suffix with a separator before it
    $base = preg_replace('/[\s:,()\-]+Very Short Intro\w*\s*$/i', '', $base);
    // Remove trailing "- Very Short Intro..." (hyphen separator, no leading "A")
    $base = preg_replace('/\s+-\s+Very Short Intro\w*.*$/i', '', $base);
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

// ── Summary ──────────────────────────────────────────────────────────────────

echo "\n══ Summary ══════════════════════════════════════════════════════════════════\n";
echo "Books fixed:   {$fixed}\n";
echo "Books skipped: {$skipped}\n";
if (!empty($needsUserInput) && $dryRun) {
    echo "Need input:    " . count($needsUserInput) . "\n";
}

if ($dryRun) {
    echo "\n[DRY RUN] Re-run without --dry-run to apply changes.\n";
}
