<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Author;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Services\AIBookProcessor;
use App\Services\AudioFileAnalyzer;
use App\Services\AudibleService;
use App\Services\ExternalCoverService;
use App\Services\GoogleBooksApiService;
use App\Traits\GenreMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportBooksFromDownloads extends Command
{
    use GenreMapping;
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'books:import-downloads 
                            {--directory=* : Custom directories to scan (defaults to /media/download and /media/download/audiobooks)}
                            {--model=gemini-2.5-flash-lite : AI model to use for processing}
                            {--min-confidence=80 : Minimum AI confidence for auto-import}
                            {--auto : Fully automated mode - no manual review}
                            {--dry-run : Show what would be imported without making changes}
                            {--limit=10 : Maximum number of books to process per run}
                            {--force : Skip confirmation prompts}
                            {--skip-enrichment : Skip external data enrichment (Audible, Google Books)}
                            {--copy-files : Copy files after successful import instead of moving (default is move)}
                            {--no-backup : Skip automatic database backup}';

    /**
     * The console command description.
     */
    protected $description = 'Import audiobooks from download directories using AI processing and external data enrichment (creates a database backup by default)';

    protected ?AIBookProcessor $aiProcessor = null;
    protected ?AudioFileAnalyzer $audioAnalyzer = null;
    protected ?AudibleService $audibleService = null;
    protected ?ExternalCoverService $coverService = null;
    protected ?GoogleBooksApiService $googleBooksService = null;
    protected array $processedBooks = [];
    protected array $failedBooks = [];
    protected array $skippedBooks = [];
    protected int $totalFound = 0;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Create a database backup unless --no-backup is specified
        if (!$this->option('no-backup')) {
            $this->info('Creating a database backup before importing books...');
            $this->call('backup:database');
            $this->info('Database backup created.');
        }

        $this->info("🚀 Starting automated audiobook import from download directories...");
        
        // Initialize AI processor
        $model = $this->option('model');
        try {
            $this->aiProcessor = new AIBookProcessor($model, true);
            $this->info("✅ AI processor initialized with model: {$model}");
        } catch (\Exception $e) {
            $this->error("❌ Failed to initialize AI processor: " . $e->getMessage());
            return Command::FAILURE;
        }

        // Get directories to scan
        $directories = $this->getDirectoriesToScan();
        if (empty($directories)) {
            $this->error("❌ No valid directories found to scan");
            return Command::FAILURE;
        }

        $this->info("📁 Scanning directories: " . implode(', ', $directories));

        // Scan for audiobooks
        $audiobooks = $this->scanForAudiobooks($directories);
        $this->totalFound = count($audiobooks);

        if (empty($audiobooks)) {
            $this->info("ℹ️  No audiobooks found in specified directories");
            return Command::SUCCESS;
        }

        $this->info("📚 Found {$this->totalFound} potential audiobooks");

        // Apply limit
        $limit = $this->option('limit');
        if ($limit && count($audiobooks) > $limit) {
            $audiobooks = array_slice($audiobooks, 0, $limit);
            $this->warn("⚠️  Processing limited to {$limit} books (use --limit=0 for no limit)");
        }

        // Show cost estimate for AI processing
        $this->showCostEstimate(count($audiobooks));


        // Process each audiobook
        $progressBar = $this->output->createProgressBar(count($audiobooks));
        $progressBar->start();

        foreach ($audiobooks as $audiobook) {
            try {
                $this->processAudiobook($audiobook);
            } catch (\Exception $e) {
                $this->failedBooks[] = [
                    'path' => $audiobook['path'],
                    'error' => $e->getMessage()
                ];
                Log::error("Import failed for {$audiobook['path']}: " . $e->getMessage());
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Show summary
        $this->displaySummary();

        return Command::SUCCESS;
    }

    /**
     * Get directories to scan for audiobooks
     */
    protected function getDirectoriesToScan(): array
    {
        $directories = [];
        
        // Check for custom directories
        $customDirs = $this->option('directory');
        if (!empty($customDirs)) {
            foreach ($customDirs as $dir) {
                if (is_dir($dir) && is_readable($dir)) {
                    $directories[] = $dir;
                } else {
                    $this->warn("⚠️  Directory not accessible: {$dir}");
                }
            }
        } else {
            // Use default directories
            $defaultDirs = ['/media/download', '/media/download/audiobooks'];
            foreach ($defaultDirs as $dir) {
                if (is_dir($dir) && is_readable($dir)) {
                    $directories[] = $dir;
                }
            }
        }

        return $directories;
    }

    /**
     * Scan directories for audiobook folders/files
     */
    protected function scanForAudiobooks(array $directories): array
    {
        $audiobooks = [];
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];

        foreach ($directories as $directory) {
            $this->info("🔍 Scanning: {$directory}");
            
            // Get all subdirectories and files
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            $potentialBooks = [];
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $extension = strtolower($file->getExtension());
                    if (in_array($extension, $audioExtensions)) {
                        $bookDir = $file->getPath();
                        if (!isset($potentialBooks[$bookDir])) {
                            $potentialBooks[$bookDir] = [
                                'path' => $bookDir,
                                'name' => basename($bookDir),
                                'files' => [],
                                'total_size' => 0
                            ];
                        }
                        $potentialBooks[$bookDir]['files'][] = $file->getPathname();
                        $potentialBooks[$bookDir]['total_size'] += $file->getSize();
                    }
                }
            }

            // Filter out directories with too few files or too small size
            foreach ($potentialBooks as $bookData) {
                if (count($bookData['files']) >= 1 && $bookData['total_size'] > 10 * 1024 * 1024) { // At least 10MB
                    // Check if already imported
                    if (!$this->isAlreadyImported($bookData['path'])) {
                        $audiobooks[] = $bookData;
                    } else {
                        $this->skippedBooks[] = [
                            'path' => $bookData['path'],
                            'reason' => 'Already imported'
                        ];
                    }
                }
            }
        }

        return $audiobooks;
    }

    /**
     * Check if audiobook is already imported
     */
    protected function isAlreadyImported(string $path, array $metadata = []): bool
    {
        $baseName = basename($path);
        
        // First check by ISBN if available (most reliable)
        if (!empty($metadata['isbn'])) {
            $existingBook = Book::where('isbn', $metadata['isbn'])->first();
            if ($existingBook) {
                return true;
            }
        }
        
        // Then check by exact title and author combination (if available)
        if (!empty($metadata['title']) && !empty($metadata['author'])) {
            $title = $metadata['title'];
            $author = is_array($metadata['author']) ? $metadata['author'][0] : $metadata['author'];
            
            $existingBook = Book::where('title', $title)
                ->whereHas('authors', function($query) use ($author) {
                    $query->where('name', $author);
                })
                ->first();
                
            if ($existingBook) {
                return true;
            }
        }
        
        // Fallback to directory path and title similarity
        $existingBook = Book::where('directory_path', 'like', '%' . $baseName . '%')
            ->orWhere('title', 'like', '%' . $baseName . '%')
            ->first();
            
        return $existingBook !== null;
    }

    /**
     * Process a single audiobook with AI and external enrichment
     */
    protected function processAudiobook(array $audiobook): void
    {
        $this->newLine();
        $this->info("📖 Processing: " . $audiobook['name']);
        $this->line("📁 Path: " . $audiobook['path']);
        $this->line("📄 Files: " . count($audiobook['files']) . " (" . $this->formatBytes($audiobook['total_size']) . ")");

        // Step 1: AI Processing
        $aiMetadata = $this->processWithAI($audiobook);
        
        if (!$aiMetadata || $aiMetadata['confidence'] < $this->option('min-confidence')) {
            $this->warn("⚠️  AI confidence too low (" . ($aiMetadata['confidence'] ?? 0) . "%) - skipping");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'Low AI confidence'
            ];
            return;
        }

        $this->info("✅ AI processing successful (confidence: {$aiMetadata['confidence']}%)");
        
        // Check for duplicates with AI-extracted metadata (more accurate than path-based check)
        if ($this->isAlreadyImported($audiobook['path'], $aiMetadata)) {
            $this->warn("⚠️  Book already exists (detected after AI processing) - skipping");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'Duplicate book detected'
            ];
            return;
        }
        
        // Step 2: External data enrichment (before manual review)
        if (!$this->option('skip-enrichment')) {
            $this->info("🔍 Enriching with external data...");
            $enrichedData = $this->enrichWithExternalData($aiMetadata);
            if ($enrichedData) {
                $aiMetadata = array_merge($aiMetadata, $enrichedData);
                $this->info("✅ External data enrichment completed");
            }
        }
        
        // Show expected directory path
        $expectedPath = $this->generateDirectoryPath($aiMetadata);
        $this->info("📁 Expected directory path: {$expectedPath}");
        
        $this->displayEnrichedMetadata($aiMetadata);

        // Step 3: Manual review (unless in auto mode)
        if (!$this->option('auto') && !$this->option('dry-run')) {
            if (!$this->reviewAndApprove($aiMetadata, $audiobook)) {
                $this->warn("❌ Import rejected by user");
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'Rejected by user'
                ];
                return;
            }
        } elseif ($this->option('auto') && !$this->hasEnrichmentData($aiMetadata)) {
            // In auto mode, skip books with no enrichment data as the detected fields might be wrong
            $this->warn("⚠️  No enrichment data found in auto mode - skipping (detected fields might be incorrect)");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'No enrichment data in auto mode'
            ];
            return;
        }

        // Step 4: Import to database
        if (!$this->option('dry-run')) {
            $book = $this->createBookFromMetadata($aiMetadata, $audiobook);
            if ($book) {
                $this->info("✅ Book imported successfully: {$book->title} (ID: {$book->id})");
                
                // Step 5: Move/copy files
                $this->moveFilesToLibrary($audiobook, $book);
                
                $this->processedBooks[] = [
                    'path' => $audiobook['path'],
                    'book_id' => $book->id,
                    'title' => $book->title
                ];
            }
        } else {
            $this->info("🔍 [DRY RUN] Would import: {$aiMetadata['title']}");
        }
    }

    /**
     * Process audiobook with AI
     */
    protected function processWithAI(array $audiobook): ?array
    {
        try {
            // Check for .nfo file first (priority over audio file tags)
            $nfoData = $this->extractNfoData($audiobook['path']);
            
            // Extract file tags from first few files
            $fileTags = [];
            $fileNames = [];
            
            foreach (array_slice($audiobook['files'], 0, 3) as $filePath) {
                $fileName = basename($filePath);
                $fileNames[] = $fileName;
                
                $tags = $this->aiProcessor->extractFileTags($filePath);
                if (!empty($tags)) {
                    $fileTags[$fileName] = $tags;
                }
            }

            // Process with AI, passing NFO data as priority information
            return $this->aiProcessor->processBookDirectory(
                $audiobook['path'],
                $fileNames,
                $fileTags,
                $nfoData
            );
        } catch (\Exception $e) {
            $this->error("❌ AI processing failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Display enriched metadata (AI + external data) for review
     */
    protected function displayEnrichedMetadata(array $metadata): void
    {
        // Helper function to convert arrays to strings
        $arrayToString = function($value) {
            if (is_array($value)) {
                // Filter out nested arrays and objects, then convert to string
                $filtered = array_filter($value, function($v) {
                    return !is_array($v) && !is_object($v) && $v !== null && $v !== '';
                });
                return implode(', ', $filtered);
            }
            return $value ?? 'N/A';
        };

        // Build the basic metadata table
        $tableData = [
            ['Title', $arrayToString($metadata['title'])],
            ['Author', $arrayToString($metadata['author'])],
            ['Narrator', $arrayToString($metadata['narrator'])],
            ['Series', ($metadata['series'] ?? '') . ($metadata['series_number'] ? " #{$metadata['series_number']}" : '')],
            ['Genre', $arrayToString($metadata['genre'])],
            ['Year', $metadata['year'] ?? 'N/A'],
            ['Publisher', $arrayToString($metadata['publisher'])],
            ['Language', $metadata['language'] ?? 'N/A'],
            ['ISBN', $metadata['isbn'] ?? 'N/A'],
            ['Confidence', $metadata['confidence'] . '%'],
        ];
        
        // Add description if available (truncated for display)
        if (!empty($metadata['description'])) {
            $description = strlen($metadata['description']) > 80 
                ? substr($metadata['description'], 0, 80) . '...'
                : $metadata['description'];
            $tableData[] = ['Description', $description];
        }
        
        // Add cover source if available
        if (!empty($metadata['cover_url'])) {
            $source = 'Unknown';
            if (isset($metadata['audible_raw'])) {
                $source = 'Audible';
            } elseif (isset($metadata['google_books_raw'])) {
                $source = 'Google Books';
            }
            $tableData[] = ['Cover Source', $source];
        }

        $this->table(['Field', 'Value'], $tableData);
        
        // Display cover image if terminal supports it and cover is available
        if (!empty($metadata['cover_url'])) {
            $this->displayCoverImage($metadata['cover_url']);
        }
    }
    
    /**
     * Display cover image if terminal supports it (like Ghostty)
     */
    protected function displayCoverImage(string $imageUrl): void
    {
        // Check if we're in a terminal that supports image display
        $term = getenv('TERM_PROGRAM') ?: getenv('TERM');
        
        // Ghostty and other modern terminals that support images
        $supportedTerminals = ['Ghostty', 'iTerm.app', 'WezTerm'];
        
        if (in_array($term, $supportedTerminals) || getenv('TERM_PROGRAM') === 'Ghostty') {
            try {
                // Download image temporarily for display
                $tempFile = tempnam(sys_get_temp_dir(), 'cover_') . '.jpg';
                $imageData = @file_get_contents($imageUrl);
                
                if ($imageData && file_put_contents($tempFile, $imageData)) {
                    // For Ghostty and compatible terminals, use the image display escape sequence
                    $this->line("\n📸 Cover Preview:");
                    
                    if ($term === 'Ghostty' || getenv('TERM_PROGRAM') === 'Ghostty') {
                        // Ghostty supports kitty graphics protocol
                        $base64Image = base64_encode($imageData);
                        $this->line("\033_Ga=T,f=100,s=200,v=150;{$base64Image}\033\\");
                    } elseif ($term === 'iTerm.app') {
                        // iTerm2 inline image protocol
                        $base64Image = base64_encode($imageData);
                        $this->line("\033]1337;File=inline=1;width=200px;height=150px:{$base64Image}\007");
                    }
                    
                    // Clean up temp file
                    @unlink($tempFile);
                } else {
                    $this->line("📸 Cover available: {$imageUrl}");
                }
            } catch (\Exception $e) {
                $this->line("📸 Cover available: {$imageUrl}");
            }
        } else {
            $this->line("📸 Cover available: {$imageUrl}");
        }
    }

    /**
     * Manual review and approval
     */
    protected function reviewAndApprove(array &$metadata, array $audiobook = []): bool
    {
        $this->warn("🔍 Manual Review Required");
        
        // If no enrichment data found, assume detected fields are wrong and skip auto-approval
        if (!$this->hasEnrichmentData($metadata)) {
            $this->warn("⚠️  No external enrichment data found - detected fields may be incorrect");
            $this->info("📝 Please review and edit the metadata:");
        } else {
            // Ask if user wants to accept all fields as shown
            $this->line("\nOptions:");
            $this->line("1. Accept all metadata as shown");
            $this->line("2. Edit individual fields");
            $this->line("3. Skip this book");
            
            $choice = $this->ask("Choose an option (1-3)", '2');
            
            switch ($choice) {
                case '1':
                    return true;
                case '2':
                    // Continue to field editing below
                    break;
                case '3':
                    return false;
                default:
                    // Default to editing
                    break;
            }
        }
        
        // Offer individual field editing
        $this->info("📝 Edit individual fields (press Enter to keep current value):");
        
        // Edit title
        $newTitle = $this->ask("Title", $metadata['title'] ?? '');
        if ($newTitle !== ($metadata['title'] ?? '')) {
            $metadata['title'] = $newTitle;
        }

        // Edit author
        $currentAuthor = is_array($metadata['author']) ? implode(', ', $metadata['author']) : ($metadata['author'] ?? '');
        $newAuthor = $this->ask("Author(s) (comma-separated)", $currentAuthor);
        if ($newAuthor !== $currentAuthor) {
            $metadata['author'] = array_map('trim', explode(',', $newAuthor));
        }

        // Edit narrator
        $currentNarrator = is_array($metadata['narrator']) ? implode(', ', $metadata['narrator']) : ($metadata['narrator'] ?? '');
        $newNarrator = $this->ask("Narrator(s) (comma-separated)", $currentNarrator);
        if ($newNarrator !== $currentNarrator) {
            $metadata['narrator'] = array_map('trim', explode(',', $newNarrator));
        }

        // Edit genre
        $currentGenre = is_array($metadata['genre']) ? implode(', ', $metadata['genre']) : ($metadata['genre'] ?? '');
        $newGenre = $this->ask("Genre", $currentGenre);
        if ($newGenre !== $currentGenre) {
            $metadata['genre'] = $newGenre;
        }

        // Edit series
        $currentSeries = $metadata['series'] ?? '';
        $newSeries = $this->ask("Series", $currentSeries);
        if ($newSeries !== $currentSeries) {
            $metadata['series'] = $newSeries;
        }

        // Edit series number
        $currentSeriesNumber = $metadata['series_number'] ?? '';
        $newSeriesNumber = $this->ask("Series Number", $currentSeriesNumber);
        if ($newSeriesNumber !== $currentSeriesNumber) {
            $metadata['series_number'] = $newSeriesNumber;
        }

        // Edit year
        $currentYear = $metadata['year'] ?? '';
        $newYear = $this->ask("Year", $currentYear);
        if ($newYear !== $currentYear) {
            $metadata['year'] = $newYear;
        }

        // If we started with no enrichment data, try to enrich with the edited metadata
        if (!$this->hasEnrichmentData($metadata) && !$this->option('skip-enrichment')) {
            if ($this->confirm("Try to find enrichment data with the edited metadata?", true)) {
                $this->info("🔍 Attempting to enrich with edited metadata...");
                $enrichedData = $this->enrichWithExternalData($metadata);
                if ($enrichedData) {
                    $metadata = array_merge($metadata, $enrichedData);
                    $this->info("✅ Found enrichment data with edited metadata!");
                    $this->newLine();
                    $this->displayEnrichedMetadata($metadata);
                    $this->newLine();
                } else {
                    $this->warn("⚠️  Still no enrichment data found");
                }
            }
        }

        return $this->confirm("Import this book with the edited metadata?", true);
    }

    /**
     * Enrich metadata with external data sources
     */
    protected function enrichWithExternalData(array $metadata): array
    {
        $enrichedData = [];
        $enrichmentResults = []; // Track which sources succeeded/failed

        // Try to get data from Audible first (most comprehensive for audiobooks)
        if (!empty($metadata['title']) && !empty($metadata['author'])) {
            $audibleData = $this->retryApiCall(function() use ($metadata) {
                return $this->searchAudible($metadata['title'], is_array($metadata['author']) ? $metadata['author'][0] : $metadata['author']);
            }, 'Audible');
            
            if ($audibleData) {
                $enrichedData = array_merge($enrichedData, $audibleData);
                $enrichmentResults['audible'] = 'success';
                $this->info("📚 Found Audible data");
            } else {
                $enrichmentResults['audible'] = 'no_data';
                $this->warn("⚠️  Audible: No data found");
            }
        }

        // Try Google Books if we still need description or cover
        if ((empty($enrichedData['description']) || empty($enrichedData['cover_url'])) && !empty($metadata['title'])) {
            $googleData = $this->retryApiCall(function() use ($metadata) {
                return $this->searchGoogleBooks($metadata['title'], is_array($metadata['author']) ? $metadata['author'][0] : $metadata['author']);
            }, 'Google Books');
            
            if ($googleData) {
                // Only merge data we don't already have (prioritize Audible)
                if (empty($enrichedData['description']) && !empty($googleData['description'])) {
                    $enrichedData['description'] = $googleData['description'];
                }
                if (empty($enrichedData['cover_url']) && !empty($googleData['cover_url'])) {
                    $enrichedData['cover_url'] = $googleData['cover_url'];
                }
                if (empty($enrichedData['publisher']) && !empty($googleData['publisher'])) {
                    $enrichedData['publisher'] = $googleData['publisher'];
                }
                if (empty($enrichedData['year']) && !empty($googleData['year'])) {
                    $enrichedData['year'] = $googleData['year'];
                }
                
                // Always merge raw data for reference
                if (!empty($googleData['google_books_raw'])) {
                    $enrichedData['google_books_raw'] = $googleData['google_books_raw'];
                }
                
                $enrichmentResults['google_books'] = 'success';
                $this->info("📖 Found Google Books data");
            } else {
                $enrichmentResults['google_books'] = 'no_data';
                $this->warn("⚠️  Google Books: No data found");
            }
        }

        // Continue searching for missing data from additional sources
        $missingData = $this->getMissingDataFields($enrichedData);
        if (!empty($missingData) && !empty($metadata['title'])) {
            $this->info("🔍 Still searching for: " . implode(', ', $missingData));
            
            // Add more sources here as needed:
            // - AudiobookBay
            // - OpenLibrary  
            // - LibriVox
            // - Internet Archive
        }

        // Store enrichment results for later use
        $enrichedData['_enrichment_results'] = $enrichmentResults;
        
        return $enrichedData;
    }

    /**
     * Get list of missing data fields that we should continue searching for
     */
    protected function getMissingDataFields(array $enrichedData): array
    {
        $missing = [];
        
        if (empty($enrichedData['cover_url'])) {
            $missing[] = 'cover image';
        }
        
        if (empty($enrichedData['description'])) {
            $missing[] = 'description';
        }
        
        // Only look for publisher if we don't have it from AI processing
        // (enrichedData only contains external API data, not AI-extracted data)
        
        return $missing;
    }

    /**
     * Search Audible for book data using AudibleService
     */
    protected function searchAudible(string $title, string $author): ?array
    {
        try {
            if (!$this->audibleService) {
                $this->audibleService = app(AudibleService::class);
            }

            $results = $this->audibleService->searchBooksWithFiltering($title, $author, ['limit' => 1]);
            
            if (!empty($results) && isset($results[0])) {
                $bookData = $results[0];
                
                $enrichedData = [];
                
                // Store raw Audible data
                $enrichedData['audible_raw'] = $bookData;
                
                if (!empty($bookData['description'])) {
                    $enrichedData['description'] = $this->cleanDescription($bookData['description']);
                }
                
                // AudibleService returns camelCase keys
                if (!empty($bookData['coverImageUrl'])) {
                    $enrichedData['cover_url'] = $bookData['coverImageUrl'];
                } elseif (!empty($bookData['image'])) {
                    $enrichedData['cover_url'] = $bookData['image'];
                }
                
                if (!empty($bookData['publishedYear'])) {
                    $enrichedData['year'] = $bookData['publishedYear'];
                } elseif (!empty($bookData['releaseDate'])) {
                    $enrichedData['year'] = substr($bookData['releaseDate'], 0, 4);
                }
                
                if (!empty($bookData['publisher'])) {
                    $enrichedData['publisher'] = $bookData['publisher'];
                }
                
                return $enrichedData;
            }
        } catch (\Exception $e) {
            Log::warning("Audible search failed: " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Search Google Books for book data using GoogleBooksApiService
     */
    protected function searchGoogleBooks(string $title, string $author): ?array
    {
        try {
            if (!$this->googleBooksService) {
                $this->googleBooksService = app(GoogleBooksApiService::class);
            }

            $query = "intitle:{$title} inauthor:{$author}";
            $results = $this->googleBooksService->searchBooks($query, ['limit' => 1]);
            
            if (!empty($results) && isset($results[0])) {
                $bookData = $results[0];
                
                $enrichedData = [];
                
                // Store raw Google Books data
                $enrichedData['google_books_raw'] = $bookData;
                
                if (!empty($bookData['description'])) {
                    $enrichedData['description'] = $this->cleanDescription($bookData['description']);
                }
                
                // GoogleBooksApiService returns camelCase keys
                if (!empty($bookData['coverImageUrl'])) {
                    $enrichedData['cover_url'] = $bookData['coverImageUrl'];
                }
                
                if (!empty($bookData['publishedYear'])) {
                    $enrichedData['year'] = $bookData['publishedYear'];
                }
                
                if (!empty($bookData['publisher'])) {
                    $enrichedData['publisher'] = $bookData['publisher'];
                }
                
                return $enrichedData;
            }
        } catch (\Exception $e) {
            Log::warning("Google Books search failed: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Download cover image to book directory using ExternalCoverService
     */
    protected function downloadCoverImage(string $imageUrl, string $directoryPath, string $source = 'unknown'): ?string
    {
        if (!$this->coverService) {
            $this->coverService = app(ExternalCoverService::class);
        }

        $result = $this->coverService->downloadCoverImage($imageUrl, $directoryPath, $source);
        
        if ($result['success']) {
            $this->info("📸 Downloaded cover image: {$result['path']}");
            return $result['path'];
        } else {
            $this->warn("⚠️  Error downloading cover image: " . $result['error']);
            return null;
        }
    }

    /**
     * Clean description text (remove HTML, limit length, etc.)
     */
    protected function cleanDescription(string $description): string
    {
        // Remove HTML tags
        $cleaned = strip_tags($description);
        
        // Decode HTML entities
        $cleaned = html_entity_decode($cleaned, ENT_QUOTES, 'UTF-8');
        
        // Trim whitespace
        $cleaned = trim($cleaned);
        
        // Limit length if extremely long
        if (strlen($cleaned) > 2000) {
            $cleaned = substr($cleaned, 0, 1997) . '...';
        }
        
        return $cleaned;
    }

    /**
     * Create book record in database
     */
    protected function createBookFromMetadata(array $metadata, array $audiobook): ?Book
    {
        try {
            return DB::transaction(function () use ($metadata, $audiobook) {
                // Create book
                $book = new Book();
                $book->title = $metadata['title'] ?? basename($audiobook['path']);
                $book->description = $metadata['description'] ?? null;
                $book->directory_path = $this->generateDirectoryPath($metadata);
                $book->language = $metadata['language'] ?? 'en';
                $book->isbn = $metadata['isbn'] ?? null;
                
                // Handle publisher (may be array from external services)
                if (!empty($metadata['publisher'])) {
                    if (is_array($metadata['publisher'])) {
                        $book->publisher = implode(', ', array_filter($metadata['publisher']));
                    } else {
                        $book->publisher = $metadata['publisher'];
                    }
                } else {
                    $book->publisher = null;
                }
                
                // Set data source based on AI model used
                $book->source = $this->getDataSource();
                
                // Calculate and store audio file information
                $audioInfo = $this->calculateAudioInfo($audiobook['files']);
                $book->audio_file_count = $audioInfo['count'];
                $book->duration = $audioInfo['duration'];
                $book->file_tags = json_encode($audioInfo['tags']);
                
                // Store enrichment data if available
                if (isset($metadata['google_books_raw'])) {
                    $book->google_books_info = json_encode($metadata['google_books_raw']);
                }
                if (isset($metadata['audible_raw'])) {
                    $book->audible_info = json_encode($metadata['audible_raw']);
                }
                if (isset($metadata['audiobook_bay_raw'])) {
                    $book->audiobook_bay_info = json_encode($metadata['audiobook_bay_raw']);
                }
                
                // Download and set cover image if found during enrichment
                if (isset($metadata['cover_url'])) {
                    $source = isset($metadata['audible_raw']) ? 'audible' : 'googlebooks';
                    $coverPath = $this->downloadCoverImage($metadata['cover_url'], $book->directory_path, $source);
                    if ($coverPath) {
                        $book->cover_image = $coverPath;
                    }
                }
                
                if (!empty($metadata['year'])) {
                    $book->release_date = $metadata['year'] . '-01-01';
                }
                
                $book->save();

                // Handle authors
                if (!empty($metadata['author'])) {
                    $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
                    $authorIds = [];
                    foreach ($authors as $authorName) {
                        $author = Author::firstOrCreate(['name' => trim($authorName)]);
                        $authorIds[] = $author->id;
                    }
                    $book->authors()->sync($authorIds);
                }

                // Handle narrators
                if (!empty($metadata['narrator'])) {
                    $narrators = is_array($metadata['narrator']) ? $metadata['narrator'] : [$metadata['narrator']];
                    $narratorIds = [];
                    foreach ($narrators as $narratorName) {
                        $narrator = Narrator::firstOrCreate(['name' => trim($narratorName)]);
                        $narratorIds[] = $narrator->id;
                    }
                    $book->narrators()->sync($narratorIds);
                }

                // Handle genres with author consistency check
                if (!empty($metadata['genre'])) {
                    $genres = is_array($metadata['genre']) ? $metadata['genre'] : [$metadata['genre']];
                    
                    // Check if author has existing books and prefer their established genre
                    $authorGenre = $this->getAuthorPreferredGenre($metadata['author']);
                    if ($authorGenre) {
                        $this->info("📚 Author genre preference found: Using '{$authorGenre}' based on existing books");
                        $genres = [$authorGenre]; // Override AI genre with author's established genre
                    }
                    
                    $genreIds = [];
                    foreach ($genres as $genreName) {
                        $mappedGenre = $this->mapToValidGenre(trim($genreName));
                        $genre = Genre::firstOrCreate(['name' => $mappedGenre]);
                        $genreIds[] = $genre->id;
                    }
                    $book->genres()->sync($genreIds);
                }

                // Handle series
                if (!empty($metadata['series'])) {
                    $series = Series::firstOrCreate(['name' => trim($metadata['series'])]);
                    $seriesNumber = $metadata['series_number'] ?? 1;
                    
                    $book->series()->sync([
                        $series->id => ['series_number' => $seriesNumber]
                    ]);
                }

                return $book;
            });
        } catch (\Exception $e) {
            $this->error("❌ Failed to create book: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate directory path for book storage
     */
    protected function generateDirectoryPath(array $metadata): string
    {
        $parts = [];
        
        // Check for author's preferred genre first
        $authorGenre = $this->getAuthorPreferredGenre($metadata['author']);
        if ($authorGenre) {
            $parts[] = $authorGenre;
        } elseif (!empty($metadata['genre'])) {
            $genre = is_array($metadata['genre']) ? $metadata['genre'][0] : $metadata['genre'];
            $parts[] = $genre;
        } else {
            $parts[] = 'Other';
        }
        
        if (!empty($metadata['author'])) {
            $author = is_array($metadata['author']) ? $metadata['author'][0] : $metadata['author'];
            $parts[] = $author;
        }
        
        if (!empty($metadata['series'])) {
            $parts[] = $metadata['series'];
        }
        
        if (!empty($metadata['title'])) {
            $title = $metadata['title'];
            // If we have a series number, prefix it to the title
            if (!empty($metadata['series_number'])) {
                $seriesNumber = str_pad($metadata['series_number'], 2, '0', STR_PAD_LEFT);
                $title = $seriesNumber . ' ' . $title;
            }
            $parts[] = $title;
        }
        
        // Clean parts for filesystem
        $parts = array_map(function($part) {
            return preg_replace('/[^\w\s\-\.]/', '', $part);
        }, $parts);
        
        return implode('/', $parts);
    }

    /**
     * Move files to library after successful import
     */
    protected function moveFilesToLibrary(array $audiobook, Book $book): bool
    {
        try {
            $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
            if (!$bookStoragePath) {
                $this->warn("⚠️  Book storage path not configured - files not moved");
                return false;
            }

            $targetDir = $bookStoragePath . '/' . $book->directory_path;
            
            // Check if target directory already exists before creating it
            if (File::isDirectory($targetDir)) {
                $conflictAction = $this->handleDirectoryConflict($audiobook, $targetDir);
                
                switch ($conflictAction) {
                    case 'skip':
                        $this->info("⏭️  Skipping file operations - directories are identical");
                        $this->cleanupSourceDirectory($audiobook, true); // Clean up since files already exist
                        return true;
                        
                    case 'replace':
                        $this->info("🗑️  Removing existing directory to replace with new files");
                        File::deleteDirectory($targetDir);
                        break;
                        
                    case 'rename_existing':
                        $newExistingPath = $targetDir . '_backup_' . date('Y-m-d_H-i-s');
                        File::move($targetDir, $newExistingPath);
                        $this->info("📁 Renamed existing directory to: " . basename($newExistingPath));
                        break;
                        
                    case 'rename_new':
                        $targetDir = $targetDir . '_imported_' . date('Y-m-d_H-i-s');
                        $this->info("📁 Importing to renamed directory: " . basename($targetDir));
                        break;
                        
                    case 'cancel':
                        $this->warn("❌ Import cancelled by user");
                        return false;
                }
            }
            
            // Create target directory (after handling conflicts)
            File::makeDirectory($targetDir, 0755, true);

            // Move or copy all files in the directory (not just audio files)
            $copyFiles = $this->option('copy-files');
            $filesMoved = 0;
            $filesCopied = 0;
            
            // Get all files in the source directory
            $allFiles = File::allFiles($audiobook['path']);
            
            foreach ($allFiles as $sourceFile) {
                $relativePath = str_replace($audiobook['path'] . '/', '', $sourceFile->getPathname());
                $targetFile = $targetDir . '/' . $relativePath;
                
                // Create subdirectories if needed
                $targetSubDir = dirname($targetFile);
                if (!File::isDirectory($targetSubDir)) {
                    File::makeDirectory($targetSubDir, 0755, true);
                }
                
                if ($copyFiles) {
                    File::copy($sourceFile->getPathname(), $targetFile);
                    $filesCopied++;
                } else {
                    // Try to move first, fallback to copy if move fails
                    try {
                        File::move($sourceFile->getPathname(), $targetFile);
                        $filesMoved++;
                    } catch (\Exception $e) {
                        $this->warn("⚠️  Failed to move {$relativePath}, copying instead: " . $e->getMessage());
                        File::copy($sourceFile->getPathname(), $targetFile);
                        $filesCopied++;
                    }
                }
            }
            
            // Log the actual operation performed
            if ($filesMoved > 0 && $filesCopied > 0) {
                $this->info("📁 {$filesMoved} files moved, {$filesCopied} files copied to library");
            } elseif ($filesMoved > 0) {
                $this->info("📁 {$filesMoved} files moved to library");
            } elseif ($filesCopied > 0) {
                $this->info("📁 {$filesCopied} files copied to library");
            }

            // Clean up source directory if files were moved successfully
            if ($filesMoved > 0 && $filesCopied == 0) {
                $this->cleanupSourceDirectory($audiobook);
            }

            return true;
        } catch (\Exception $e) {
            $this->error("❌ Failed to move files: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Show cost estimate for AI processing
     */
    protected function showCostEstimate(int $bookCount): void
    {
        $costEstimate = $this->aiProcessor->estimateBatchCost($bookCount);
        
        if ($costEstimate['total_cost'] > 0) {
            $this->warn("💰 Estimated AI processing cost: \${$costEstimate['total_cost']} (\${$costEstimate['cost_per_book']} per book)");
            
            if ($costEstimate['total_cost'] > 1.0) {
                $this->error("⚠️  High cost operation (>\$1.00) - use --force to proceed");
                if (!$this->option('force')) {
                    exit(1);
                }
            }
        } else {
            $this->info("💰 Using free tier AI model - no cost");
        }
    }

    /**
     * Display processing summary
     */
    protected function displaySummary(): void
    {
        $this->info('📊 Import Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Found', $this->totalFound],
                ['Successfully Imported', count($this->processedBooks)],
                ['Failed', count($this->failedBooks)],
                ['Skipped', count($this->skippedBooks)],
            ]
        );

        if (!empty($this->processedBooks)) {
            $this->info('✅ Successfully Imported:');
            foreach ($this->processedBooks as $book) {
                $this->line("  📚 {$book['title']} (ID: {$book['book_id']})");
            }
        }

        if (!empty($this->failedBooks)) {
            $this->warn('❌ Failed Imports:');
            foreach ($this->failedBooks as $failed) {
                $this->line("  🚫 {$failed['path']}: {$failed['error']}");
            }
        }

        if (!empty($this->skippedBooks)) {
            $this->info('⏭️  Skipped:');
            foreach ($this->skippedBooks as $skipped) {
                $this->line("  ⚠️  {$skipped['path']}: {$skipped['reason']}");
            }
        }

        // Show actual AI costs
        $totalCost = $this->aiProcessor->getTotalCost();
        if ($totalCost > 0) {
            $this->info("💰 Total AI cost: \${$totalCost}");
        }
    }


    /**
     * Extract metadata from .nfo files if present
     */
    protected function extractNfoData(string $directoryPath): ?array
    {
        $nfoFiles = glob($directoryPath . '/*.nfo');
        if (empty($nfoFiles)) {
            return null;
        }
        
        $nfoFile = $nfoFiles[0]; // Use first .nfo file found
        $nfoContent = file_get_contents($nfoFile);
        
        if (!$nfoContent) {
            return null;
        }
        
        $nfoData = [];
        
        // Parse XML-style NFO files (common format)
        if (strpos($nfoContent, '<') !== false) {
            $nfoData = $this->parseXmlNfo($nfoContent);
        } else {
            // Parse plain text NFO files
            $nfoData = $this->parsePlainTextNfo($nfoContent);
        }
        
        if (!empty($nfoData)) {
            $this->info("📄 Found .nfo file with metadata: " . basename($nfoFile));
        }
        
        return $nfoData;
    }
    
    /**
     * Parse XML-format NFO files
     */
    protected function parseXmlNfo(string $content): array
    {
        $data = [];
        
        try {
            $xml = simplexml_load_string($content);
            
            if ($xml) {
                if (isset($xml->title)) $data['title'] = (string)$xml->title;
                if (isset($xml->author)) $data['author'] = (string)$xml->author;
                if (isset($xml->narrator)) $data['narrator'] = (string)$xml->narrator;
                if (isset($xml->series)) $data['series'] = (string)$xml->series;
                if (isset($xml->seriesNumber)) $data['series_number'] = (string)$xml->seriesNumber;
                if (isset($xml->genre)) $data['genre'] = (string)$xml->genre;
                if (isset($xml->year)) $data['year'] = (string)$xml->year;
                if (isset($xml->publisher)) $data['publisher'] = (string)$xml->publisher;
                if (isset($xml->isbn)) $data['isbn'] = (string)$xml->isbn;
                if (isset($xml->plot)) $data['description'] = (string)$xml->plot;
                if (isset($xml->description)) $data['description'] = (string)$xml->description;
            }
        } catch (\Exception $e) {
            Log::warning("Failed to parse XML NFO: " . $e->getMessage());
        }
        
        return $data;
    }
    
    /**
     * Parse plain text NFO files
     */
    protected function parsePlainTextNfo(string $content): array
    {
        $data = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Look for common patterns
            if (preg_match('/^title\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['title'] = trim($matches[1]);
            } elseif (preg_match('/^author\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['author'] = trim($matches[1]);
            } elseif (preg_match('/^(?:narrator|read\s+by)\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['narrator'] = trim($matches[1]);
            } elseif (preg_match('/^series\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['series'] = trim($matches[1]);
            } elseif (preg_match('/^(?:series.?number|book.?number)\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['series_number'] = trim($matches[1]);
            } elseif (preg_match('/^genre\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['genre'] = trim($matches[1]);
            } elseif (preg_match('/^(?:year|original\s+publication)\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['year'] = trim($matches[1]);
            } elseif (preg_match('/^publisher\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['publisher'] = trim($matches[1]);
            } elseif (preg_match('/^isbn\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['isbn'] = trim($matches[1]);
            } elseif (preg_match('/^(?:description|plot|summary)\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['description'] = trim($matches[1]);
            }
        }
        
        return $data;
    }

    /**
     * Handle directory conflicts when target already exists
     */
    protected function handleDirectoryConflict(array $audiobook, string $targetDir): string
    {
        $this->warn("⚠️  Target directory already exists: " . basename($targetDir));
        
        // Compare directories
        $comparison = $this->compareDirectories($audiobook['path'], $targetDir);
        
        // Display comparison
        $this->displayDirectoryComparison($comparison);
        
        // If directories are identical, just clean up source
        if ($comparison['identical']) {
            $this->info("✅ Directories are identical - no need to move files");
            return 'skip';
        }
        
        // If in auto mode, default to replace
        if ($this->option('auto')) {
            $this->info("🤖 Auto mode: Replacing existing directory");
            return 'replace';
        }
        
        // Prompt user for action
        $this->line("\nOptions:");
        $this->line("1. Replace existing directory with new files");
        $this->line("2. Rename existing directory (backup)");  
        $this->line("3. Rename new import");
        $this->line("4. Cancel import");
        
        $choice = $this->ask("Choose an option (1-4)", '1');
        
        switch ($choice) {
            case '1':
                return 'replace';
            case '2':
                return 'rename_existing';
            case '3':
                return 'rename_new';
            case '4':
                return 'cancel';
            default:
                return 'replace';
        }
    }
    
    /**
     * Compare two directories for content differences
     */
    protected function compareDirectories(string $sourcePath, string $targetPath): array
    {
        $sourceFiles = $this->getDirectoryInfo($sourcePath);
        $targetFiles = $this->getDirectoryInfo($targetPath);
        
        // Check if directories are identical
        $identical = $this->areDirectoriesIdentical($sourceFiles, $targetFiles);
        
        return [
            'identical' => $identical,
            'source' => $sourceFiles,
            'target' => $targetFiles
        ];
    }
    
    /**
     * Get detailed information about files in a directory
     */
    protected function getDirectoryInfo(string $path): array
    {
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $files = [];
        $totalSize = 0;
        $fileTypes = [];
        
        if (!File::isDirectory($path)) {
            return [
                'files' => [],
                'total_size' => 0,
                'file_types' => [],
                'count' => 0
            ];
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                if (in_array($extension, $audioExtensions)) {
                    $size = $file->getSize();
                    $files[] = [
                        'name' => $file->getFilename(),
                        'size' => $size,
                        'extension' => $extension,
                        'hash' => md5($file->getFilename() . $size) // Simple hash for comparison
                    ];
                    $totalSize += $size;
                    $fileTypes[$extension] = ($fileTypes[$extension] ?? 0) + 1;
                }
            }
        }
        
        return [
            'files' => $files,
            'total_size' => $totalSize,
            'file_types' => $fileTypes,
            'count' => count($files)
        ];
    }
    
    /**
     * Check if two directories have identical content
     */
    protected function areDirectoriesIdentical(array $sourceFiles, array $targetFiles): bool
    {
        if ($sourceFiles['count'] !== $targetFiles['count']) {
            return false;
        }
        
        if ($sourceFiles['total_size'] !== $targetFiles['total_size']) {
            return false;
        }
        
        // Compare file hashes
        $sourceHashes = array_column($sourceFiles['files'], 'hash');
        $targetHashes = array_column($targetFiles['files'], 'hash');
        
        sort($sourceHashes);
        sort($targetHashes);
        
        return $sourceHashes === $targetHashes;
    }
    
    /**
     * Display directory comparison information
     */
    protected function displayDirectoryComparison(array $comparison): void
    {
        $this->table(
            ['Location', 'Files', 'Total Size', 'File Types'],
            [
                [
                    'Source (New)', 
                    $comparison['source']['count'],
                    $this->formatBytes($comparison['source']['total_size']),
                    $this->formatFileTypes($comparison['source']['file_types'])
                ],
                [
                    'Target (Existing)',
                    $comparison['target']['count'], 
                    $this->formatBytes($comparison['target']['total_size']),
                    $this->formatFileTypes($comparison['target']['file_types'])
                ]
            ]
        );
    }
    
    /**
     * Format file types for display
     */
    protected function formatFileTypes(array $fileTypes): string
    {
        if (empty($fileTypes)) {
            return 'None';
        }
        
        $formatted = [];
        foreach ($fileTypes as $type => $count) {
            $formatted[] = "{$count} {$type}";
        }
        
        return implode(', ', $formatted);
    }
    
    /**
     * Get data source based on AI model used
     */
    protected function getDataSource(): string
    {
        $model = $this->option('model');
        
        if (str_contains($model, 'gemini')) {
            return 'gemini';
        } elseif (str_contains($model, 'gpt') || str_contains($model, 'openai')) {
            return 'chatgpt';
        } elseif (str_contains($model, 'claude')) {
            return 'claude';
        }
        
        return 'ai'; // Generic fallback
    }
    
    /**
     * Calculate audio file information including duration and tags
     */
    protected function calculateAudioInfo(array $audioFiles): array
    {
        $totalDuration = 0;
        $allTags = [];
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $audioFileCount = 0;
        
        foreach ($audioFiles as $filePath) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            
            if (in_array($extension, $audioExtensions)) {
                $audioFileCount++;
                
                try {
                    // Extract file tags
                    $tags = $this->aiProcessor->extractFileTags($filePath);
                    if (!empty($tags)) {
                        $fileName = basename($filePath);
                        $allTags[$fileName] = $tags;
                        
                        // Add to total duration if available
                        if (isset($tags['duration_seconds'])) {
                            $totalDuration += (int)$tags['duration_seconds'];
                        } elseif (isset($tags['duration'])) {
                            // Parse duration from string format (e.g., "1:23:45")
                            $totalDuration += $this->parseDurationString($tags['duration']);
                        } elseif (isset($tags['DURATION'])) {
                            // Some formats use uppercase
                            $totalDuration += $this->parseDurationString($tags['DURATION']);
                        } elseif (isset($tags['LENGTH'])) {
                            // Alternative field name
                            $totalDuration += $this->parseDurationString($tags['LENGTH']);
                        }
                        
                        // Try to get duration from file directly if not in tags
                        if ($totalDuration == 0) {
                            $fileDuration = $this->getAudioFileDuration($filePath);
                            if ($fileDuration > 0) {
                                $totalDuration += $fileDuration;
                                // Store calculated duration in tags for reference
                                $allTags[$fileName]['calculated_duration'] = $fileDuration;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to extract tags from {$filePath}: " . $e->getMessage());
                }
            }
        }
        
        return [
            'count' => $audioFileCount,
            'duration' => $totalDuration, // in seconds
            'tags' => $allTags
        ];
    }
    
    /**
     * Parse duration string (e.g., "1:23:45") to seconds
     */
    protected function parseDurationString(string $duration): int
    {
        $parts = explode(':', $duration);
        $seconds = 0;
        
        if (count($parts) === 3) {
            // H:M:S format
            $seconds = ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        } elseif (count($parts) === 2) {
            // M:S format
            $seconds = ($parts[0] * 60) + $parts[1];
        } else {
            // Just seconds
            $seconds = (int)$duration;
        }
        
        return (int)$seconds;
    }

    /**
     * Get audio file duration directly from file metadata
     */
    protected function getAudioFileDuration(string $filePath): int
    {
        if (!class_exists('getID3')) {
            return 0;
        }

        try {
            $getID3 = new \getID3();
            $fileInfo = $getID3->analyze($filePath);
            
            // Get duration from playtime_seconds if available
            if (isset($fileInfo['playtime_seconds'])) {
                return (int)round($fileInfo['playtime_seconds']);
            }
            
            // Alternative: calculate from bitrate and filesize
            if (isset($fileInfo['filesize']) && isset($fileInfo['bitrate']) && $fileInfo['bitrate'] > 0) {
                $durationSeconds = ($fileInfo['filesize'] * 8) / $fileInfo['bitrate'];
                return (int)round($durationSeconds);
            }
            
        } catch (\Exception $e) {
            Log::warning("Failed to get audio file duration from {$filePath}: " . $e->getMessage());
        }
        
        return 0;
    }

    /**
     * Clean up source directory after successful operations
     */
    protected function cleanupSourceDirectory(array $audiobook, bool $filesAlreadyExist = false): void
    {
        if (!$this->option('copy-files') && File::isDirectory($audiobook['path'])) {
            if ($filesAlreadyExist) {
                // Files already exist in target, safe to remove source
                try {
                    File::deleteDirectory($audiobook['path']);
                    $this->info("🗑️  Removed source directory (files already exist in target)");
                } catch (\Exception $e) {
                    $this->warn("⚠️  Could not remove source directory: " . $e->getMessage());
                }
            } else {
                // Check if directory is empty after move
                $remainingFiles = File::files($audiobook['path']);
                if (empty($remainingFiles)) {
                    try {
                        File::deleteDirectory($audiobook['path']);
                        $this->info("🗑️  Removed empty source directory");
                    } catch (\Exception $e) {
                        $this->warn("⚠️  Could not remove source directory: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Get author's preferred genre based on their existing books
     */
    protected function getAuthorPreferredGenre($authorData): ?string
    {
        if (empty($authorData)) {
            return null;
        }
        
        // Handle both string and array author data
        $authorNames = is_array($authorData) ? $authorData : [$authorData];
        
        foreach ($authorNames as $authorName) {
            $authorName = trim($authorName);
            if (empty($authorName)) {
                continue;
            }
            
            // Find the author in the database
            $author = Author::where('name', $authorName)->first();
            if (!$author) {
                continue;
            }
            
            // Get genre distribution for this author's books
            $genreStats = DB::table('books')
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                ->join('genres', 'book_genre.genre_id', '=', 'genres.id')
                ->where('author_book.author_id', $author->id)
                ->select('genres.name', DB::raw('COUNT(*) as count'))
                ->groupBy('genres.name')
                ->orderByDesc('count')
                ->first();
            
            if ($genreStats && $genreStats->count >= 2) {
                // If author has 2+ books in the same genre, use that genre
                return $genreStats->name;
            }
        }
        
        return null;
    }

    /**
     * Check if metadata contains enrichment data from external sources
     */
    protected function hasEnrichmentData(array $metadata): bool
    {
        // Check for data that typically comes from external sources
        $enrichmentFields = [
            'audible_raw',
            'google_books_raw',
            'audiobook_bay_raw',
            'cover_url'
        ];
        
        foreach ($enrichmentFields as $field) {
            if (!empty($metadata[$field])) {
                return true;
            }
        }
        
        // Also check if we have a detailed description (usually from external sources)
        if (!empty($metadata['description']) && strlen($metadata['description']) > 100) {
            return true;
        }
        
        return false;
    }

    /**
     * Retry API calls with exponential backoff
     */
    protected function retryApiCall(callable $apiCall, string $serviceName, int $maxRetries = 3): mixed
    {
        $attempt = 1;
        
        while ($attempt <= $maxRetries) {
            try {
                $result = $apiCall();
                
                // If we get a result, return it (could be null for "no data found")
                return $result;
                
            } catch (\Exception $e) {
                if ($attempt === $maxRetries) {
                    // Last attempt failed, log and return null
                    $this->error("❌ {$serviceName}: All {$maxRetries} attempts failed - " . $e->getMessage());
                    Log::error("{$serviceName} enrichment failed after {$maxRetries} attempts", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    return null;
                }
                
                // Calculate backoff delay (exponential: 1s, 2s, 4s...)
                $delay = pow(2, $attempt - 1);
                $this->warn("⚠️  {$serviceName}: Attempt {$attempt} failed, retrying in {$delay}s... ({$e->getMessage()})");
                sleep($delay);
                
                $attempt++;
            }
        }
        
        return null;
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}