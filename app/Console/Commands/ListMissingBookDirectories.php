<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ListMissingBookDirectories extends Command
{
    protected $signature = 'books:list-missing-directories
                            {--disk=books : Storage disk to check}
                            {--format=txt : Output format: txt|json}
                            {--output= : Optional file path to write results}
                            {--unreferenced : Scan disk and list directories that contain audio files but no DB book}
                            {--root= : Optional root path within the disk to limit scanning when using --unreferenced}';

    protected $description = 'Generate a list of book directory_path values that do not exist on disk';

    public function handle(): int
    {
        $diskName = (string) $this->option('disk');
        $format = strtolower((string) $this->option('format'));
        $outputPath = (string) ($this->option('output') ?? '');

        $disk = Storage::disk($diskName);

        $isUnreferencedMode = (bool) $this->option('unreferenced');

        if ($isUnreferencedMode) {
            $root = (string) ($this->option('root') ?? '');
            $extensions = (array) config('bookparser.extensions', ['mp3', 'm4b', 'm4a', 'mp4', 'ogg', 'flac', 'aac', 'wav']);
            $extMap = array_fill_keys(array_map('strtolower', $extensions), true);
            $excludePatterns = (array) config('bookparser.exclude_dirs', []);

            // Build set of DB-referenced directories
            $dbDirs = Book::query()
                ->whereNotNull('directory_path')
                ->pluck('directory_path')
                ->filter(fn ($p) => is_string($p) && $p !== '')
                ->values()
                ->all();
            $dbDirSet = array_fill_keys($dbDirs, true);

            // Scan files recursively and collect their directories if audio
            $files = $disk->allFiles($root ?: null);
            $candidateDirs = [];
            foreach ($files as $file) {
                $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
                if ($ext === '' || !isset($extMap[$ext])) {
                    continue;
                }
                $dir = trim(str_replace('\\', '/', dirname($file)), '/');
                if ($dir === '.') {
                    $dir = '';
                }
                // Exclude directories by pattern
                if ($dir !== '') {
                    $skip = false;
                    foreach ($excludePatterns as $pattern) {
                        if (@preg_match('/' . $pattern . '/i', $dir)) {
                            if (preg_match('/' . $pattern . '/i', $dir)) {
                                $skip = true;
                                break;
                            }
                        }
                    }
                    if ($skip) {
                        continue;
                    }
                }
                $candidateDirs[$dir] = true;
            }

            // Find dirs that are not referenced by any DB book
            $unreferenced = [];
            foreach (array_keys($candidateDirs) as $dir) {
                if (!isset($dbDirSet[$dir])) {
                    $unreferenced[] = $dir;
                }
            }

            $unreferenced = array_values(array_unique($unreferenced));
            sort($unreferenced, SORT_NATURAL | SORT_FLAG_CASE);

            if ($format === 'json') {
                $payload = json_encode([
                    'disk' => $diskName,
                    'count' => count($unreferenced),
                    'directories' => $unreferenced,
                    'mode' => 'unreferenced',
                    'root' => $root,
                ], JSON_UNESCAPED_SLASHES);
                if ($outputPath !== '') {
                    file_put_contents($outputPath, $payload . PHP_EOL);
                    $this->info("Wrote JSON list to {$outputPath} ({$diskName})");
                } else {
                    $this->line($payload);
                }
            } else {
                $lines = implode(PHP_EOL, $unreferenced) . (count($unreferenced) ? PHP_EOL : '');
                if ($outputPath !== '') {
                    file_put_contents($outputPath, $lines);
                    $this->info("Wrote list to {$outputPath} ({$diskName})");
                } else {
                    foreach ($unreferenced as $p) {
                        $this->line($p);
                    }
                }
            }

            $this->info('Unreferenced directories: ' . count($unreferenced));
            return self::SUCCESS;
        }

        // Default mode: list missing directories referenced by DB
        $books = Book::query()
            ->whereNotNull('directory_path')
            ->pluck('directory_path', 'id');

        $missing = [];
        foreach ($books as $id => $path) {
            $path = (string) $path;
            if ($path === '') {
                continue;
            }
            if (!$disk->exists($path)) {
                $missing[] = $path;
            }
        }

        $missing = array_values(array_unique($missing));
        sort($missing, SORT_NATURAL | SORT_FLAG_CASE);

        if ($format === 'json') {
            $payload = json_encode([
                'disk' => $diskName,
                'count' => count($missing),
                'paths' => $missing,
                'mode' => 'missing',
            ], JSON_UNESCAPED_SLASHES);
            if ($outputPath !== '') {
                file_put_contents($outputPath, $payload . PHP_EOL);
                $this->info("Wrote JSON list to {$outputPath} ({$diskName})");
            } else {
                $this->line($payload);
            }
        } else {
            $lines = implode(PHP_EOL, $missing) . (count($missing) ? PHP_EOL : '');
            if ($outputPath !== '') {
                file_put_contents($outputPath, $lines);
                $this->info("Wrote list to {$outputPath} ({$diskName})");
            } else {
                foreach ($missing as $p) {
                    $this->line($p);
                }
            }
        }

        $this->info('Missing directories: ' . count($missing));
        return self::SUCCESS;
    }
}
