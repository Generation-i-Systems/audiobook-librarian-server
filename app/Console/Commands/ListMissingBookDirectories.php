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
                            {--output= : Optional file path to write results}';

    protected $description = 'Generate a list of book directory_path values that do not exist on disk';

    public function handle(): int
    {
        $diskName = (string) $this->option('disk');
        $format = strtolower((string) $this->option('format'));
        $outputPath = (string) ($this->option('output') ?? '');

        $disk = Storage::disk($diskName);

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

        // Unique and sorted for stable output
        $missing = array_values(array_unique($missing));
        sort($missing, SORT_NATURAL | SORT_FLAG_CASE);

        if ($format === 'json') {
            $payload = json_encode([
                'disk' => $diskName,
                'count' => count($missing),
                'paths' => $missing,
            ], JSON_UNESCAPED_SLASHES);
            if ($outputPath !== '') {
                file_put_contents($outputPath, $payload . PHP_EOL);
                $this->info("Wrote JSON list to {$outputPath} ({$diskName})");
            } else {
                $this->line($payload);
            }
        } else {
            // default txt
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
