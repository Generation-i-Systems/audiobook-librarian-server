#!/usr/bin/env php
<?php

// scripts/recover_books_from_openaudible.php
// Scans OpenAudible export directory for .m4b files, matches by title/author to
// database books whose directory is missing, moves files to the expected directory
// on the 'books' disk, extracts an embedded cover image, and reports statistics.

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Book;

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

if (!empty($options['help'])) {
    fwrite(STDOUT, usage_text());
    exit(0);
}

// Configuration
$openAudibleRoot = '/media/audiobooks/OpenAudible/books';
$booksDisk = 'books';

// Logging setup
$runId = date('Ymd_His');
$logFile = function_exists('storage_path')
    ? storage_path('logs/recover_openaudible_' . $runId . '.jsonl')
    : (__DIR__ . '/../storage/logs/recover_openaudible_' . $runId . '.jsonl');
ensure_log_dir(dirname($logFile));
log_change($logFile, [
    'ts' => date(DATE_ATOM),
    'run_id' => $runId,
    'event' => 'start',
    'dry_run' => $dryRun,
    'limit' => $limit,
    'openAudibleRoot' => $openAudibleRoot,
    'booksDisk' => $booksDisk,
]);

if (!is_dir($openAudibleRoot)) {
    fwrite(STDERR, "OpenAudible directory not found: {$openAudibleRoot}\n");
    exit(1);
}

if (!config("filesystems.disks.{$booksDisk}")) {
    fwrite(STDERR, "Filesystem disk '{$booksDisk}' is not configured.\n");
    exit(1);
}

// Stats
$stats = [
    'db_missing_directories' => 0,
    'db_missing_covers' => 0,
    'moved_files' => 0,
    'cover_extracted' => 0,
    'planned_moved_files' => 0,
    'planned_cover_extracted' => 0,
    'openaudible_total' => 0,
    'openaudible_not_moved' => 0,
    'openaudible_already_present' => 0,
];

// Load DB books and classify which are missing directories or covers
$books = Book::query()->get();
$booksByKey = []; // key: normalized "author|title"

foreach ($books as $book) {
    $dir = $book->directory_path ?? $book->directoryPath ?? null;
    $exists = !empty($dir) && Storage::disk($booksDisk)->exists(rtrim($dir, '/'));
    $hasCover = !empty($book->cover_image ?? $book->coverImage);

    if (!$exists) {
        $stats['db_missing_directories']++;
    }
    if (!$hasCover) {
        $stats['db_missing_covers']++;
    }

    // Build key from first author and title
    $authorName = null;
    try {
        $authorName = $book->authors()->exists() ? ($book->authors()->first()->name ?? null) : null;
    } catch (Throwable $e) {
        $authorName = null;
    }
    $key = normalize_key($authorName, $book->title ?? null);
    if ($key) {
        $booksByKey[$key][] = [
            'id' => $book->id,
            'title' => $book->title,
            'author' => $authorName,
            'directory' => $dir,
            'exists' => $exists,
            'hasCover' => $hasCover,
        ];
    }
}

// Recursively find .m4b files in OpenAudible
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($openAudibleRoot, FilesystemIterator::SKIP_DOTS));
$m4bFiles = [];
foreach ($rii as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'm4b') {
        $m4bFiles[] = $file->getPathname();
    }
}
$stats['openaudible_total'] = count($m4bFiles);
if ($limit > 0) {
    $m4bFiles = array_slice($m4bFiles, 0, $limit);
}

// Prepare getID3
$getID3 = new getID3();

