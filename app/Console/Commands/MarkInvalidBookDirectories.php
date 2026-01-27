<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Book;

class MarkInvalidBookDirectories extends Command
{
    protected $signature = 'books:mark-invalid-directories
                            {--disk=books : Storage disk to check}
                            {--limit=0 : Limit number of books to process (0 for all)}
                            {--dry-run : Only report, do not modify database}';

    protected $description = 'Mark books with missing/invalid directory_path as needs_review with reasons.';

    public function handle(): int
    {
        $disk = Storage::disk($this->option('disk'));
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $query = Book::whereNotNull('directory_path');
        $total = $query->count();
        $this->info("Scanning {$total} books for invalid directory paths on disk '{$this->option('disk')}'.");

        $books = $query->cursor();
        $processed = 0;
        $marked = 0;

        foreach ($books as $book) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
            $processed++;

            $path = $book->directory_path ?? '';
            if ($path === '') {
                continue;
            }

            $exists = $disk->exists($path);
            if (!$exists) {
                $this->line("❌ Missing: [{$book->id}] {$book->title} -> {$path}");

                if (!$dryRun) {
                    $reasons = (array) ($book->needs_review_reasons ?: []);

                    $reasons = array_values(array_unique(array_merge($reasons, ['missing_directory'])));

                    $book->needs_review = true;
                    $book->needs_review_reasons = $reasons;
                    $book->save();
                }
                $marked++;
            }
        }

        $this->info(($dryRun ? 'DRY RUN: ' : '') . "Marked {$marked} of {$processed} processed books as needs_review.");
        if ($dryRun) {
            $this->info('Run without --dry-run to apply changes.');
        }

        return 0;
    }
}
