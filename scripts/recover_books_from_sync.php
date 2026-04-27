#!/usr/bin/env php
<?php

// scripts/recover_books_from_sync.php
// Scans /media/audiobooks/sync for book directories whose audio files can be
// used to restore missing entries on the 'books' disk.
//
// Two matching strategies are used:
//   1. librarian.json (server format with directoryPath + id) — exact match by DB id.
//   2. Directory-name fallback — for dirs without librarian.json, normalizes the
//      sync dir basename and matches against DB books (title or directory_path
//      basename) whose disk directory is missing. Ambiguous matches are logged but
//      skipped unless --force-ambiguous is given.

use App\Models\Book;
use Illuminate\Support\Facades\Storage;

// Bootstrap Laravel
$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
$app = require_once $basePath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// CLI Options
$options = parse_cli_options($argv);
$dryRun = $options['dry_run'] ?? false;
$limit = $options['limit'] ?? 0;
$syncRoot = $options['sync_root'] ?? '/media/audiobooks/sync';
$forceAmbiguous = $options['force_ambiguous'] ?? false;
$booksDisk = 'books';

if (!empty($options['help'])) {
    fwrite(STDOUT, usage_text());
    exit(0);
}

if (!is_dir($syncRoot)) {
    fwrite(STDERR, "Sync directory not found: {$syncRoot}\n");
    exit(1);
}

if (!config("filesystems.disks.{$booksDisk}")) {
    fwrite(STDERR, "Filesystem disk '{$booksDisk}' is not configured.\n");
    exit(1);
}

// Logging setup
$runId = date('Ymd_His');
$logFile = function_exists('storage_path')
    ? storage_path('logs/recover_sync_' . $runId . '.jsonl')
    : (__DIR__ . '/../storage/logs/recover_sync_' . $runId . '.jsonl');
ensure_log_dir(dirname($logFile));
log_entry($logFile, [
    'ts' => date(DATE_ATOM),
    'run_id' => $runId,
    'event' => 'start',
    'dry_run' => $dryRun,
    'limit' => $limit,
    'sync_root' => $syncRoot,
    'books_disk' => $booksDisk,
]);

$stats = [
    'sync_dirs_scanned' => 0,
    'matched_via_json' => 0,
    'matched_via_dirname' => 0,
    'skipped_no_audio' => 0,
    'skipped_already_exists' => 0,
    'skipped_no_db_match' => 0,
    'skipped_ambiguous' => 0,
    'recovered' => 0,
    'planned_recover' => 0,
    'copy_errors' => 0,
];

$audioExtensions = ['mp3', 'm4b', 'm4a', 'flac', 'ogg', 'aac', 'wav'];

// Build index of DB books with missing directories keyed by normalized name
$disk = Storage::disk($booksDisk);
$missingBooks = Book::query()->get()->filter(function (Book $b) use ($disk) {
    $dir = $b->directory_path;
    return !empty($dir) && !$disk->exists(rtrim($dir, '/'));
});

// Index: normalized_key => [Book, ...]
$missingByNorm = [];
foreach ($missingBooks as $book) {
    // Key on title
    $titleKey = normalize_name($book->title ?? '');
    if ($titleKey !== '') {
        $missingByNorm[$titleKey][] = $book;
    }
    // Also key on directory basename (stripped of leading "NN " prefix)
    $baseKey = normalize_name(basename(rtrim($book->directory_path ?? '', '/')));
    if ($baseKey !== '' && $baseKey !== $titleKey) {
        $missingByNorm[$baseKey][] = $book;
    }
}

// Scan all leaf directories under syncRoot that contain audio files
$syncDirs = collect_audio_dirs($syncRoot, $audioExtensions);
$stats['sync_dirs_scanned'] = count($syncDirs);

if ($limit > 0) {
    $syncDirs = array_slice($syncDirs, 0, $limit);
}