foreach ($m4bFiles as $sourcePath) {
    // Extract metadata
    $meta = analyze_audio_metadata($getID3, $sourcePath);
    $key = normalize_key($meta['author'] ?? null, $meta['title'] ?? null);

    $matchedDbBook = null;
    if ($key && !empty($booksByKey[$key])) {
        // Prefer a book missing directory; fallback to any
        $matchedDbBook = collect($booksByKey[$key])->first(function ($b) {
            return !$b['exists'];
        }) ?? $booksByKey[$key][0];
    }

    if (!$matchedDbBook) {
        $stats['openaudible_not_moved']++;
        continue;
    }

    // If the DB book already has a valid directory, count as already present and skip
    if (!empty($matchedDbBook['directory']) && Storage::disk($booksDisk)->exists($matchedDbBook['directory'])) {
        $stats['openaudible_already_present']++;
        continue;
    }

    // Move file into the expected directory on the books disk
    $targetDir = rtrim($matchedDbBook['directory'] ?? '', '/');
    if (empty($targetDir)) {
        // Cannot move without a known target directory
        $stats['openaudible_not_moved']++;
        continue;
    }

    if ($dryRun) {
        log_change($logFile, [
            'ts' => date(DATE_ATOM),
            'op' => 'mkdir',
            'status' => 'planned',
            'disk' => $booksDisk,
            'path' => $targetDir,
        ]);
    } else {
        $created = Storage::disk($booksDisk)->makeDirectory($targetDir);
        log_change($logFile, [
            'ts' => date(DATE_ATOM),
            'op' => 'mkdir',
            'status' => $created ? 'executed' : 'skipped',
            'disk' => $booksDisk,
            'path' => $targetDir,
        ]);
    }

    $baseName = basename($sourcePath);
    $targetPath = $targetDir . '/' . $baseName;

    if ($dryRun) {
        $stats['planned_moved_files']++;
        // Inspect for cover presence without writing
        $hasCover = audio_has_embedded_cover($getID3, $sourcePath);
        if ($hasCover) {
            $stats['planned_cover_extracted']++;
        }
        log_change($logFile, [
            'ts' => date(DATE_ATOM),
            'op' => 'copy_move',
            'status' => 'planned',
            'source' => $sourcePath,
            'disk' => $booksDisk,
            'target' => $targetPath,
        ]);
        // Skip actual move and extraction
        continue;
    }

    $source = fopen($sourcePath, 'rb');
    if ($source === false) {
        $stats['openaudible_not_moved']++;
        continue;
    }

    log_change($logFile, [
        'ts' => date(DATE_ATOM),
        'op' => 'copy_move',
        'status' => 'executing',
        'source' => $sourcePath,
        'disk' => $booksDisk,
        'target' => $targetPath,
    ]);
    $putOk = Storage::disk($booksDisk)->put($targetPath, $source);
    if ($putOk) {
        // Delete source after successful copy
        @unlink($sourcePath);
        log_change($logFile, [
            'ts' => date(DATE_ATOM),
            'op' => 'delete',
            'status' => 'executed',
            'path' => $sourcePath,
        ]);
        $stats['moved_files']++;

        // Attempt to extract and save cover image
        $coverSaved = extract_and_save_cover($getID3, $targetPath, $targetDir, $booksDisk);
        if ($coverSaved['saved']) {
            $stats['cover_extracted']++;
            log_change($logFile, [
                'ts' => date(DATE_ATOM),
                'op' => 'write_cover',
                'status' => 'executed',
                'disk' => $booksDisk,
                'path' => $coverSaved['path'],
                'source_audio' => $targetPath,
            ]);
            // Update DB record cover_image if missing and clear needs_review
            /** @var Book $dbBook */
            $dbBook = Book::query()->find($matchedDbBook['id']);
            if ($dbBook) {
                if (empty($dbBook->cover_image ?? $dbBook->coverImage)) {
                    $dbBook->coverImage = $coverSaved['path'];
                }
                $dbBook->needs_review = false;
                $reasons = is_array($dbBook->needs_review_reasons ?? null) ? $dbBook->needs_review_reasons : [];
                $reasons = array_values(array_diff($reasons, ['invalid image', 'missing directory', 'no suitable image found']));
                $dbBook->needs_review_reasons = $reasons;
                $dbBook->save();
            }
        }
    } else {
        $stats['openaudible_not_moved']++;
        log_change($logFile, [
            'ts' => date(DATE_ATOM),
            'op' => 'copy_move',
            'status' => 'failed',
            'source' => $sourcePath,
            'disk' => $booksDisk,
            'target' => $targetPath,
        ]);
    }
}

// Print report
$report = [
    'db_missing_directories' => $stats['db_missing_directories'],
    'db_missing_covers' => $stats['db_missing_covers'],
    'openaudible_total' => $stats['openaudible_total'],
    'dry_run' => $dryRun,
    'limit' => $limit,
    'moved_files' => $stats['moved_files'],
    'cover_images_extracted' => $stats['cover_extracted'],
    'planned_moved_files' => $stats['planned_moved_files'],
    'planned_cover_images_extracted' => $stats['planned_cover_extracted'],
    'openaudible_not_moved' => $stats['openaudible_not_moved'],
    'openaudible_already_present' => $stats['openaudible_already_present'],
    'log_file' => $logFile,
];

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// -------- Helpers ---------

/**
 * Normalize author/title to a matching key.
 */
