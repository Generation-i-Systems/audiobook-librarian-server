<?php

/**
 * Fix Blinkist SiteRip Audio (August 2023) books.
 *
 * Problems:
 *   1. All 243 books have the author embedded in the title: "Title (Author Name)"
 *      → title should be just "Title", author should be a linked Author record
 *   2. Two entries (08, 09) have no title at all — just "(Author Name)"
 *      → exact duplicates of entries 20 and 26 (identical duration/file content)
 *      → delete them from the DB and remove from filesystem
 *   3. Audio filenames mirror the bad "Title (Author)" format
 *      → rename to clean "Title (Author)" format (keeping author in filename for clarity)
 *   4. librarian.json files need title/author/directoryPath updated
 *   5. DB directoryPath for all records is wrong — update from librarian.json source of truth
 *
 * Source of truth: filesystem librarian.json (has correct directoryPath per file)
 * DB is updated to match.
 *
 * Usage:
 *   php scripts/fix_blinkist_books.php [--dry-run]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Book;
use App\Models\Author;
use Illuminate\Support\Facades\DB;

$dryRun = in_array('--dry-run', $argv, true);

if ($dryRun) {
    echo "[DRY RUN] No changes will be written.\n\n";
}

$baseDir = '/media/audiobooks/books/Other/Blinkist SiteRip Audio (August 2023)';
$basePath = '/media/audiobooks/books';

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Parse "Title (Author Name)" → ['title' => 'Title', 'author' => 'Author Name']
 * Returns null if the format doesn't match or title is empty.
 */
function parseTitleAuthor(string $raw): ?array
{
    if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/', $raw, $m)) {
        $title = trim($m[1]);
        $author = trim($m[2]);
        if ($title !== '') {
            return ['title' => $title, 'author' => $author];
        }
    }
    return null;
}

// ── Collect all book dirs from filesystem ────────────────────────────────────

$dirs = [];
foreach (scandir($baseDir) as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $fullDir = $baseDir . '/' . $entry;
    if (!is_dir($fullDir)) {
        continue;
    }
    $jsonPath = $fullDir . '/librarian.json';
    if (!file_exists($jsonPath)) {
        echo "WARN: no librarian.json in {$entry}\n";
        continue;
    }
    $json = json_decode(file_get_contents($jsonPath), true);
    if (!isset($json['id'])) {
        echo "WARN: no id in librarian.json for {$entry}\n";
        continue;
    }
    $dirs[$entry] = ['json' => $json, 'fullDir' => $fullDir];
}

echo "Found " . count($dirs) . " directories with librarian.json\n\n";

// ── Step 1: Identify and remove duplicate no-title entries ───────────────────

$toDelete = [];
foreach ($dirs as $dirname => $info) {
    $title = trim($info['json']['title'] ?? '');
    // Title is ONLY "(Author Name)" — no real book title
    if (preg_match('/^\([^)]+\)$/', $title)) {
        $toDelete[$dirname] = $info;
    }
}

if (count($toDelete) > 0) {
    echo "── Duplicates to remove (" . count($toDelete) . ") ─────────────────────────────────\n";
    foreach ($toDelete as $dirname => $info) {
        $id = $info['json']['id'];
        $title = $info['json']['title'];
        echo "  [{$id}] \"{$dirname}\" → title was \"{$title}\"\n";

        if (!$dryRun) {
            $book = Book::find($id);
            if ($book) {
                DB::transaction(function () use ($book) {
                    $book->authors()->detach();
                    $book->narrators()->detach();
                    $book->genres()->detach();
                    $book->series()->detach();
                    $book->delete();
                });
                echo "    → Deleted from DB\n";
            } else {
                echo "    → Book not found in DB (id={$id}), skipping DB delete\n";
            }

            // Remove directory and its contents
            $fullDir = $info['fullDir'];
            foreach (glob($fullDir . '/*') as $f) {
                unlink($f);
            }
            rmdir($fullDir);
            echo "    → Removed filesystem directory\n";
        } else {
            echo "    [dry-run] Would delete from DB (id={$id}) and remove directory\n";
        }

        // Remove from working set
        unset($dirs[$dirname]);
    }
    echo "\n";
}

// ── Step 2: Fix books with embedded author in title ──────────────────────────

$fixed = 0;
$skipped = 0;

echo "── Fixing title/author/paths ─────────────────────────────────────────────\n";