foreach ($syncDirs as $syncDir) {
    $audioFiles = find_audio_files($syncDir, $audioExtensions);
    if (empty($audioFiles)) {
        $stats['skipped_no_audio']++;
        continue;
    }

    // --- Strategy 1: librarian.json ---
    $jsonPath = $syncDir . '/librarian.json';
    $matchedBook = null;
    $targetDir = null;
    $strategy = null;

    if (file_exists($jsonPath)) {
        $json = @file_get_contents($jsonPath);
        $data = $json ? @json_decode($json, true) : null;
        if (is_array($data) && !empty($data['directoryPath']) && !empty($data['id'])) {
            $directoryPath = trim($data['directoryPath'], '/');
            $bookId = (int) $data['id'];

            if ($disk->exists($directoryPath)) {
                $stats['skipped_already_exists']++;
                log_entry($logFile, [
                    'ts' => date(DATE_ATOM),
                    'event' => 'skip_exists',
                    'strategy' => 'json',
                    'book_id' => $bookId,
                    'directory_path' => $directoryPath,
                    'sync_dir' => $syncDir,
                ]);
                continue;
            }

            $matchedBook = Book::query()->find($bookId);
            $targetDir = $directoryPath;
            $strategy = 'json';

            if (!$matchedBook) {
                $stats['skipped_no_db_match']++;
                log_entry($logFile, [
                    'ts' => date(DATE_ATOM),
                    'event' => 'skip_no_db',
                    'strategy' => 'json',
                    'book_id' => $bookId,
                    'sync_dir' => $syncDir,
                ]);
                continue;
            }

            $stats['matched_via_json']++;
        }
    }

    // --- Strategy 2: directory-name fallback ---
    if ($matchedBook === null) {
        $baseName = basename($syncDir);
        $normKey = normalize_name($baseName);

        $candidates = $missingByNorm[$normKey] ?? [];
        // Deduplicate by id
        $seen = [];
        $candidates = array_filter($candidates, function (Book $b) use (&$seen) {
            if (isset($seen[$b->id])) {
                return false;
            }
            $seen[$b->id] = true;
            return true;
        });
        $candidates = array_values($candidates);

        if (empty($candidates)) {
            $stats['skipped_no_db_match']++;
            log_entry($logFile, [
                'ts' => date(DATE_ATOM),
                'event' => 'skip_no_db',
                'strategy' => 'dirname',
                'basename' => $baseName,
                'sync_dir' => $syncDir,
            ]);
            continue;
        }

        // Score each candidate by how many sync ancestor path components (series,
        // author dir) appear anywhere in the DB book's directory_path. A score of
        // 0 means no contextual overlap — likely a false title-only match.
        $scored = [];
        foreach ($candidates as $candidate) {
            $scored[] = [
                'book' => $candidate,
                'score' => parent_context_score($syncDir, $syncRoot, $candidate),
            ];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $topScore = $scored[0]['score'];

        // Collect all candidates tied at the top score
        $topCandidates = array_values(array_filter($scored, fn ($s) => $s['score'] === $topScore));

        // No contextual overlap at all — too risky to recover automatically
        if ($topScore === 0) {
            $stats['skipped_no_db_match']++;
            log_entry($logFile, [
                'ts' => date(DATE_ATOM),
                'event' => 'skip_no_context',
                'basename' => $baseName,
                'sync_dir' => $syncDir,
                'candidates' => array_map(fn ($s) => ['id' => $s['book']->id, 'title' => $s['book']->title, 'dir' => $s['book']->directory_path], $scored),
            ]);
            continue;
        }

        if (count($topCandidates) > 1 && !$forceAmbiguous) {
            $stats['skipped_ambiguous']++;
            $ambig = array_map(fn ($s) => ['id' => $s['book']->id, 'title' => $s['book']->title, 'dir' => $s['book']->directory_path, 'score' => $s['score']], $topCandidates);
            log_entry($logFile, [
                'ts' => date(DATE_ATOM),
                'event' => 'skip_ambiguous',
                'basename' => $baseName,
                'sync_dir' => $syncDir,
                'candidates' => $ambig,
            ]);
            fwrite(STDOUT, "AMBIGUOUS: '{$baseName}' matches " . count($topCandidates) . " DB books at score {$topScore} — skipped (see log)\n");
            continue;
        }

        $matchedBook = $topCandidates[0]['book'];
        $targetDir = trim($matchedBook->directory_path ?? '', '/');
        $strategy = 'dirname';

        if (empty($targetDir)) {
            $stats['skipped_no_db_match']++;
            continue;
        }

        if ($disk->exists($targetDir)) {
            $stats['skipped_already_exists']++;
            continue;
        }

        $stats['matched_via_dirname']++;
    }

    // --- Recover ---
    $coverSource = find_cover($syncDir);

    if ($dryRun) {
        $stats['planned_recover']++;
        log_entry($logFile, [
            'ts' => date(DATE_ATOM),
            'event' => 'planned_recover',
            'strategy' => $strategy,
            'book_id' => $matchedBook->id,
            'title' => $matchedBook->title,
            'directory_path' => $targetDir,
            'sync_dir' => $syncDir,
            'audio_file_count' => count($audioFiles),
            'cover_source' => $coverSource,
        ]);
        $tag = $strategy === 'json' ? '[json]' : '[dirname]';
        fwrite(STDOUT, "[dry-run] {$tag} Would recover: [{$matchedBook->id}] {$matchedBook->title}\n");
        fwrite(STDOUT, "              from: {$syncDir}\n");
        fwrite(STDOUT, "                to: {$targetDir} ({$booksDisk} disk)\n");
        fwrite(STDOUT, "       audio files: " . count($audioFiles) . ", cover: " . ($coverSource ? basename($coverSource) : 'none') . "\n\n");
        continue;
    }

    // Create target directory and copy files
    $disk->makeDirectory($targetDir);
    $copyErrors = 0;

    foreach ($audioFiles as $src) {
        $baseName = basename($src);
        $targetPath = $targetDir . '/' . $baseName;
        $ok = copy_to_disk($src, $targetPath, $booksDisk);
        log_entry($logFile, [
            'ts' => date(DATE_ATOM),
            'event' => 'copy_audio',
            'status' => $ok ? 'ok' : 'error',
            'book_id' => $matchedBook->id,
            'source' => $src,
            'target' => $targetPath,
        ]);
        if (!$ok) {
            $copyErrors++;
        }
    }

    $savedCoverName = null;
    if ($coverSource) {
        $coverExt = strtolower(pathinfo($coverSource, PATHINFO_EXTENSION));
        $coverName = 'cover.' . $coverExt;
        $coverTarget = $targetDir . '/' . $coverName;
        $ok = copy_to_disk($coverSource, $coverTarget, $booksDisk);
        log_entry($logFile, [
            'ts' => date(DATE_ATOM),
            'event' => 'copy_cover',
            'status' => $ok ? 'ok' : 'error',
            'book_id' => $matchedBook->id,
            'source' => $coverSource,
            'target' => $coverTarget,
        ]);
        if ($ok) {
            $savedCoverName = $coverName;
        }
    }

    if ($copyErrors > 0) {
        $stats['copy_errors']++;
    } else {
        $stats['recovered']++;
    }

    // Update DB record
    if ($savedCoverName && empty($matchedBook->cover_image)) {
        $matchedBook->cover_image = $savedCoverName;
    }
    $reasons = is_array($matchedBook->needs_review_reasons) ? $matchedBook->needs_review_reasons : [];
    $reasons = array_values(array_diff($reasons, ['missing directory', 'invalid image', 'no suitable image found']));
    $matchedBook->needs_review_reasons = $reasons;
    if (empty($reasons)) {
        $matchedBook->needs_review = false;
    }
    $matchedBook->save();

    $tag = $strategy === 'json' ? '[json]' : '[dirname]';
    log_entry($logFile, [
        'ts' => date(DATE_ATOM),
        'event' => 'recover_complete',
        'strategy' => $strategy,
        'book_id' => $matchedBook->id,
        'title' => $matchedBook->title,
        'directory_path' => $targetDir,
        'audio_files_copied' => count($audioFiles) - $copyErrors,
        'cover_saved' => $savedCoverName,
    ]);
    fwrite(STDOUT, "Recovered {$tag}: [{$matchedBook->id}] {$matchedBook->title}\n");
    fwrite(STDOUT, "       to: {$targetDir}\n\n");
}

// Final report
$report = [
    'dry_run' => $dryRun,
    'limit' => $limit,
    'sync_root' => $syncRoot,
    'sync_dirs_scanned' => $stats['sync_dirs_scanned'],
    'matched_via_json' => $stats['matched_via_json'],
    'matched_via_dirname' => $stats['matched_via_dirname'],
    'skipped_no_audio' => $stats['skipped_no_audio'],
    'skipped_already_exists' => $stats['skipped_already_exists'],
    'skipped_no_db_match' => $stats['skipped_no_db_match'],
    'skipped_ambiguous' => $stats['skipped_ambiguous'],
    'recovered' => $stats['recovered'],
    'planned_recover' => $stats['planned_recover'],
    'copy_errors' => $stats['copy_errors'],
    'log_file' => $logFile,
];

fwrite(STDOUT, "\n" . json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// -------- Helpers --------

/**
 * Walk syncRoot and return all directories (at any depth) that contain at
 * least one audio file directly inside them (not recursing further).
 */
function collect_audio_dirs(string $syncRoot, array $audioExtensions): array
{
    $result = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($syncRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $seen = [];
    foreach ($rii as $item) {
        if (!$item->isFile()) {
            continue;
        }
        if (!in_array(strtolower($item->getExtension()), $audioExtensions, true)) {
            continue;
        }
        $dir = $item->getPath();
        if (!isset($seen[$dir])) {
            $seen[$dir] = true;
            $result[] = $dir;
        }
    }
    return $result;
}

function find_audio_files(string $dir, array $audioExtensions): array
{
    $files = [];
    foreach (new DirectoryIterator($dir) as $f) {
        if ($f->isFile() && in_array(strtolower($f->getExtension()), $audioExtensions, true)) {
            $files[] = $f->getPathname();
        }
    }
    return $files;
}

function find_cover(string $dir): ?string
{
    foreach (['cover.jpg', 'cover.png', 'cover.webp', 'folder.jpg'] as $name) {
        $p = $dir . '/' . $name;
        if (file_exists($p)) {
            return $p;
        }
    }
    // Find any image in the dir (prefer audible covers)
    $fallback = null;
    foreach (new DirectoryIterator($dir) as $f) {
        if ($f->isFile() && in_array(strtolower($f->getExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $name = strtolower($f->getFilename());
            if (str_contains($name, 'cover') || str_contains($name, 'audible')) {
                return $f->getPathname();
            }
            $fallback ??= $f->getPathname();
        }
    }
    return $fallback;
}

/**
 * Score how well the sync dir's ancestor path components (series, author dirs)
 * match the DB book's directory_path components. Each matching ancestor awards
 * one point. A score of 0 means no contextual overlap.
 *
 * Example: sync path ".../mike/Cytoverse/04 Defiant"
 *   ancestors (after stripping syncRoot + username): ["cytoverse"]
 *   DB path "Science Fiction/Brandon Sanderson/Skyward/04 Defiant"
 *   components: ["science fiction", "brandon sanderson", "skyward", "04 defiant"]
 *   "cytoverse" partial-matches "skyward"? no → score 0
 *   "cytoverse" partial-matches nothing → score 0  (correct rejection)
 */
function parent_context_score(string $syncDir, string $syncRoot, Book $book): int
{
    // Derive ancestor components: strip syncRoot + username, then remove the leaf
    $syncRelative = ltrim(substr($syncDir, strlen(rtrim($syncRoot, '/'))), '/');
    $parts = explode('/', $syncRelative);
    array_pop($parts); // remove leaf (the book dir itself)
    if (!empty($parts)) {
        array_shift($parts); // remove username segment
    }
    $syncAncestors = array_values(array_filter(array_map('normalize_name', $parts)));

    if (empty($syncAncestors)) {
        return 0;
    }

    // DB path components (excluding leaf)
    $dbParts = explode('/', trim($book->directory_path ?? '', '/'));
    array_pop($dbParts);
    $dbComponents = array_values(array_filter(array_map('normalize_name', $dbParts)));

    $score = 0;
    foreach ($syncAncestors as $anc) {
        foreach ($dbComponents as $comp) {
            if ($anc === $comp || str_contains($comp, $anc) || str_contains($anc, $comp)) {
                $score++;
                break;
            }
        }
    }
    return $score;
}

/**
 * Normalize a name for fuzzy matching: lowercase, strip leading "NN " series
 * prefix, remove non-alphanumeric chars, collapse whitespace.
 */
function normalize_name(string $s): string
{
    $s = trim($s);
    // Strip leading series number prefix like "01 ", "1 ", "02.5 "
    $s = preg_replace('/^\d+(\.\d+)?\s+/', '', $s) ?? $s;
    $s = mb_strtolower($s);
    $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s) ?? $s;
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    return $s;
}

function copy_to_disk(string $srcPath, string $targetPath, string $disk): bool
{
    $handle = fopen($srcPath, 'rb');
    if ($handle === false) {
        return false;
    }
    $ok = Storage::disk($disk)->put($targetPath, $handle);
    fclose($handle);
    return (bool) $ok;
}

function parse_cli_options(array $argv): array
{
    $out = [
        'dry_run' => false,
        'limit' => 0,
        'help' => false,
        'sync_root' => '/media/audiobooks/sync',
        'force_ambiguous' => false,
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run' || $arg === '-n') {
            $out['dry_run'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $out['help'] = true;
        } elseif ($arg === '--force-ambiguous') {
            $out['force_ambiguous'] = true;
        } elseif (str_starts_with($arg, '--limit=')) {
            $out['limit'] = max(0, (int) substr($arg, strlen('--limit=')));
        } elseif (str_starts_with($arg, '--sync-root=')) {
            $out['sync_root'] = substr($arg, strlen('--sync-root='));
        }
    }
    return $out;
}

function usage_text(): string
{
    return <<<TXT
Recover books from user sync directories

Usage:
  php scripts/recover_books_from_sync.php [--dry-run] [--limit=N] [--sync-root=PATH] [--force-ambiguous]

Description:
  Scans sync directories for subdirectories containing audio files and attempts to
  match them to DB books whose disk directory is missing, using two strategies:

    1. librarian.json (server format: directoryPath + id) — exact DB id match.
    2. Directory-name fallback — normalizes the sync dir basename and matches
       against missing DB books by title or directory_path basename. Skips
       ambiguous matches (multiple candidates) unless --force-ambiguous is given.

  On match, copies audio files and the cover image to the 'books' disk at the
  expected directory_path, then updates the DB record.

Options:
  --dry-run, -n        Preview without copying files or changing the database
  --limit=N            Process only the first N audio-containing sync dirs found
  --sync-root=PATH     Override sync root (default: /media/audiobooks/sync)
  --force-ambiguous    Recover even when dirname matching finds multiple candidates
                       (picks the first match — use with care)
  --help, -h           Show this help message

Examples:
  php scripts/recover_books_from_sync.php --dry-run
  php scripts/recover_books_from_sync.php --dry-run --limit=20
  php scripts/recover_books_from_sync.php
TXT;
}

function log_entry(string $logFile, array $entry): void
{
    ensure_log_dir(dirname($logFile));
    @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function ensure_log_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}