function normalize_key(?string $author, ?string $title): ?string
{
    $a = trim((string) ($author ?? ''));
    $t = trim((string) ($title ?? ''));
    if ($a === '' || $t === '') {
        return null;
    }
    $norm = function (string $s) {
        $s = Str::lower($s);
        $s = preg_replace('/[^a-z0-9]+/i', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        return $s;
    };
    return $norm($a) . '|' . $norm($t);
}

/**
 * Extracts basic audio metadata using getID3.
 * Returns ['title' => ?, 'author' => ?]
 */
function analyze_audio_metadata(getID3 $getID3, string $path): array
{
    $r = [
        'title' => null,
        'author' => null,
    ];
    try {
        $info = $getID3->analyze($path);
        if (!empty($info['tags'])) {
            $tags = $info['tags'];
            // Try common tag containers
            foreach (['id3v2', 'quicktime', 'id3v1'] as $container) {
                if (!empty($tags[$container])) {
                    $c = $tags[$container];
                    if (empty($r['title'])) {
                        $r['title'] = first_or_null($c['title'] ?? null);
                    }
                    if (empty($r['author'])) {
                        $r['author'] = first_or_null($c['artist'] ?? null) ?? first_or_null($c['author'] ?? null);
                    }
                }
            }
        }
        // Fallbacks
        if (empty($r['title'])) {
            $r['title'] = pathinfo($path, PATHINFO_FILENAME);
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $r;
}

/**
 * Extract embedded cover and save to target directory.
 * Returns ['saved' => bool, 'path' => string|null]
 */
function extract_and_save_cover(getID3 $getID3, string $audioPathRelative, string $targetDir, string $booksDisk): array
{
    $result = ['saved' => false, 'path' => null];

    try {
        // Need absolute path on local fs for getID3 to re-analyze; resolve via Storage adapter if possible.
        // We can temporarily save a stream to tmp if direct path unknown; however, common setups map disk to local fs.
        // Attempt: read file contents from disk and re-analyze from temp file for reliability.
        $tmpFile = tempnam(sys_get_temp_dir(), 'ab_cover_') . '.m4b';
        $stream = Storage::disk($booksDisk)->readStream($audioPathRelative);
        if ($stream === false) {
            return $result;
        }
        $out = fopen($tmpFile, 'wb');
        if ($out === false) {
            return $result;
        }
        stream_copy_to_stream($stream, $out);
        fclose($out);

        $info = $getID3->analyze($tmpFile);
        @unlink($tmpFile);

        $coverBin = null;
        $coverExt = 'jpg';
        if (!empty($info['comments']['picture'][0])) {
            $pic = $info['comments']['picture'][0];
            $coverBin = $pic['data'] ?? null;
            $mime = $pic['image_mime'] ?? ($pic['mime'] ?? 'image/jpeg');
            if (is_string($mime) && str_contains($mime, 'png')) {
                $coverExt = 'png';
            } elseif (is_string($mime) && str_contains($mime, 'webp')) {
                $coverExt = 'webp';
            } else {
                $coverExt = 'jpg';
            }
        }

        if (!$coverBin) {
            return $result;
        }

        $coverName = 'cover.' . $coverExt;
        $coverPath = rtrim($targetDir, '/') . '/' . $coverName;
        $saved = Storage::disk($booksDisk)->put($coverPath, $coverBin);
        if ($saved) {
            $result['saved'] = true;
            $result['path'] = $coverPath;
        }
    } catch (Throwable $e) {
        // ignore
    }

    return $result;
}

/**
 * Quickly check if an audio file has an embedded cover (without writing output).
 */
function audio_has_embedded_cover(getID3 $getID3, string $sourcePath): bool
{
    try {
        $info = $getID3->analyze($sourcePath);
        return !empty($info['comments']['picture'][0]);
    } catch (Throwable $e) {
        return false;
    }
}

function first_or_null($v)
{
    if (is_array($v)) {
        return $v[0] ?? null;
    }
    return $v;
}

/**
 * Parse simple CLI options: --dry-run, --limit=N, --help
 */
function parse_cli_options(array $argv): array
{
    $out = [
        'dry_run' => false,
        'limit' => 0,
        'help' => false,
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run' || $arg === '-n') {
            $out['dry_run'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $out['help'] = true;
        } elseif (str_starts_with($arg, '--limit=')) {
            $val = (int) substr($arg, strlen('--limit='));
            $out['limit'] = max(0, $val);
        } elseif ($arg === '-l') {
            // Next arg as limit (basic support)
            $out['limit'] = $out['limit'] ?: 0; // noop; keeping simple
        }
    }
    return $out;
}

function usage_text(): string
{
    return <<<TXT
Recover books from OpenAudible export

Usage:
  php scripts/recover_books_from_openaudible.php [--dry-run] [--limit=N]

Description:
  Scans /media/audiobooks/OpenAudible/books for .m4b files, matches metadata (author/title)
  against database books whose directory_path is missing on the 'books' disk. When matched,
  moves the file into the expected directory, extracts an embedded cover image, updates the DB,
  and prints a JSON report.

Options:
  --dry-run, -n   Perform a preview without moving files or changing the database
  --limit=N       Process only the first N .m4b files found
  --help, -h      Show this help message

Examples:
  php scripts/recover_books_from_openaudible.php --dry-run
  php scripts/recover_books_from_openaudible.php --limit=50
  php scripts/recover_books_from_openaudible.php --dry-run --limit=25
TXT;
}

/**
 * Append a JSON line to the log file.
 */
function log_change(string $logFile, array $entry): void
{
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/** Ensure log directory exists */
function ensure_log_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}
