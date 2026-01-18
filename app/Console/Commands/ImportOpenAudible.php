<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * @deprecated Use book:import instead - it auto-detects OpenAudible directories
 */
class ImportOpenAudible extends Command
{
    protected $signature = 'books:import-openaudible
                            {--source=/media/audiobooks/OpenAudible : OpenAudible directory}
                            {--dry-run : Show what would be imported without making changes}
                            {--include-old : Also import books from books_old directory}
                            {--force : Reimport books that already exist}
                            {--auto-replace : Automatically replace audio files for duplicates}
                            {--auto-merge : Automatically merge files for duplicates}
                            {--auto-skip : Automatically skip duplicates}
                            {--limit= : Limit number of books to import}';

    protected $description = '[DEPRECATED] Use book:import instead - it auto-detects OpenAudible directories';

    public function handle(): int
    {
        $this->error('');
        $this->error('  ╔══════════════════════════════════════════════════════════════╗');
        $this->error('  ║  DEPRECATED: books:import-openaudible has been removed       ║');
        $this->error('  ╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->info('  Use "book:import" instead - it auto-detects OpenAudible directories.');
        $this->newLine();

        $this->line('  <fg=yellow>Examples:</>');
        $this->line('    php artisan book:import /media/audiobooks/OpenAudible/books');
        $this->line('    php artisan book:import --include-old');
        $this->line('    ibk /media/audiobooks/OpenAudible/books');
        $this->newLine();

        $this->line('  <fg=yellow>Features:</>');
        $this->line('    • Auto-detects OpenAudible directories and uses books.json metadata');
        $this->line('    • Includes chapters, ASIN, and genre mapping from OpenAudible');
        $this->line('    • Skips enrichment when OpenAudible data is available');
        $this->line('    • Use --include-old to include books_old directory');
        $this->newLine();

        // Offer to run the new command
        $source = rtrim((string) $this->option('source'), '/');
        $booksDir = $source . '/books';

        if (is_dir($booksDir)) {
            $this->line("  Would you like to run: <fg=cyan>php artisan book:import {$booksDir}</>");

            if ($this->confirm('  Run book:import now?', true)) {
                $args = ['path' => [$booksDir]];

                if ($this->option('dry-run')) {
                    $args['--dry-run'] = true;
                }
                if ($this->option('include-old')) {
                    $args['--include-old'] = true;
                }
                if ($this->option('force')) {
                    $args['--force'] = true;
                }
                if ($this->option('limit')) {
                    $args['--limit'] = $this->option('limit');
                }

                return $this->call('book:import', $args);
            }
        }

        return 1;
    }
}