foreach ($dirs as $dirname => $info) {
    $fullDir = $info['fullDir'];
    $json = $info['json'];
    $id = $json['id'];
    $rawTitle = trim($json['title'] ?? '');

    $parsed = parseTitleAuthor($rawTitle);
    if ($parsed === null) {
        // Check if already correctly split (title clean, authors present in DB)
        $book = Book::with('authors')->find($id);
        if ($book && $book->authors->isNotEmpty()) {
            $skipped++;
            continue;
        }
        echo "SKIP (unparseable, no authors): id={$id} title=\"{$rawTitle}\"\n";
        $skipped++;
        continue;
    }

    $cleanTitle = $parsed['title'];
    $authorName = $parsed['author'];

    // Check if already fixed
    $book = Book::with('authors')->find($id);
    if (!$book) {
        echo "WARN: Book id={$id} not found in DB (dir: {$dirname})\n";
        $skipped++;
        continue;
    }

    if ($book->title === $cleanTitle && $book->authors->isNotEmpty()) {
        $skipped++;
        continue;
    }

    // Compute correct directoryPath (relative to $basePath)
    $correctDirectoryPath = str_replace($basePath . '/', '', $fullDir);

    // Find audio file in the current directory
    $audioFiles = glob($fullDir . '/*.{m4b,mp3,flac,ogg,aac,opus}', GLOB_BRACE);
    $oldAudioPath = $audioFiles[0] ?? null;
    $ext = $oldAudioPath ? pathinfo($oldAudioPath, PATHINFO_EXTENSION) : null;

    // New clean audio filename: "Title (Author).ext"
    $newAudioFilename = $cleanTitle . ' (' . $authorName . ').' . $ext;
    // Sanitize: replace chars forbidden on common filesystems
    $newAudioFilename = preg_replace('/[\/\\\\:*?"<>|]/', '_', $newAudioFilename);
    $newAudioPath = $oldAudioPath ? ($fullDir . '/' . $newAudioFilename) : null;

    $audioChanged = $oldAudioPath && $newAudioPath && basename($oldAudioPath) !== $newAudioFilename;

    echo "FIX [{$id}]: \"{$rawTitle}\"\n";
    echo "  title:  → \"{$cleanTitle}\"\n";
    echo "  author: → \"{$authorName}\"\n";
    if ($audioChanged) {
        echo "  audio:  \"" . basename($oldAudioPath) . "\" → \"{$newAudioFilename}\"\n";
    }
    if ($book->directoryPath !== $correctDirectoryPath) {
        echo "  dbPath: \"{$book->directoryPath}\" → \"{$correctDirectoryPath}\"\n";
    }

    if (!$dryRun) {
        // Rename audio file
        if ($audioChanged && file_exists($oldAudioPath)) {
            rename($oldAudioPath, $newAudioPath);
        }

        // Update librarian.json
        $updatedJson = $json;
        $updatedJson['title'] = $cleanTitle;
        $updatedJson['author'] = [$authorName];
        $updatedJson['directoryPath'] = $correctDirectoryPath;
        if ($audioChanged) {
            $oldFilename = basename($oldAudioPath);
            if (isset($updatedJson['fileTags'][$oldFilename])) {
                $updatedJson['fileTags'][$newAudioFilename] = $updatedJson['fileTags'][$oldFilename];
                unset($updatedJson['fileTags'][$oldFilename]);
            }
        }
        $updatedJson['metadata']['updated_at'] = now()->toISOString();
        file_put_contents(
            $fullDir . '/librarian.json',
            json_encode($updatedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        // Update DB
        DB::transaction(function () use ($book, $cleanTitle, $authorName, $correctDirectoryPath) {
            $book->title = $cleanTitle;
            $book->directoryPath = $correctDirectoryPath;
            $book->save();

            $author = Author::firstOrCreate(['name' => $authorName]);
            if (!$book->authors->contains($author->id)) {
                $book->authors()->attach($author->id);
            }
        });

        echo "  → Done\n";
        $fixed++;
    } else {
        echo "  [dry-run] Would fix\n";
        $fixed++;
    }
}

echo "\n── Summary ──────────────────────────────────────────────────────────────\n";
echo "Duplicates removed: " . count($toDelete) . "\n";
echo "Books fixed:        {$fixed}\n";
echo "Books skipped:      {$skipped}\n";

if ($dryRun) {
    echo "\n[DRY RUN] Re-run without --dry-run to apply changes.\n";
}
