<?php

namespace App\Console\Commands;

use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class FixDuplicatedTitleDirectories extends Command
{
    protected $signature = 'books:fix-duplicated-title-directories {--apply : Actually perform fixes instead of dry-run}';

    protected $description = 'Fix book directories on disk where the title segment is duplicated (.../Title/Title) while database directory_path is correct';

    public function handle(): int
    {
        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $apply = (bool) $this->option('apply');

        $this->info('Scanning for duplicated title directories (pattern: .../Title/Title)');
        $this->line('Book root: ' . $bookRoot);
        $this->line($apply ? 'Mode: APPLY (will move directories)' : 'Mode: DRY-RUN (no changes will be made)');

        $fixedCount = 0;
        $skippedCount = 0;

        Book::chunk(200, function ($books) use (&$fixedCount, &$skippedCount, $bookRoot, $apply) {
            foreach ($books as $book) {
                if (empty($book->directory_path) || empty($book->title)) {
                    $skippedCount++;
                    continue;
                }

                $relativePath = ltrim($book->directory_path, '/');
                $expectedDir = $bookRoot . '/' . $relativePath;
                $title = trim($book->title);

                // Only consider paths where we expect .../<title> as the final segment from DB
                if (basename($expectedDir) !== $title) {
                    $skippedCount++;
                    continue;
                }

                $duplicatedDir = $expectedDir . '/' . $title;

                if (!File::isDirectory($duplicatedDir)) {
                    $skippedCount++;
                    continue;
                }

                $this->warn("Found duplicated directory for book ID {$book->id}: {$duplicatedDir}");

                if (!$apply) {
                    $fixedCount++;
                    continue;
                }

                $parentDir = dirname($expectedDir);
                if (!File::isDirectory($parentDir)) {
                    File::makeDirectory($parentDir, 0775, true);
                }

                // Ensure expectedDir exists
                if (!File::isDirectory($expectedDir)) {
                    File::makeDirectory($expectedDir, 0775, true);
                }

                // Move all files (and subdirectories) from duplicatedDir into expectedDir
                $files = File::allFiles($duplicatedDir);
                foreach ($files as $file) {
                    $relative = ltrim(str_replace($duplicatedDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                    $targetPath = $expectedDir . DIRECTORY_SEPARATOR . $relative;
                    $targetDir = dirname($targetPath);

                    if (!File::isDirectory($targetDir)) {
                        File::makeDirectory($targetDir, 0775, true);
                    }

                    if (File::exists($targetPath)) {
                        $this->warn("  Skipping existing file for book ID {$book->id}: {$targetPath}");
                        continue;
                    }

                    File::move($file->getPathname(), $targetPath);
                }

                // Remove the now-empty duplicated directory tree
                File::deleteDirectory($duplicatedDir);
                $this->info("  ✓ Fixed book ID {$book->id}: merged contents into {$expectedDir} and removed duplicated directory");

                $fixedCount++;
            }
        });

        $this->newLine();
        $this->info('Summary:');
        $this->line('  Fixed:   ' . $fixedCount);
        $this->line('  Skipped: ' . $skippedCount);

        if (!$apply && $fixedCount > 0) {
            $this->line('Run again with --apply to perform these fixes.');
        }

        return Command::SUCCESS;
    }
}
