<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MigrateBooksCamelCaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:migrate-camelcase {--no-backup : Skip automatic database backup}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate all book records in Firestore from snake_case to camelCase and remove snake_case fields (creates a database backup by default)';

    /**
     * The Firestore service instance.
     */
    protected DocumentStoreServiceInterface $documentStoreService;

    /**
     * Field mapping from snake_case to camelCase.
     *
     * @var array
     */
    protected $fieldMapping = [
        'series_number' => 'seriesNumber',
        'directory_path' => 'directoryPath',
        'published_year' => 'publishedYear',
        'cover_image' => 'coverImage',
        'cover_image_candidate' => 'coverImageCandidate',
        'duration_formatted' => 'durationFormatted',
        'file_size' => 'fileSize',
        'file_modified' => 'fileModified',
        'file_extension' => 'fileExtension',
        'full_path' => 'fullPath',
        'needs_review' => 'needsReview',
        'review_reason' => 'reviewReason',
        'audio_file_count' => 'audioFileCount',
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
    ];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        parent::__construct();
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Create a database backup unless --no-backup is specified
        if (!$this->option('no-backup')) {
            $this->info('Creating a database backup before migrating book camelCase...');
            $this->call('backup:database');
            $this->info('Database backup created.');
        }

        $this->info(
            'Starting migration of book records from snake_case to camelCase and removing snake_case fields...'
        );

        try {
            // Get all books from Firestore
            $books = $this->documentStoreService->listBooks();
            $this->info('Found ' . count($books) . ' books to process');

            $bar = $this->output->createProgressBar(count($books));
            $bar->start();

            $updated = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($books as $book) {
                $bookId = $book['id'];
                $updatedBook = $this->convertToCamelCase($book);

                // Only update if changes were made
                if ($updatedBook !== $book) {
                    try {
                        // Update the book in Firestore
                        $this->documentStoreService->updateBook($bookId, $updatedBook);
                        $updated++;
                    } catch (\Exception $e) {
                        $this->error("Error updating book {$bookId}: " . $e->getMessage());
                        Log::error("Error updating book {$bookId} to camelCase: " . $e->getMessage());
                        $errors++;
                    }
                } else {
                    $skipped++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            $this->info('Migration completed:');
            $this->info("- Updated: {$updated} books");
            $this->info("- Skipped: {$skipped} books (already in camelCase)");
            $this->info("- Errors: {$errors} books");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Migration failed: ' . $e->getMessage());
            Log::error('Book camelCase migration failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Convert book data from snake_case to camelCase.
     *
     * @return array
     */
    protected function convertToCamelCase(array $book)
    {
        $updatedBook = $book;
        $changed = false;

        // Process direct field mappings
        foreach ($this->fieldMapping as $snakeCase => $camelCase) {
            // If snake_case exists, convert to camelCase
            if (isset($book[$snakeCase])) {
                // Only set camelCase if it doesn't exist or if snake_case value is different
                if (
                    !isset($book[$camelCase]) ||
                    $book[$camelCase] !== $book[$snakeCase]
                ) {
                    $updatedBook[$camelCase] = $book[$snakeCase];
                    $changed = true;
                }

                // Remove the snake_case field
                unset($updatedBook[$snakeCase]);
                $changed = true;
            }
        }

        // Handle nested arrays and objects
        foreach ($updatedBook as $key => $value) {
            if (is_array($value) && !empty($value)) {
                // Check if it's an associative array
                if (array_keys($value) !== range(0, count($value) - 1)) {
                    $updatedBook[$key] = $this->convertToCamelCase($value);
                    if ($updatedBook[$key] !== $value) {
                        $changed = true;
                    }
                }
            }
        }

        return $changed ? $updatedBook : $book;
    }
}
