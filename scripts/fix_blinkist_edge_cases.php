<?php

/**
 * Fix the 5 edge-case Blinkist books where hyphens in author names caused wrong parsing.
 *
 * These books ended up in wrong sub-directories (Other/[partial-author-name]/...)
 * and need to be moved back into the main Blinkist directory with correct metadata.
 *
 * Usage:
 *   php scripts/fix_blinkist_edge_cases.php [--dry-run]
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

$basePath = '/media/audiobooks/books';
$blinkistRelBase = 'Other/Blinkist SiteRip Audio (August 2023)';
$blinkistAbsBase = $basePath . '/' . $blinkistRelBase;

// Manual corrections for the 5 edge cases
// Format: book_id => [correct_title, correct_author, series_dir_prefix, old_abs_dir]
$fixes = [
    11693 => [
        'title'         => "A Hunter-Gatherer's Guide to the 21st Century",
        'author'        => 'Heather Heying and Bret Weinstein',
        'dir_prefix'    => '61',
        'old_abs_dir'   => $basePath . "/Other/A. Hunter/Blinkist SiteRip Audio (August 2023)/61 Gatherer's Guide to the 21st Century (Heather Heying and Bret Weinstein)",
        'old_audio'     => "A Hunter - Gatherer's Guide to the 21st Century (Heather Heying and Bret Weinstein).m4b",
    ],
    11792 => [
        'title'         => 'All My Knotted-Up Life',
        'author'        => 'Beth Moore',
        'dir_prefix'    => '160',
        'old_abs_dir'   => $basePath . '/Other/All My Knotted/Blinkist SiteRip Audio (August 2023)/160 Up Life (Beth Moore)',
        'old_audio'     => 'All My Knotted - Up Life (Beth Moore).m4b',
    ],
    11829 => [
        'title'         => 'Anticipate',
        'author'        => 'Rob-Jan de Jong',
        'dir_prefix'    => '198',
        'old_abs_dir'   => $basePath . '/Other/Anticipate (Rob/Blinkist SiteRip Audio (August 2023)/198 Anticipate (Rob - Jan de Jong)',
        'old_audio'     => 'Anticipate (Rob - Jan de Jong).m4b',
    ],
    11837 => [
        'title'         => 'Arabs',
        'author'        => 'Tim Mackintosh-Smith',
        'dir_prefix'    => '206',
        'old_abs_dir'   => $basePath . '/Other/Arabs (Tim Mackintosh/Blinkist SiteRip Audio (August 2023)/206 Arabs (Tim Mackintosh - Smith)',
        'old_audio'     => 'Arabs (Tim Mackintosh - Smith).m4b',
    ],
    11889 => [
        'title'         => 'Beautiful Game Theory',
        'author'        => 'Ignacio Palacios-Huerta',
        'dir_prefix'    => '258',
        'old_abs_dir'   => $basePath . '/Other/Beautiful Game Theory (Ignacio Palacios/Blinkist SiteRip Audio (August 2023)/258 Beautiful Game Theory (Ignacio Palacios - Huerta)',
        'old_audio'     => 'Beautiful Game Theory (Ignacio Palacios - Huerta).m4b',
    ],
];

foreach ($fixes as $id => $fix) {
    $book = Book::with('authors')->find($id);
    if (!$book) {
        echo "WARN: Book id={$id} not found in DB\n";
        continue;
    }

    $newDirName      = $fix['dir_prefix'] . ' ' . $fix['title'] . ' (' . $fix['author'] . ')';
    $newAbsDir       = $blinkistAbsBase . '/' . $newDirName;
    $newAudioFile    = $fix['title'] . ' (' . $fix['author'] . ').m4b';
    $newRelPath      = $blinkistRelBase . '/' . $newDirName;

    $oldAbsDir  = $fix['old_abs_dir'];
    $oldAudioAbs = $oldAbsDir . '/' . $fix['old_audio'];

    echo "FIX [{$id}]:\n";
    echo "  title:  \"{$book->title}\" → \"{$fix['title']}\"\n";
    echo "  author: \"{$book->authors->pluck('name')->join(', ')}\" → \"{$fix['author']}\"\n";
    echo "  oldDir: {$oldAbsDir}\n";
    echo "  newDir: {$newAbsDir}\n";
    echo "  audio:  \"{$fix['old_audio']}\" → \"{$newAudioFile}\"\n";

    if (!$dryRun) {
        // Create new target directory
        if (!is_dir($newAbsDir)) {
            mkdir($newAbsDir, 0755, true);
        }

        // Move and rename audio file
        $newAudioAbs = $newAbsDir . '/' . $newAudioFile;
        if (file_exists($oldAudioAbs)) {
            rename($oldAudioAbs, $newAudioAbs);
            echo "  → Moved audio file\n";
        } else {
            echo "  WARN: audio file not found: {$oldAudioAbs}\n";
        }

        // Write updated librarian.json in new location
        $oldJsonPath = $oldAbsDir . '/librarian.json';
        $json = file_exists($oldJsonPath) ? json_decode(file_get_contents($oldJsonPath), true) : [];
        $json['id']            = $id;
        $json['title']         = $fix['title'];
        $json['author']        = [$fix['author']];
        $json['directoryPath'] = $newRelPath;
        if (isset($json['fileTags'][$fix['old_audio']])) {
            $json['fileTags'][$newAudioFile] = $json['fileTags'][$fix['old_audio']];
            unset($json['fileTags'][$fix['old_audio']]);
        }
        $json['metadata']['updated_at'] = now()->toISOString();
        file_put_contents(
            $newAbsDir . '/librarian.json',
            json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        // Remove old directory (should now be empty)
        if (file_exists($oldJsonPath)) {
            unlink($oldJsonPath);
        }
        if (is_dir($oldAbsDir) && count(glob($oldAbsDir . '/*')) === 0) {
            rmdir($oldAbsDir);
            echo "  → Removed old directory\n";
        }

        // Clean up empty parent dirs (e.g. Other/Anticipate (Rob/Blinkist SiteRip Audio (August 2023)/)
        $parent = dirname($oldAbsDir);
        while ($parent !== $basePath && is_dir($parent) && count(glob($parent . '/*')) === 0) {
            rmdir($parent);
            echo "  → Removed empty parent: {$parent}\n";
            $parent = dirname($parent);
        }

        // Update DB
        DB::transaction(function () use ($book, $fix, $newRelPath) {
            $book->title         = $fix['title'];
            $book->directoryPath = $newRelPath;
            $book->save();

            // Replace author(s)
            $book->authors()->detach();
            $author = Author::firstOrCreate(['name' => $fix['author']]);
            $book->authors()->attach($author->id);
        });

        echo "  → DB updated\n";
    } else {
        echo "  [dry-run] Would move, rename, and update DB\n";
    }
    echo "\n";
}

echo "Done.\n";
