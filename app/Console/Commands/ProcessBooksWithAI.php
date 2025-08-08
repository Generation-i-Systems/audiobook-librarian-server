<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Author;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Services\AIBookProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProcessBooksWithAI extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:process-ai 
                            {--book=* : Process specific book IDs}
                            {--limit=10 : Limit number of books to process (default 10 for free tier)}
                            {--min-confidence=70 : Minimum confidence level to auto-apply changes}
                            {--force : Skip confirmation prompts}
                            {--dry-run : Show what would be processed without making changes}
                            {--reprocess : Process books even if already AI-processed}
                            {--model=gemini-2.5-flash-lite : Model to use (gemini-2.0-flash, gemini-2.0-flash-lite, gemini-2.5-flash, gemini-2.5-flash-lite, gemini-2.5-pro, claude-3-5-haiku, claude-3-5-sonnet, claude-4-sonnet, claude-4-opus, gpt-4o-mini, gpt-4o, gpt-4-turbo, gpt-3.5-turbo)}
                            {--paid-tier : Use paid tier limits and pricing (requires billing setup)}
                            {--no-backup : Skip automatic database backup}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process books using AI to extract and improve metadata from directory paths, filenames, and audio tags (creates a database backup by default)';

    protected AIBookProcessor $aiProcessor;
    protected int $processedCount = 0;
    protected int $updatedCount = 0;
    protected int $errorCount = 0;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Create a database backup unless --no-backup is specified
        if (!$this->option('no-backup')) {
            $this->info('Creating a database backup before AI processing...');
            $this->call('backup:database');
            $this->info('Database backup created.');
        }

        // Check API keys based on model
        $model = $this->option('model');
        $isClaudeModel = str_starts_with($model, 'claude-');
        $isOpenAIModel = str_starts_with($model, 'gpt-');

        if ($isClaudeModel && empty(config('services.claude.api_key'))) {
            $this->error('Claude API key not configured. Please set CLAUDE_API_KEY in your .env file.');
            return Command::FAILURE;
        } elseif ($isOpenAIModel && empty(config('services.openai.api_key'))) {
            $this->error('OpenAI API key not configured. Please set OPENAI_API_KEY in your .env file.');
            return Command::FAILURE;
        } elseif (!$isClaudeModel && !$isOpenAIModel && empty(config('services.gemini.api_key'))) {
            $this->error('Gemini API key not configured. Please set GEMINI_API_KEY in your .env file.');
            return Command::FAILURE;
        }

        // Initialize processor with selected model and tier
        $model = $this->option('model');
        $paidTier = $this->option('paid-tier');

        try {
            $this->aiProcessor = new AIBookProcessor($model, $paidTier);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->info("Starting AI-powered book processing...");
        $modelInfo = $this->aiProcessor->getModelInfo();
        $this->info("Using model: {$modelInfo['model']} ({$modelInfo['tier']} tier)");

        $books = $this->getBooks();

        if ($books->isEmpty()) {
            $this->info('No books found to process.');
            return Command::SUCCESS;
        }

        $totalBooks = $books->count();
        $this->info("Found {$totalBooks} books to process.");

        // Show limits and cost estimates
        $this->displayLimitsAndCosts($totalBooks);

        // Check for high-cost operations and confirm
        if ($paidTier && !$this->option('dry-run')) {
            $costEstimate = $this->aiProcessor->estimateBatchCost($totalBooks);
            if ($costEstimate['total_cost'] > 1.0) {
                $this->warn("⚠️  This operation will cost approximately \${$costEstimate['total_cost']}");
                if (!$this->option('force') && !$this->confirm('This will cost more than $1.00. Are you sure you want to continue?', false)) {
                    $this->info('Processing cancelled to avoid high costs.');
                    return Command::SUCCESS;
                }
            }
        }

        if (!$this->option('force') && !$this->option('dry-run')) {
            if (!$this->confirm('Continue with processing?', true)) {
                $this->info('Processing cancelled.');
                return Command::SUCCESS;
            }
        }

        $progressBar = $this->output->createProgressBar($totalBooks);
        $progressBar->start();

        foreach ($books as $book) {
            try {
                $this->processBook($book);
                $this->processedCount++;
            } catch (\Exception $e) {
                $this->errorCount++;
                Log::error('AI book processing failed', [
                    'book_id' => $book->id,
                    'error' => $e->getMessage()
                ]);

                if ($this->option('verbose')) {
                    $this->newLine();
                    $this->error("Failed to process book {$book->id}: {$e->getMessage()}");
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->displaySummary();

        return Command::SUCCESS;
    }

    /**
     * Get books to process based on options
     */
    protected function getBooks()
    {
        $query = Book::query();

        // Specific book IDs
        if ($this->option('book')) {
            $query->whereIn('id', $this->option('book'));
        }

        // Skip already AI-processed books unless reprocessing
        if (!$this->option('reprocess')) {
            $query->where(function ($q) {
                $q->whereNull('ai_processed')
                  ->orWhere('ai_processed', false);
            });
        }

        // Only process books with directory paths
        $query->whereNotNull('directoryPath')
              ->where('directoryPath', '!=', '');

        // Apply limit (default to 10 for free tier)
        $limit = $this->option('limit') ?: 10;
        $query->limit($limit);

        return $query->get();
    }

    /**
     * Process a single book with AI
     */
    protected function processBook(Book $book): void
    {
        if ($this->option('verbose')) {
            $this->newLine();
            $this->info("Processing book: {$book->title} (ID: {$book->id})");
            $this->line("Directory: {$book->directoryPath}");
        }

        // Get audio files from the directory
        $audioFiles = $this->aiProcessor->getAudioFiles($book->directoryPath);

        if (empty($audioFiles)) {
            if ($this->option('verbose')) {
                $this->warn("No audio files found in directory: {$book->directoryPath}");
            }
            return;
        }

        // Extract file tags from a sample of files (first 3 files to avoid rate limits)
        $fileTags = $this->extractFileTags($audioFiles, $book->directoryPath, 3);

        // Get just filenames for the AI
        $fileNames = array_map('basename', $audioFiles);

        // Process with AI
        $aiMetadata = $this->aiProcessor->processBookDirectory(
            $book->directoryPath,
            $fileNames,
            $fileTags
        );

        if ($this->option('dry-run')) {
            $this->displayProposedChanges($book, $aiMetadata);
            return;
        }

        // Apply changes based on confidence level
        $minConfidence = $this->option('min-confidence');

        if ($aiMetadata['confidence'] >= $minConfidence || $this->option('force')) {
            $this->applyAIMetadata($book, $aiMetadata);
            $this->updatedCount++;

            if ($this->option('verbose')) {
                $this->info("✓ Updated book with AI metadata (confidence: {$aiMetadata['confidence']}%)");
            }
        } else {
            if ($this->option('verbose')) {
                $this->warn("⚠ Skipped book due to low confidence ({$aiMetadata['confidence']}% < {$minConfidence}%)");
            }

            // Still save the AI metadata for manual review
            $this->saveAIMetadataForReview($book, $aiMetadata);
        }
    }

    /**
     * Extract file tags from audio files
     */
    protected function extractFileTags(array $audioFiles, string $directoryPath, int $maxFiles = 3): array
    {
        $fileTags = [];
        $diskName = 'books';
        $processedFiles = 0;

        foreach ($audioFiles as $audioFile) {
            if ($processedFiles >= $maxFiles) {
                break;
            }

            $fullPath = Storage::disk($diskName)->path($audioFile);

            if (file_exists($fullPath)) {
                $tags = $this->aiProcessor->extractFileTags($fullPath);
                if (!empty($tags)) {
                    $fileTags[basename($audioFile)] = $tags;
                    $processedFiles++;
                }
            }
        }

        return $fileTags;
    }

    /**
     * Display proposed changes for dry run
     */
    protected function displayProposedChanges(Book $book, array $aiMetadata): void
    {
        $this->newLine();
        $this->info("Book ID: {$book->id}");
        $this->line("Current Title: <fg=red>{$book->title}</>");
        $this->line("AI Title: <fg=green>{$aiMetadata['title']}</>");

        $this->line("Current Authors: " . implode(', ', $book->authors->pluck('name')->toArray()));
        $this->line("AI Authors: " . implode(', ', $aiMetadata['author']));

        if (!empty($aiMetadata['narrator'])) {
            $this->line("AI Narrators: " . implode(', ', $aiMetadata['narrator']));
        }

        if ($aiMetadata['series']) {
            $seriesText = $aiMetadata['series'];
            if ($aiMetadata['series_number']) {
                $seriesText .= " (Book {$aiMetadata['series_number']})";
            }
            $this->line("AI Series: {$seriesText}");
        }

        if (!empty($aiMetadata['genre'])) {
            $this->line("AI Genres: " . implode(', ', $aiMetadata['genre']));
        }

        $this->line("AI Confidence: {$aiMetadata['confidence']}%");
        $this->line(str_repeat('-', 50));
    }

    /**
     * Apply AI metadata to the book
     */
    protected function applyAIMetadata(Book $book, array $aiMetadata): void
    {
        DB::transaction(function () use ($book, $aiMetadata) {
            // Update basic book information
            $book->title = $aiMetadata['title'];

            if ($aiMetadata['year']) {
                $book->release_date = $aiMetadata['year'] . '-01-01';
            }

            if ($aiMetadata['description']) {
                $book->description = $aiMetadata['description'];
            }

            if ($aiMetadata['publisher']) {
                $book->publisher = $aiMetadata['publisher'];
            }

            if ($aiMetadata['isbn']) {
                $book->isbn = $aiMetadata['isbn'];
            }

            if ($aiMetadata['language']) {
                $book->language = $aiMetadata['language'];
            }

            // Mark as AI processed
            $book->ai_processed = true;
            $book->ai_confidence = $aiMetadata['confidence'];
            $book->ai_processed_at = now();

            $book->save();

            // Handle authors
            if (!empty($aiMetadata['author'])) {
                $authorIds = [];
                foreach ($aiMetadata['author'] as $authorName) {
                    $author = Author::firstOrCreate(['name' => trim($authorName)]);
                    $authorIds[] = $author->id;
                }
                $book->authors()->sync($authorIds);
            }

            // Handle narrators
            if (!empty($aiMetadata['narrator'])) {
                $narratorIds = [];
                foreach ($aiMetadata['narrator'] as $narratorName) {
                    $narrator = Narrator::firstOrCreate(['name' => trim($narratorName)]);
                    $narratorIds[] = $narrator->id;
                }
                $book->narrators()->sync($narratorIds);
            }

            // Handle genres
            if (!empty($aiMetadata['genre'])) {
                $genreIds = [];
                foreach ($aiMetadata['genre'] as $genreName) {
                    $genre = Genre::firstOrCreate(['name' => trim($genreName)]);
                    $genreIds[] = $genre->id;
                }
                $book->genres()->sync($genreIds);
            }

            // Handle series
            if ($aiMetadata['series']) {
                $series = Series::firstOrCreate(['name' => trim($aiMetadata['series'])]);
                $seriesNumber = $aiMetadata['series_number'] ?? 1;

                // Sync series relationship
                $book->series()->sync([
                    $series->id => ['series_number' => $seriesNumber]
                ]);
            }
        });
    }

    /**
     * Save AI metadata for manual review when confidence is low
     */
    protected function saveAIMetadataForReview(Book $book, array $aiMetadata): void
    {
        $book->update([
            'ai_suggestions' => json_encode($aiMetadata),
            'ai_processed' => false,
            'needs_review' => true,
            'needs_review_reasons' => json_encode(['low_ai_confidence'])
        ]);
    }

    /**
     * Display limits and cost information
     */
    protected function displayLimitsAndCosts(int $totalBooks): void
    {
        $modelInfo = $this->aiProcessor->getModelInfo();
        $limits = $modelInfo['limits'];

        if ($modelInfo['tier'] === 'free') {
            $this->warn("Free tier limits:");
            $this->line("  • {$limits['requests_per_minute']} requests/minute");
            if ($limits['requests_per_day']) {
                $this->line("  • {$limits['requests_per_day']} requests/day");
            }
            $estimatedTime = ceil($totalBooks / $limits['requests_per_minute']);
            $this->info("Estimated processing time: ~{$estimatedTime} minutes");
        } else {
            $this->info("Paid tier limits:");
            $this->line("  • {$limits['requests_per_minute']} requests/minute");
            $this->line("  • No daily limit");

            $costEstimate = $this->aiProcessor->estimateBatchCost($totalBooks);
            $this->info("💰 Estimated cost: \${$costEstimate['total_cost']} (\${$costEstimate['cost_per_book']} per book)");

            if ($costEstimate['total_cost'] > 0.10) {
                $this->warn("💡 Consider using free tier for small batches to save costs");
            }
        }
    }

    /**
     * Display processing summary
     */
    protected function displaySummary(): void
    {
        $this->newLine();
        $this->info('AI Book Processing Summary:');
        $this->line("Total processed: {$this->processedCount}");
        $this->line("Books updated: {$this->updatedCount}");

        if ($this->errorCount > 0) {
            $this->line("Errors: {$this->errorCount}");
        }

        if ($this->option('dry-run')) {
            $this->info('This was a dry run - no changes were made.');
        }

        $lowConfidenceCount = $this->processedCount - $this->updatedCount - $this->errorCount;
        if ($lowConfidenceCount > 0) {
            $this->info("{$lowConfidenceCount} books saved for manual review due to low confidence.");
        }

        // Show actual costs for paid tier
        if ($this->option('paid-tier') && !$this->option('dry-run')) {
            $totalCost = $this->aiProcessor->getTotalCost();
            if ($totalCost > 0) {
                $this->newLine();
                $this->info("💰 Total cost: \${$totalCost}");

                if ($totalCost > 0.50) {
                    $this->warn("💡 Significant cost incurred. Consider using free tier for development.");
                }
            }
        }
    }
}
