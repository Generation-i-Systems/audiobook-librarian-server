<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Author;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Services\AIBookProcessor;
use App\Services\BookDirectoryParser;
use App\Services\AudioFileAnalyzer;
use App\Services\AudibleService;
use App\Services\BackgroundProcessingService;
use App\Services\BookEnrichmentService;
use App\Services\BookImportService;
use App\Services\FileSystemService;
use App\Services\MetadataProcessingService;
use App\Services\ExternalCoverService;
use App\Services\GoogleBooksApiService;
use App\Services\ImportCacheService;
use App\Services\OpenAudibleParser;
use App\Services\TerminalImageService;
use App\Traits\GenreMapping;
use getID3;
use getid3_lib;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\App;

class ImportBooksFromDownloads extends Command
{
    use GenreMapping;

    /**
     * @var getID3
     */
    protected $getID3;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'books:import-downloads
                            {path?* : Specific files or folders to process (if none provided, scans default directories)}
                            {--directory=* : Custom directories to scan (ignored if paths are provided)}
                            {--model=gemini-2.5-flash-lite : AI model to use for processing}
                            {--min-confidence=80 : Minimum AI confidence for auto-import}
                            {--auto : Fully automated mode - no manual review}
                            {--dry-run : Show what would be imported without making changes}
                            {--limit=0 : Maximum number of books to process per run (0 = no limit)}
                            {--force : Skip confirmation prompts}
                            {--skip-enrichment : Skip external data enrichment (Audible, Google Books)}
                            {--skip-ai : Skip AI processing - use only file metadata and OpenAudible data}
                            {--copy-files : Copy files after successful import instead of moving (default is move)}
                            {--no-backup : Skip automatic database backup}
                            {--background : Enable background processing for enrichment (disabled by default)}
                            {--batch-id= : Batch ID to tag all imports in this run for group operations}
                            {--no-cache : Disable background processing cache}
                            {--clear-cache : Clear background processing cache before starting}
                            {--force-audio : Force audio transcription even when AI confidence is high}
                            {--skip-pattern=* : Skip directories matching these patterns (supports wildcards)}
                            {--no-multi-book : Disable multi-book directory detection for this run}';

    /**
     * The console command description.
     */
    protected $description = 'Import audiobooks from download directories using AI processing and external data enrichment (creates a database backup by default)';

    protected ?AIBookProcessor $aiProcessor = null;
    protected ?AudioFileAnalyzer $audioAnalyzer = null;
    protected string $batchId;
    protected ?AudibleService $audibleService = null;
    protected ?ExternalCoverService $coverService = null;
    protected ?GoogleBooksApiService $googleBooksService = null;
    protected ?TerminalImageService $terminalImageService = null;

    // New services
    protected ?BookDirectoryParser $directoryParser = null;
    protected ?BookEnrichmentService $enrichmentService = null;
    protected ?BookImportService $importService = null;
    protected ?BackgroundProcessingService $backgroundService = null;
    protected ?ImportCacheService $cacheService = null;
    protected ?MetadataProcessingService $metadataService = null;
    protected ?FileSystemService $fileSystemService = null;
    protected ?OpenAudibleParser $openAudibleParser = null;

    // Cache for file tags to avoid re-extracting
    protected array $fileTagsCache = [];

    // Track metadata from previously processed books for series metadata propagation
    protected array $previousBookMetadata = [];

    // OpenAudible books.json data cache
    protected ?array $openAudibleBooksData = null;
    protected ?string $openAudibleRootPath = null;

    // Background processing
    protected array $backgroundTasks = [];
    protected array $preloadedData = [];
    protected bool $backgroundProcessingEnabled = false; // Disabled by default
    protected array $taskQueue = [];
    protected int $maxConcurrentTasks = 3;
    protected int $runningTaskCount = 0;
    protected bool $inputInterrupted = false;

    public function __construct()
    {
        parent::__construct();

        $this->getID3 = new getID3();
        // Disable writing tags to files
        $this->getID3->option_tag_id3v1 = false;
        $this->getID3->option_tag_id3v2 = false;
        $this->getID3->option_tag_lyrics3 = false;
        $this->getID3->option_tags_process = false;
    }

    // Persistent cache
    protected string $cacheDirectory;
    protected string $cacheFilePath;
    protected array $backgroundCache = [];
    protected int $cacheVersion = 2; // Increment when cache structure changes
    protected bool $cacheEnabled = true;

    // User interruption handling
    protected bool $userRequestedQuit = false;
    protected array $processedBooks = [];
    protected array $failedBooks = [];
    protected array $skippedBooks = [];
    protected int $totalFound = 0;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Set up signal handlers for graceful interruption
        $this->setupSignalHandlers();

        // Initialize persistent cache system
        $this->getCacheService()->initializeCache();

        // Auto-generate batch-id if not provided
        $batchId = $this->option('batch-id');
        if (empty($batchId)) {
            $batchId = 'import_' . date('Ymd_His');
            $this->info("📦 Auto-generated batch ID: {$batchId}");
        } else {
            $this->info("📦 Using batch ID: {$batchId}");
        }
        // Store batch-id for use during import
        $this->batchId = $batchId;

        // Check if background processing should be enabled
        if ($this->option('background')) {
            $this->backgroundProcessingEnabled = true;
            $this->info('✅ Background processing enabled');
        }

        // Create a database backup unless --no-backup is specified or in dry-run mode
        if (!$this->option('no-backup') && !$this->option('dry-run')) {
            $backupStartTime = microtime(true);
            $this->info('Creating a database backup before importing books...');
            $this->call('backup:database', ['--suffix' => 'import-books']);
            $backupDuration = round((microtime(true) - $backupStartTime) * 1000);
            $this->info('Database backup created.');
            if ($this->isOptionEnabled('verbose')) {
                $this->line("⏱️  Database backup took: {$backupDuration}ms");
            }
        } elseif ($this->option('dry-run')) {
            $this->line('⏩ Skipping database backup (dry-run mode)');
        }

        $this->info("🚀 Starting automated audiobook import from download directories...");

        // Check for readline extension and warn if not available (unless in auto mode)
        if (!$this->option('auto') && !extension_loaded('readline')) {
            $this->error("❌ PHP readline extension is not enabled. Advanced line editing features (arrow keys, etc.) will not be available. Consider enabling it for a better interactive experience.");
        }

        // Initialize AI processor
        $model = $this->option('model');
        try {
            $this->aiProcessor = new AIBookProcessor($model, true);
            $this->info("✅ AI processor initialized with model: {$model}");
        } catch (\Exception $e) {
            $this->error("❌ Failed to initialize AI processor: " . $e->getMessage());
            return Command::FAILURE;
        }

        // Check for specific paths first (files or folders)
        $specificPaths = $this->argument('path');
        if (!empty($specificPaths)) {
            $pathsToProcess = is_array($specificPaths) ? $specificPaths : [$specificPaths];
            $this->info("📁 Processing specific paths: " . implode(', ', $pathsToProcess));
            $audiobooks = $this->processSpecificPaths($pathsToProcess);
        } else {
            // Get directories to scan
            $directories = $this->getDirectoriesToScan();
            if (empty($directories)) {
                $this->error("❌ No valid directories found to scan");
                return Command::FAILURE;
            }

            $this->info("📁 Scanning directories: " . implode(', ', $directories));

            // Try to load OpenAudible books.json if present in any directory
            foreach ($directories as $directory) {
                $this->loadOpenAudibleData($directory);
                if ($this->openAudibleBooksData !== null) {
                    break;
                }
            }

            // Scan for audiobooks using existing parser
            $audiobooks = $this->scanForAudiobooks($directories);
        }
        $this->totalFound = count($audiobooks);

        if (empty($audiobooks)) {
            if (!empty($specificPaths)) {
                $this->info("ℹ️  No audiobooks found in specified paths");
                $this->info("💡 Tip: Use quotes around paths with spaces: \"path with spaces\"");
                $this->info("💡 Or use full paths: /media/download/audiobooks/\"Michael Simon - First Command\"");
            } else {
                $this->info("ℹ️  No audiobooks found in specified directories");
            }
            return Command::SUCCESS;
        }

        $totalFound = count($audiobooks);
        $this->info("📚 Found {$totalFound} potential audiobooks to import");

        // Apply limit
        $limit = $this->option('limit');
        if ($limit && $limit > 0 && $totalFound > $limit) {
            $audiobooks = array_slice($audiobooks, 0, $limit);
            $this->warn("⚠️  Processing limited to {$limit} of {$totalFound} books (use --limit=0 for no limit)");
        } else {
            $this->info("📋 Will process all {$totalFound} books");
        }

        // Show cost estimate for AI processing
        $costEstimate = $this->aiProcessor->estimateBatchCost(count($audiobooks));

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


        // Process each audiobook
        $progressBar = $this->output->createProgressBar(count($audiobooks));
        $progressBar->start();

        foreach ($audiobooks as $index => $audiobook) {
            try {
                // Check if directory should be skipped based on patterns
                if ($this->shouldSkipDirectory($audiobook['path'])) {
                    $this->skippedBooks[] = [
                        'path' => $audiobook['path'],
                        'reason' => 'Matched skip pattern',
                    ];
                    $progressBar->advance();
                    continue;
                }

                // Start background processing for upcoming books (only if enabled)
                if ($this->backgroundProcessingEnabled && isset($audiobooks[$index + 1])) {
                    $this->getBackgroundService()->scheduleBackgroundTask('process_audiobook', $audiobooks[$index + 1]);
                }

                if ($this->isOptionEnabled('verbose')) {
                    $this->info("Debug: Calling processAudiobook for: " . $audiobook['name']);
                }
                $this->processAudiobook($audiobook);
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();

                // Handle user interruption specially
                if (str_contains($errorMessage, '[Request interrupted by user]')) {
                    $this->skippedBooks[] = [
                        'path' => $audiobook['path'],
                        'reason' => 'User interruption - skipped current book',
                    ];
                    $this->info("⏭️  Skipped due to user interruption: " . basename($audiobook['path']));
                } else {
                    // Regular error handling with detailed stack trace for debugging
                    $stackTrace = $e->getTraceAsString();
                    $fullError = $errorMessage . "\n\nStack trace:\n" . $stackTrace;

                    $this->failedBooks[] = [
                        'path' => $audiobook['path'],
                        'error' => $errorMessage,
                    ];
                    Log::error("Import failed for {$audiobook['path']}: " . $fullError);

                    // Also output to console for debugging
                    if ($this->output->isVerbose()) {
                        $this->error("Full error trace: " . $fullError);
                    }
                }
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Show summary
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

        // Save cache before exit and show cache statistics
        $cacheService = $this->getCacheService();
        if ($this->cacheEnabled && $cacheService) {
            $cacheService->saveCache();
            $cacheStats = $cacheService->displayCacheStatistics();
            if (is_array($cacheStats) && !empty($cacheStats)) {
                // Convert associative array to table rows
                $tableRows = [];
                foreach ($cacheStats as $key => $value) {
                    $tableRows[] = [ucwords(str_replace('_', ' ', $key)), $value];
                }
                $this->table(['Metric', 'Value'], $tableRows);
            }
        }

        return Command::SUCCESS;
    }


    protected function processSpecificPaths(array $paths): array
    {
        $audiobooks = [];
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $processedDirectories = []; // Track directories we've already processed

        foreach ($paths as $path) {
            // Handle escaped spaces and normalize path
            $normalizedPath = str_replace('\ ', ' ', $path);

            // Try multiple path variations
            $pathsToTry = [
                $path,
                $normalizedPath,
            ];

            // If not absolute path, try current directory first, then common audiobook directories
            if (!str_starts_with($path, '/')) {
                // Try current working directory first
                $currentDir = getcwd();
                $pathsToTry[] = $currentDir . '/' . $path;
                $pathsToTry[] = $currentDir . '/' . $normalizedPath;

                // Then try common audiobook directories
                $commonDirs = ['/media/download/audiobooks', '/media/download'];
                foreach ($commonDirs as $baseDir) {
                    $pathsToTry[] = $baseDir . '/' . $path;
                    $pathsToTry[] = $baseDir . '/' . $normalizedPath;
                }
            }

            $actualPath = null;
            foreach ($pathsToTry as $tryPath) {
                if (file_exists($tryPath)) {
                    $actualPath = $tryPath;
                    break;
                }
            }

            if (!$actualPath) {
                $this->warn("⚠️  Path does not exist: {$path}");
                $this->warn("⚠️  Tried paths:");
                foreach (array_unique($pathsToTry) as $tryPath) {
                    $exists = file_exists($tryPath) ? '✅' : '❌';
                    $this->warn("    {$exists} {$tryPath}");
                }
                continue;
            }

            $path = $actualPath;

            if (is_file($path)) {
                // Single file - treat as individual audiobook
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($extension, $audioExtensions)) {
                    $this->info("🔍 Processing individual audio file: {$path}");
                    $audiobook = $this->processSingleAudioFile($path);
                    if ($audiobook) {
                        $audiobooks[] = $audiobook;
                    }
                } else {
                    $this->warn("⚠️  Not an audio file: {$path}");
                }
            } elseif (is_dir($path)) {
                // Skip if we've already processed this directory
                if (in_array($path, $processedDirectories)) {
                    $this->info("🔍 Directory already processed: {$path}");
                    continue;
                }

                // Directory - check if it contains subdirectories that are audiobooks
                $this->info("🔍 Processing directory: {$path}");

                // Scan recursively for individual audiobook directories
                $foundAudiobookDirs = $this->findAudiobookDirectories($path);

                if (count($foundAudiobookDirs) > 1) {
                    // Multiple individual audiobook directories found
                    $this->info("📚 Found " . count($foundAudiobookDirs) . " individual audiobook directories");
                    foreach ($foundAudiobookDirs as $audiobookDir) {
                        $audiobook = $this->processAudiobookDirectory($audiobookDir);
                        if ($audiobook) {
                            $audiobooks[] = $audiobook;
                        }
                    }
                } else {
                    // Single audiobook directory or treat whole directory as one audiobook
                    $audiobook = $this->processAudiobookDirectory($path);
                    if ($audiobook) {
                        $audiobooks[] = $audiobook;
                    }
                }

                $processedDirectories[] = $path;
            }
        }

        return $audiobooks;
    }

    /**
     * Scan directories for audiobooks
     */
    protected function scanForAudiobooks(array $directories): array
    {
        $audiobooks = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory) || !is_readable($directory)) {
                $this->warn("⚠️  Directory not accessible: {$directory}");
                continue;
            }

            // Check if directory should be skipped
            if ($this->shouldSkipDirectory($directory)) {
                if ($this->isOptionEnabled('verbose')) {
                    $this->line("  Skipping scan of '{$directory}' (matches skip pattern)");
                }
                continue;
            }

            $this->info("🔍 Scanning directory: {$directory}");

            // Find individual audiobook directories within this directory
            $foundAudiobookDirs = $this->findAudiobookDirectories($directory);

            if (count($foundAudiobookDirs) > 1) {
                // Multiple individual audiobook directories found
                $this->info("📚 Found " . count($foundAudiobookDirs) . " individual audiobook directories");
                foreach ($foundAudiobookDirs as $audiobookDir) {
                    $audiobook = $this->processAudiobookDirectory($audiobookDir);
                    if ($audiobook) {
                        $audiobooks[] = $audiobook;
                    }
                }
            } else {
                // Use recursive scanning to find audiobooks in subdirectories
                $this->scanDirectoryRecursive($directory, $audiobooks);
            }
        }

        return $audiobooks;
    }

    /**
     * Format source path for display (truncate long paths)
     */
    protected function formatSourcePathForDisplay(string $path): string
    {
        // Truncate very long paths for better display
        if (strlen($path) > 80) {
            return '...' . substr($path, -77);
        }
        return $path;
    }

    /**
     * Process a single audio file as an individual audiobook
     */
    protected function processSingleAudioFile(string $filePath): ?array
    {
        if (!file_exists($filePath) || !is_file($filePath)) {
            return null;
        }

        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, $audioExtensions)) {
            return null;
        }

        $fileSize = filesize($filePath);

        // Require at least 10MB for audiobook files
        if ($fileSize < 10 * 1024 * 1024) {
            return null;
        }

        // Look for cover image in same directory
        $coverImagePath = $this->findCoverImageForAudioFile($filePath);

        return [
            'path' => $filePath,
            'name' => pathinfo($filePath, PATHINFO_FILENAME),
            'files' => [$filePath],
            'total_size' => $fileSize,
            'cover_image_path' => $coverImagePath,
        ];
    }

    /**
     * Find cover image for an individual audio file
     * Looks for image with same basename or common cover names in same directory
     */
    protected function findCoverImageForAudioFile(string $audioFilePath): ?string
    {
        $directory = dirname($audioFilePath);
        $basename = pathinfo($audioFilePath, PATHINFO_FILENAME);
        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $commonCoverNames = ['cover', 'folder', 'albumart', 'front'];

        // Priority 1: Exact match with -Cover, -Folder, etc.
        foreach ($commonCoverNames as $suffix) {
            foreach ($imageExtensions as $ext) {
                $imagePath = "{$directory}/{$basename}-{$suffix}.{$ext}";
                if (File::exists($imagePath)) {
                    return $imagePath;
                }
            }
        }

        // Priority 2: Image with same basename as audio file
        foreach ($imageExtensions as $ext) {
            $imagePath = "{$directory}/{$basename}.{$ext}";
            if (File::exists($imagePath)) {
                return $imagePath;
            }
        }

        // Priority 3: Common cover image names
        foreach ($commonCoverNames as $name) {
            foreach ($imageExtensions as $ext) {
                $imagePath = "{$directory}/{$name}.{$ext}";
                if (File::exists($imagePath)) {
                    return $imagePath;
                }
            }
        }

        return null;
    }

    /**
     * Process a single directory as an audiobook
     */
    protected function processAudiobookDirectory(string $directory): ?array
    {
        // Handle case where a file path was passed instead of directory
        if (is_file($directory)) {
            return $this->processSingleAudioFile($directory);
        }

        if (!is_dir($directory)) {
            return null;
        }

        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $files = [];
        $totalSize = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                if (in_array($extension, $audioExtensions)) {
                    $files[] = $file->getPathname();
                    $totalSize += $file->getSize();
                }
            }
        }

        // Require at least 1 audio file and 10MB total size
        if (count($files) >= 1 && $totalSize > 10 * 1024 * 1024) {
            $coverImagePath = $this->findCoverImageInDirectory($directory);

            // CRITICAL: Sort files naturally so Part1 comes before Part2, Part10, etc.
            // This ensures we extract metadata from the first file which typically has the book intro
            natsort($files);
            $files = array_values($files); // Re-index array after sorting

            return [
                'path' => $directory,
                'name' => basename($directory),
                'files' => $files,
                'total_size' => $totalSize,
                'cover_image_path' => $coverImagePath,
            ];
        }

        return null;
    }

    /**
     * Safely check if an option is enabled (handles test scenarios where input may be null)
     */
    protected function isOptionEnabled(string $option): bool
    {
        if (!isset($this->input) || $this->input === null) {
            return false;
        }

        try {
            return $this->option($option) ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Find the best cover image in a directory
     *
     * This method searches for cover images in the following order of priority:
     * 1. Files with 'cover', 'front', 'folder', or 'albumart' in the filename
     * 2. Files that match the book title
     * 3. Common cover image names (cover.jpg, folder.jpg, etc.)
     * 4. Any other image files
     *
     * @param string $directory Directory to search for cover images
     * @return string|null Path to the best cover image, or null if none found
     */
    protected function findCoverImageInDirectory(string $directory): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }

        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $commonCoverNames = ['cover', 'folder', 'albumart', 'front'];
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $bookTitle = strtolower(basename($directory));

        $candidates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $extension = strtolower($fileInfo->getExtension());

            // Skip audio files
            if (in_array($extension, $audioExtensions)) {
                continue;
            }

            // Only process image files
            if (!in_array($extension, $imageExtensions)) {
                continue;
            }

            $filename = strtolower($fileInfo->getFilename());
            $basename = strtolower($fileInfo->getBasename('.' . $extension));
            $relativePath = ltrim(str_replace($directory, '', $fileInfo->getPathname()), DIRECTORY_SEPARATOR);
            $depth = substr_count($relativePath, DIRECTORY_SEPARATOR);

            $score = 1000; // Start with a high score (lower is better)
            $reasons = [];

            // Priority 1: Files with cover/front/folder in name (highest priority)
            if (preg_match('/(cover|front|folder|albumart)/i', $basename)) {
                $score -= 500;
                $reasons[] = 'contains cover/front/folder in name';
            }

            // Priority 2: Files that match the book title
            if (strpos($basename, $bookTitle) !== false) {
                $score -= 400;
                $reasons[] = 'matches book title';
            }

            // Priority 3: Common cover image names
            if (in_array($basename, $commonCoverNames)) {
                $score -= 300;
                $reasons[] = 'common cover name';
            }

            // Priority 4: Files in root directory
            if ($depth === 0) {
                $score -= 200;
                $reasons[] = 'in root directory';
            }

            // Priority 5: File extension (prefer jpg over png, etc.)
            $extensionScores = ['jpg' => 50, 'jpeg' => 50, 'png' => 40, 'webp' => 30, 'gif' => 20];
            $score -= $extensionScores[$extension] ?? 0;

            // Add penalty for depth (deeper paths get higher scores)
            $score += $depth * 10;

            $candidates[] = [
                'path' => $fileInfo->getPathname(),
                'score' => $score,
                'reasons' => $reasons,
                'depth' => $depth,
                'filename' => $fileInfo->getFilename(),
            ];
        }

        // Sort candidates by score (lowest score first)
        usort($candidates, function ($a, $b) {
            return $a['score'] <=> $b['score'];
        });

        // Log the top candidates for debugging
        if (count($candidates) > 0 && $this->isOptionEnabled('verbose')) {
            $this->line("Cover image candidates for {$directory}:");
            foreach (array_slice($candidates, 0, 5) as $i => $candidate) {
                $this->line(sprintf(
                    "  %d. %s (score: %d, depth: %d, reasons: %s)",
                    $i + 1,
                    $candidate['filename'],
                    $candidate['score'],
                    $candidate['depth'],
                    implode(', ', $candidate['reasons'])
                ));
            }
        }

        // Return the best candidate, or null if none found
        return $candidates[0]['path'] ?? null;
    }

    protected function normalizeCoverPriority(array &$metadata): void
    {
        $hasLocalCover = (!empty($metadata['cover_is_local_file']) && !empty($metadata['cover_url']))
            || (!empty($metadata['cover_source']) && in_array($metadata['cover_source'], ['Local file in directory', 'Existing file in directory'], true));

        if ($hasLocalCover) {
            $metadata['cover_source'] = $metadata['cover_source'] ?? 'Local file in directory';
            return;
        }

        if (!empty($metadata['cover_data'])) {
            $metadata['cover_source'] = 'Embedded in M4B';
            return;
        }

        $audibleCoverUrl = $metadata['audible_raw']['coverImageUrl'] ?? null;
        $googleCoverUrl = $metadata['google_books_raw']['volumeInfo']['imageLinks']['thumbnail'] ?? null;

        if ($audibleCoverUrl) {
            if (empty($metadata['cover_url']) || $metadata['cover_url'] !== $audibleCoverUrl) {
                if (!empty($metadata['cover_url'])) {
                    $metadata['fallback_cover_url'] = $metadata['cover_url'];
                    $metadata['fallback_cover_source'] = $metadata['cover_source'] ?? 'Unknown';
                } elseif (!empty($googleCoverUrl)) {
                    $metadata['fallback_cover_url'] = $googleCoverUrl;
                    $metadata['fallback_cover_source'] = 'Google Books';
                }

                $metadata['cover_url'] = $audibleCoverUrl;
            }

            $metadata['cover_source'] = 'Audible';
            unset($metadata['cover_is_local_file']);
            return;
        }

        if ($googleCoverUrl) {
            if (empty($metadata['cover_url'])) {
                $metadata['cover_url'] = $googleCoverUrl;
            }

            if (empty($metadata['cover_source'])) {
                $metadata['cover_source'] = 'Google Books';
            }
        }
    }

    /**
     * Get file tags with caching to avoid re-extraction
     */
    protected function getCachedFileTags(string $filePath): array
    {
        if (!isset($this->fileTagsCache[$filePath])) {
            $this->fileTagsCache[$filePath] = $this->getAIProcessor()->extractFileTags($filePath);
        }
        return $this->fileTagsCache[$filePath];
    }

    /**
     * Detect if directory contains multiple books in a series
     * Checks for: multiple large files (>3 hours each), numbered files, different titles in metadata
     *
     * @param  string  $directory  Path to directory
     * @return array|null Array of individual book data if multi-book series detected, null otherwise
     */
    protected function detectMultiBookSeries(string $directory): ?array
    {
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $largeFiles = [];
        $minDuration = 3 * 3600; // 3 hours in seconds

        if ($this->isOptionEnabled('verbose')) {
            $this->line("🔍 Checking for multi-book series in: " . basename($directory));
        }

        // Handle case where $directory is actually a file path
        if (is_file($directory)) {
            if ($this->isOptionEnabled('verbose')) {
                $this->line("  Path is a single file, not a multi-book series");
            }
            return null;
        }

        // Find all files directly in this directory (not subdirectories)
        $files = File::files($directory);

        // Fast-path: if fewer than 2 audio files, it's not a multi-book directory
        $audioFileCount = 0;
        foreach ($files as $file) {
            $extension = strtolower($file->getExtension());
            if (in_array($extension, $audioExtensions)) {
                $audioFileCount++;
            }
        }
        if ($this->isOptionEnabled('verbose')) {
            $this->line("  Found " . count($files) . " files in directory");
            $this->line("  Audio files: {$audioFileCount}");
        }
        if ($audioFileCount < 2) {
            if ($this->isOptionEnabled('verbose')) {
                $this->line("  Not a multi-book series (need at least 2 audio files)");
            }
            return null;
        }

        foreach ($files as $file) {
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $audioExtensions)) {
                continue;
            }

            $fileSize = $file->getSize();
            $filePath = $file->getPathname();
            $sizeMB = round($fileSize / (1024 * 1024), 2);

            if ($this->isOptionEnabled('verbose')) {
                $this->line("  Audio file: " . $file->getFilename() . " ({$sizeMB} MB)");
            }

            // Check if file is large enough (rough estimate: >100MB suggests long audiobook)
            if ($fileSize > 100 * 1024 * 1024) {
                // Get duration and metadata
                $duration = $this->getAudioAnalyzer()->getAudioDuration($filePath);
                $durationHours = $duration ? round($duration / 3600, 2) : 0;

                if ($this->isOptionEnabled('verbose')) {
                    $this->line("    Duration: {$durationHours} hours");
                }

                $metadata = $this->extractFileMetadata($filePath);

                if ($this->isOptionEnabled('verbose')) {
                    $this->line("    Title from metadata: " . ($metadata['title'] ?? 'N/A'));
                }

                if ($duration && $duration >= $minDuration) {
                    $largeFiles[] = [
                        'path' => $filePath,
                        'filename' => $file->getFilename(),
                        'size' => $fileSize,
                        'duration' => $duration,
                        'metadata' => $metadata,
                    ];
                    if ($this->isOptionEnabled('verbose')) {
                        $this->line("    ✓ Qualifies as large file (>3 hours)");
                    }
                } else {
                    if ($this->isOptionEnabled('verbose')) {
                        $this->line("    ✗ Too short (need >3 hours)");
                    }
                    // Early exit: if we found a file that doesn't meet criteria, likely not multi-book
                    break;
                }
            } else {
                if ($this->isOptionEnabled('verbose')) {
                    $this->line("    ✗ Too small (need >100MB)");
                }
                // Early exit: if we found a small file, likely not multi-book
                break;
            }
        }

        if ($this->isOptionEnabled('verbose')) {
            $this->line("  Large files found: " . count($largeFiles));
        }

        // Need at least 2 large files to be a multi-book series
        if (count($largeFiles) < 2) {
            if ($this->isOptionEnabled('verbose')) {
                $this->line("  Not a multi-book series (need at least 2 large files)");
            }
            return null;
        }

        // Check if files have different titles in metadata or filenames
        $uniqueTitles = [];
        $hasMetadata = false;

        foreach ($largeFiles as $fileData) {
            // Try metadata first - prefer album over title for M4B files (title often contains chapter info)
            $bookTitle = null;
            if (!empty($fileData['metadata']['album'])) {
                // Remove "(Unabridged)" and similar suffixes from album
                $bookTitle = preg_replace('/\s*\((Unabridged|Abridged)\)\s*$/i', '', $fileData['metadata']['album']);
                $hasMetadata = true;
            } elseif (
                !empty($fileData['metadata']['title']) &&
                !preg_match('/^(Chapter|Part|Track|Section)\s+\d+/i', $fileData['metadata']['title'])
            ) {
                // Use title only if it doesn't look like a chapter title
                $bookTitle = $fileData['metadata']['title'];
                $hasMetadata = true;
            }

            if ($bookTitle) {
                $uniqueTitles[$bookTitle] = true;
            } else {
                // Fall back to filename (without extension and series number prefix)
                $filename = pathinfo($fileData['filename'], PATHINFO_FILENAME);
                // Extract the last part after the last " - " (e.g., "Series - 01 - Title" → "Title")
                if (preg_match('/.*\s+-\s+(.+)$/', $filename, $matches)) {
                    $cleanName = $matches[1];
                } else {
                    $cleanName = $filename;
                }
                $uniqueTitles[$cleanName] = true;
            }
        }

        $this->line("  Unique titles found: " . count($uniqueTitles) . " (from " . ($hasMetadata ? 'metadata' : 'filenames') . ")");
        foreach ($uniqueTitles as $title => $val) {
            $this->line("    - {$title}");
        }

        // If we have multiple unique titles, this is likely a multi-book series
        if (count($uniqueTitles) >= 2) {
            $this->info("🔍 Detected multi-book series in directory: " . basename($directory));
            $this->line("  Found " . count($largeFiles) . " books with " . count($uniqueTitles) . " unique titles");

            return $this->splitMultiBookSeries($directory, $largeFiles);
        }

        $this->line("  Not a multi-book series (need at least 2 unique titles)");
        return null;
    }

    /**
     * Split multi-book series into individual book entries
     *
     * @param  string  $directory  Parent directory path
     * @param  array  $largeFiles  Array of large file data
     * @return array Array of individual book data
     */
    protected function splitMultiBookSeries(string $directory, array $largeFiles): array
    {
        $books = [];
        $seriesName = basename($directory);

        // Try to get series name from metadata first
        $seriesFromMetadata = null;
        $authorFromMetadata = null;
        foreach ($largeFiles as $fileData) {
            if (!empty($fileData['metadata']['series'])) {
                $seriesFromMetadata = $fileData['metadata']['series'];
            }
            if (!empty($fileData['metadata']['author'])) {
                if (is_array($fileData['metadata']['author'])) {
                    $authorFromMetadata = $fileData['metadata']['author'][0] ?? '';
                } else {
                    $authorFromMetadata = $fileData['metadata']['author'];
                }
            }
            if ($seriesFromMetadata && $authorFromMetadata) {
                break; // Found both, no need to continue
            }
        }

        // Use metadata if available, otherwise extract from directory name
        if ($seriesFromMetadata) {
            $series = $seriesFromMetadata;
            $author = $authorFromMetadata ?? '';
        } else {
            // Extract series info from directory name (e.g., "Author - Series Name")
            if (preg_match('/^(.+?)\s*-\s*(.+)$/', $seriesName, $matches)) {
                $author = trim($matches[1]);
                $series = trim($matches[2]);
            } else {
                $author = '';
                $series = $seriesName;
            }
        }

        foreach ($largeFiles as $fileData) {
            $filename = $fileData['filename'];
            $metadata = $fileData['metadata'];

            // Extract series number - prefer metadata 'part' field, then filename
            $seriesNumber = null;
            if (!empty($metadata['part'])) {
                // Part field might be a number or a string like "1" or "Book 1"
                if (is_numeric($metadata['part'])) {
                    $seriesNumber = (int) $metadata['part'];
                } elseif (preg_match('/(\d+)/', $metadata['part'], $matches)) {
                    $seriesNumber = (int) $matches[1];
                }
            }

            // Fall back to extracting from filename if not in metadata
            if ($seriesNumber === null) {
                $seriesNumber = $this->extractSeriesNumber($filename);
            }

            // Use metadata album (preferred for M4B) or title, or extract from filename
            if (!empty($metadata['album'])) {
                // Remove "(Unabridged)" and similar suffixes
                $bookTitle = preg_replace('/\s*\((Unabridged|Abridged)\)\s*$/i', '', $metadata['album']);
            } elseif (
                !empty($metadata['title']) &&
                !preg_match('/^(Chapter|Part|Track|Section)\s+\d+/i', $metadata['title'])
            ) {
                // Use title only if it doesn't look like a chapter title
                $bookTitle = $metadata['title'];
            } else {
                // Extract title from filename (get the last part after the last " - ")
                $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
                // Pattern: "Series - 01 - Title" → "Title"
                if (preg_match('/.*\s+-\s+(.+)$/', $filenameWithoutExt, $matches)) {
                    $bookTitle = $matches[1];
                } else {
                    $bookTitle = $filenameWithoutExt;
                }
            }

            $books[] = [
                'path' => $directory, // Keep same parent directory
                'name' => $bookTitle,
                'files' => [$fileData['path']], // Single file for this book
                'total_size' => $fileData['size'],
                'duration' => $fileData['duration'],
                'is_split_book' => true, // Flag to prevent re-detection
                'metadata' => array_merge($metadata, [
                    'title' => $bookTitle, // Ensure title is set
                    'series' => $series,
                    'series_number' => $seriesNumber,
                    'author' => $metadata['author'] ?? [$author],
                    'is_split_from_multi_book' => true,
                    'original_directory' => $directory,
                ]),
            ];
        }

        return $books;
    }

    /**
     * Extract series number from filename
     *
     * @param  string  $filename  Filename to parse
     * @return int|null Series number or null if not found
     */
    /**
     * Extract metadata from audio file
     *
     * @param string $filePath Path to the audio file
     * @return array Extracted metadata
     */
    /**
     * Clean up a title by removing common patterns
     */
    protected function cleanTitle(string $title): string
    {
        // Remove track numbers and other common patterns
        $patterns = [
            '/^\d+[.\s-]+/i',          // Leading numbers with dot/space/dash
            '/\s*[-–—]\s*\d+\s*$/',    // Trailing dash and numbers
            '/\(?:(?:Part|Book|Vol|Volume|Chapter|Ch)\.?\s*\d+\)/i', // (Part 1), (Vol. 2), etc.
            '/\[\d+\]/',               // [1], [2], etc.
            '/\(\d+\)/',              // (1), (2), etc.
            '/\s+/',                    // Multiple spaces
            '/^[\s\p{P}]+|[\s\p{P}]+$/u', // Trim punctuation and whitespace
        ];

        $title = preg_replace($patterns, ' ', $title);
        return trim($title);
    }

    protected function extractFileMetadata(string $filePath): array
    {
        $metadata = [
            'title' => pathinfo($filePath, PATHINFO_FILENAME), // Default to filename
            'artist' => null,
            'album' => null,
            'track_number' => null,
            'duration' => null,
        ];

        try {
            // Get duration using the audio analyzer
            $duration = $this->getAudioAnalyzer()->getAudioDuration($filePath);
            if ($duration !== null) {
                $metadata['duration'] = $duration;
            }

            // Get ID3 tags if available
            $fileInfo = $this->getID3->analyze($filePath);
            getid3_lib::CopyTagsToComments($fileInfo);

            if (!empty($fileInfo['tags'])) {
                $tags = $fileInfo['tags'];

                // Try to get title from various possible tag formats
                $title = null;
                foreach (['title', 'TIT2', 'TIT1', 'TALB'] as $tag) {
                    if (!empty($tags[$tag][0])) {
                        $title = $tags[$tag][0];
                        break;
                    }
                }

                // Try to get artist from various possible tag formats
                $artist = null;
                foreach (['artist', 'TPE1', 'TPE2', 'TPE1/1'] as $tag) {
                    if (!empty($tags[$tag][0])) {
                        $artist = $tags[$tag][0];
                        break;
                    }
                }

                // Try to get album from various possible tag formats
                $album = null;
                foreach (['album', 'TALB', 'TAL'] as $tag) {
                    if (!empty($tags[$tag][0])) {
                        $album = $tags[$tag][0];
                        break;
                    }
                }

                // Try to get track number from various possible tag formats
                $trackNumber = null;
                foreach (['track_number', 'TRCK', 'TRK', 'track'] as $tag) {
                    if (!empty($tags[$tag][0])) {
                        $trackNumber = $tags[$tag][0];
                        // Sometimes track numbers are in format "1/10" - just take the first part
                        if (is_string($trackNumber) && strpos($trackNumber, '/') !== false) {
                            $trackNumber = explode('/', $trackNumber)[0];
                        }
                        $trackNumber = (int) $trackNumber;
                        break;
                    }
                }

                // Update metadata with found values
                if ($title) {
                    $metadata['title'] = $title;
                }
                if ($artist) {
                    $metadata['artist'] = $artist;
                }
                if ($album) {
                    $metadata['album'] = $album;
                }
                if ($trackNumber) {
                    $metadata['track_number'] = $trackNumber;
                }
            }

            // Clean up the title (remove any track numbers or other common patterns)
            $metadata['title'] = $this->cleanTitle($metadata['title']);
        } catch (\Exception $e) {
            if ($this->isOptionEnabled('verbose')) {
                $this->warn("Error extracting metadata from {$filePath}: " . $e->getMessage());
            }
        }

        return $metadata;
    }

    /**
     * Extract series number from filename
     *
     * @param string $filename Filename to parse
     * @return int|null Series number or null if not found
     */
    protected function extractSeriesNumber(string $filename): ?int
    {
        // Try various patterns (ordered by specificity - most specific first)
        $patterns = [
            '/(?:book|vol|volume|part|#)\s*(\d+)/i',  // "Book 1", "Vol 1", "Part 1", "#1"
            '/^(\d+)\s*[-–—]/',                        // "01 - Title" or "01 – Title"
            '/^(\d+)\s+/',                             // "01 Title" (leading number with space)
            '/\s(\d+)\s*[-–—]/',                       // "Title 1 - Subtitle"
            '/[-–—]\s*(\d+)\s*[-–—]/',                 // "Series - 01 - Title"
            '/\s(\d+)\s*\.\w{3,4}$/',                  // "Title 1.m4b" (number before extension)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * Extract metadata from a single audio file
     * Handles both ID3 tags (MP3) and MP4/M4A/M4B metadata atoms
     *
                if (isset($qtComments['part'][0])) {
                    $metadata['part'] = $qtComments['part'][0];
                }
            }

            // Fall back to standard comments (for MP3 and other formats)
            if (empty($metadata) && isset($fileInfo['comments'])) {
                if (isset($fileInfo['comments']['title'][0])) {
                    $metadata['title'] = $fileInfo['comments']['title'][0];
                }

                if (isset($fileInfo['comments']['artist'][0])) {
                    $metadata['author'] = [$fileInfo['comments']['artist'][0]];
                }

                if (isset($fileInfo['comments']['album'][0])) {
                    $metadata['album'] = $fileInfo['comments']['album'][0];
                }

                if (isset($fileInfo['comments']['genre'][0])) {
                    $metadata['genre'] = [$fileInfo['comments']['genre'][0]];
                }

                if (isset($fileInfo['comments']['year'][0])) {
                    $metadata['year'] = $fileInfo['comments']['year'][0];
                }

                // Extract series information
                if (isset($fileInfo['comments']['series'][0])) {
                    $metadata['series'] = $fileInfo['comments']['series'][0];
                }

                // Extract part/book number
                if (isset($fileInfo['comments']['part'][0])) {
                    $metadata['part'] = $fileInfo['comments']['part'][0];
                }
            }

            return $metadata;
        } catch (\Exception $e) {
            return [];
        }
    }



    /**
     * Start background processing tasks while waiting for user input (enhanced)
     */

    /**
     * Show enhanced background processing status
     */


    /**
     * Find individual audiobook directories within a parent directory
     */
    protected function findAudiobookDirectories(string $parentPath): array
    {
        // If this is a file, not a directory, return empty array
        if (!is_dir($parentPath)) {
            return [];
        }

        $audiobookDirs = [];
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($parentPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            $checkedDirs = [];

            foreach ($iterator as $dir) {
                if (!$dir->isDir()) {
                    continue;
                }

                $dirPath = $dir->getPathname();

                // Skip if we've already checked this directory or a parent
                $skip = false;
                foreach ($checkedDirs as $checkedDir) {
                    if (str_starts_with($dirPath, $checkedDir)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }

                // Check if this directory directly contains audio files (not in subdirectories)
                $directFiles = File::files($dirPath);
                $hasDirectAudioFiles = false;

                foreach ($directFiles as $file) {
                    $extension = strtolower($file->getExtension());
                    if (in_array($extension, $audioExtensions)) {
                        $hasDirectAudioFiles = true;
                        break;
                    }
                }

                if ($hasDirectAudioFiles) {
                    $audiobookDirs[] = $dirPath;
                    $checkedDirs[] = $dirPath . '/';
                }
            }
        } catch (\Exception $e) {
            // If scanning fails, fall back to the original directory
            return [$parentPath];
        }

        return $audiobookDirs;
    }

    /**
     * Initialize persistent cache system
     */

    /**
     * Get directory modification time
    }

    /**
     * Enhanced ask method with background processing and quit handling
     */
    protected function askWithBackground(string $question, string $default = null, array $backgroundData = []): string
    {
        // Start background processing if data provided and enabled
        if ($this->backgroundProcessingEnabled && !empty($backgroundData)) {
            foreach ($backgroundData as $task) {
                $this->getBackgroundService()->scheduleBackgroundTask($task['type'], $task['data']);
            }
        }

        // Continuously process background tasks while waiting for user input (only if enabled)
        if ($this->backgroundProcessingEnabled) {
            $this->startContinuousBackgroundProcessing();
        }

        $response = $this->askWithImmediateInterrupt($question, $default);

        // Handle quit request or interruption
        if (strtolower(trim($response)) === 'q' || $this->inputInterrupted) {
            $this->handleUserQuit();
        }

        return $response;
    }

    /**
     * Start continuous background processing to maintain at least 3 running tasks
     */
    protected function startContinuousBackgroundProcessing(): void
    {
        // Process background tasks multiple times to ensure continuous operation
        for ($i = 0; $i < 5; $i++) {
            $this->getBackgroundService()->processBackgroundTasks();

            // Small delay to simulate processing time
            usleep(50000); // 50ms
        }

        // Show final background processing status (only if enabled)
        if ($this->backgroundProcessingEnabled) {
            $stats = $this->getBackgroundService()->getTaskStatistics();
            if (!empty($stats)) {
                $this->info("📊 Background Processing Summary:");
                foreach ($stats as $key => $value) {
                    $this->line("  • " . ucwords(str_replace('_', ' ', $key)) . ": $value");
                }
            }
        }
    }

    /**
     * Enhanced ask method for regular prompts with quit handling
     */
    public function ask($question, $default = null)
    {
        // Add quit option if not already present
        if (!str_contains($question, 'quit') && !str_contains($question, "'q'")) {
            $question = $question . " (or 'q' to quit)";
        }

        $response = $this->askWithImmediateInterrupt($question, $default);

        // Handle quit request
        if (strtolower(trim($response)) === 'q') {
            $this->handleUserQuit();
        }

        return $response;
    }

    /**
     * Handle user quit request
     */
    protected function handleUserQuit(): void
    {
        $this->userRequestedQuit = true;
        $this->warn("⚠️  User requested to quit - aborting current task...");

        // Show current progress
        $this->displayPartialSummary();

        // Clean exit
        $this->info("👋 Import process aborted by user. Goodbye!");
        exit(0);
    }

    /**
     * Display partial summary when quitting mid-process
     */
    protected function displayPartialSummary(): void
    {
        $this->newLine();
        $this->info("📊 Partial Import Summary (before quit):");

        $processed = count($this->processedBooks);
        $failed = count($this->failedBooks);
        $skipped = count($this->skippedBooks);

        if ($processed > 0) {
            $this->info("✅ Successfully processed: {$processed} books");
        }

        if ($failed > 0) {
            $this->warn("❌ Failed: {$failed} books");
            foreach ($this->failedBooks as $book) {
                $this->line("  • " . basename($book['path']) . " - " . $book['error']);
            }
        }

        if ($skipped > 0) {
            $this->info("⏭️  Skipped: {$skipped} books");
        }

        $total = $processed + $failed + $skipped;
        if ($this->totalFound > $total) {
            $remaining = $this->totalFound - $total;
            $this->line("⏸️  Not processed: {$remaining} books");
        }
    }

    /**
     * Set up signal handlers for graceful interruption
     */
    protected function setupSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            // Handle Ctrl+C (SIGINT) and SIGTERM
            pcntl_signal(SIGINT, function () {
                $this->handleUserInterruption();
            });

            pcntl_signal(SIGTERM, function () {
                $this->handleUserInterruption();
            });

            // Enable signal handling
            pcntl_async_signals(true);
        }
    }

    /**
     * Handle user interruption (Ctrl+C) - quit gracefully
     */
    protected function handleUserInterruption(): void
    {
        $this->inputInterrupted = true;
        $this->newLine();
        $this->warn("⚠️  [Request interrupted by user] - Ctrl+C detected");
        $this->info("🛑 Quitting import process gracefully...");

        // Quit directly without asking for options
        $this->handleUserQuit();
    }

    /**
     * Get raw input from the user without any trimming
     */
    protected function getRawInput(string $prompt): string
    {
        if (extension_loaded('readline')) {
            $input = readline($prompt);
            if ($input === false) { // Readline returns false on Ctrl+D (EOF)
                $this->inputInterrupted = true;
                return '';
            }
            return $input;
        } else {
            $this->output->write($prompt);
            $input = fgets(STDIN);
            return $input === false ? '' : rtrim($input, "\n");
        }
    }

    /**
     * Ask for input with immediate interruption capability
     */
    protected function askWithImmediateInterrupt(string $question, string $default = null): string
    {
        if (extension_loaded('readline')) {
            // Use readline for advanced line editing
            $prompt = $question . ($default ? " [{$default}]" : '') . ': ';
            $input = readline($prompt);
            if ($input === false) { // Readline returns false on Ctrl+D (EOF)
                $this->inputInterrupted = true;
                return '';
            }
            // Treat a single space as blank/empty input (don't use default)
            if ($input === ' ') {
                return '';
            }
            return trim($input) ?: ($default ?? '');
        } else {
            // Fallback to basic input if readline extension is not available
            static $warned = false;
            if (!$warned) {
                $this->error("❌ PHP readline extension is not enabled. Advanced line editing features (arrow keys, etc.) will not be available. Consider enabling it for a better interactive experience.");
                $warned = true;
            }

            $this->output->write($question . ($default ? " [{$default}]" : '') . ': ');
            $input = trim(fgets(STDIN));

            // Treat a single space as blank/empty input (don't use default)
            if ($input === ' ') {
                return '';
            }
            // Return default if empty
            return $input ?: ($default ?? '');
        }
    }

    /**
     * Helper methods for background processing
     */
    protected function groupCdDirectories(array $potentialBooks): array
    {
        $grouped = [];
        $cdPattern = '/^(cd|disc|disk)[\s_-]*(\d+)$/i';

        // First, identify CD directories and their parents
        $cdDirectories = [];
        $parentDirectories = [];

        foreach ($potentialBooks as $path => $bookData) {
            $dirName = basename($path);
            if (preg_match($cdPattern, $dirName, $matches)) {
                $parentPath = dirname($path);
                $cdDirectories[$path] = [
                    'parent' => $parentPath,
                    'cd_number' => (int) $matches[2],
                    'data' => $bookData,
                ];

                // Initialize parent directory data
                if (!isset($parentDirectories[$parentPath])) {
                    $parentDirectories[$parentPath] = [
                        'path' => $parentPath,
                        'name' => basename($parentPath),
                        'files' => [],
                        'total_size' => 0,
                        'cd_count' => 0,
                    ];
                }

                // Merge CD data into parent
                $parentDirectories[$parentPath]['files'] = array_merge(
                    $parentDirectories[$parentPath]['files'],
                    $bookData['files']
                );
                $parentDirectories[$parentPath]['total_size'] += $bookData['total_size'];
                $parentDirectories[$parentPath]['cd_count']++;
            } else {
                // Regular directory - keep as-is
                $grouped[$path] = $bookData;
            }
        }

        // Add parent directories that have CD subdirectories
        foreach ($parentDirectories as $parentPath => $parentData) {
            if ($parentData['cd_count'] > 1) {
                // Multiple CDs found - treat as single audiobook
                $this->line("📀 Detected multi-disc audiobook: " . basename($parentPath) . " ({$parentData['cd_count']} discs)");
                $grouped[$parentPath] = $parentData;
            } else {
                // Only one CD directory - might be a single disc or false positive
                // Keep the original CD directory instead of parent
                foreach ($cdDirectories as $cdPath => $cdInfo) {
                    if ($cdInfo['parent'] === $parentPath) {
                        $grouped[$cdPath] = $cdInfo['data'];
                        break;
                    }
                }
            }
        }

        return $grouped;
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
            $author = is_array($metadata['author']) ? ($metadata['author'][0] ?? '') : $metadata['author'];

            $existingBook = Book::where('title', '=', $title)
                ->whereHas('authors', function ($query) use ($author) {
                    $query->where('name', $author);
                })
                ->first();

            if ($existingBook) {
                // Double-check that titles are truly identical (case-insensitive)
                if (strtolower(trim($existingBook->title)) === strtolower(trim($title))) {
                    // Critical check: if either book has series info, compare series numbers
                    // Books with different series numbers should NEVER match, even with same title
                    $existingSeries = $existingBook->series ?? '';
                    $existingSeriesNumber = $existingBook->series_number ?? 0;
                    $newSeries = $metadata['series'] ?? '';
                    $newSeriesNumber = $metadata['series_number'] ?? 0;

                    // If either book has series number info, they must match exactly
                    if ($existingSeriesNumber > 0 || $newSeriesNumber > 0) {
                        if ($existingSeriesNumber != $newSeriesNumber) {
                            return false; // Different series numbers = different books
                        }
                    }

                    // If both have series names, they must match
                    if (!empty($existingSeries) && !empty($newSeries)) {
                        if ($existingSeries !== $newSeries) {
                            return false; // Different series = different books
                        }
                    }

                    return true;
                }
            }
        }

        // Fallback to exact directory basename match only (much more restrictive)
        // Only match if the final directory name is exactly the same
        // REMOVED: LIKE pattern matching to prevent false positives between series books
        $existingBook = Book::where('directory_path', '=', $baseName)
            ->first();

        return $existingBook !== null;
    }

    /**
     * Find existing book in database (returns Book model instead of boolean)
     */
    protected function findExistingBook(string $path, array $metadata = []): ?Book
    {
        $baseName = basename($path);

        // First check by ISBN if available (most reliable)
        if (!empty($metadata['isbn'])) {
            $existingBook = Book::where('isbn', $metadata['isbn'])->first();
            if ($existingBook) {
                return $existingBook;
            }
        }

        // Then check by exact title and author combination (if available)
        // Only consider exact title matches to avoid false positives between series books
        if (!empty($metadata['title']) && !empty($metadata['author'])) {
            $title = $metadata['title'];
            $author = is_array($metadata['author']) ? ($metadata['author'][0] ?? '') : $metadata['author'];

            // Use exact title match only - no partial matching to avoid series conflicts
            $existingBook = Book::where('title', '=', $title)
                ->whereHas('authors', function ($query) use ($author) {
                    $query->where('name', $author);
                })
                ->first();

            if ($existingBook) {
                $this->line("  ✓ Found by exact title+author: {$existingBook->title} (ID: {$existingBook->id})");
                $this->line("    Existing title: '{$existingBook->title}' vs New title: '{$title}'");
                $this->line("    Directory: {$existingBook->directory_path}");

                // Double-check that titles are truly identical (case-insensitive)
                if (strtolower(trim($existingBook->title)) === strtolower(trim($title))) {
                    // Critical check: if either book has series info, compare series numbers
                    // Books with different series numbers should NEVER match, even with same title
                    $existingSeries = $existingBook->series ?? '';
                    $existingSeriesNumber = $existingBook->series_number ?? 0;
                    $newSeries = $metadata['series'] ?? '';
                    $newSeriesNumber = $metadata['series_number'] ?? 0;

                    // If either book has series number info, they must match exactly
                    if ($existingSeriesNumber > 0 || $newSeriesNumber > 0) {
                        if ($existingSeriesNumber != $newSeriesNumber) {
                            $this->line("  ⚠️  Different series numbers (existing: #{$existingSeriesNumber}, new: #{$newSeriesNumber}) - not a duplicate");
                            return null;
                        }
                    }

                    // If both have series names, they must match
                    if (!empty($existingSeries) && !empty($newSeries)) {
                        if ($existingSeries !== $newSeries) {
                            $this->line("  ⚠️  Different series (existing: '{$existingSeries}', new: '{$newSeries}') - not a duplicate");
                            return null;
                        }
                    }

                    return $existingBook;
                } else {
                    $this->line("  ⚠️  Titles don't match exactly after normalization - not a duplicate");
                }
            }
        }

        // Fallback to exact directory basename match only (much more restrictive)
        // REMOVED: LIKE pattern matching to prevent false positives between series books
        $existingBook = Book::where('directory_path', '=', $baseName)
            ->first();

        return $existingBook;
    }

    /**
     * Process a single audiobook with AI and external enrichment
     */
    protected function processAudiobook(array $audiobook): void
    {
        // Skip multi-book detection if this is already a split book
        if (empty($audiobook['is_split_book'])) {
            // Check if this directory contains multiple books in a series
            // This must be done BEFORE validation and AI processing
            $multiBooks = $this->detectMultiBookSeries($audiobook['path']);
            if ($multiBooks) {
                $this->info("📚 Processing multi-book series separately...");
                foreach ($multiBooks as $individualBook) {
                    // Process each book individually
                    $this->processAudiobook($individualBook);
                }
                return; // Stop processing the parent directory
            }
        }

        // Validate audiobook files before processing
        $validateStartTime = microtime(true);
        if (!$this->validateAudiobookFiles($audiobook)) {
            return; // Skip if validation fails
        }
        $validateDuration = round((microtime(true) - $validateStartTime) * 1000);
        if ($this->isOptionEnabled('verbose')) {
            $this->line("  ⏱️  File validation took: {$validateDuration}ms");
        }

        // Process metadata with AI
        $metadataStartTime = microtime(true);
        $aiMetadata = $this->processAudiobookMetadata($audiobook);
        $metadataDuration = round((microtime(true) - $metadataStartTime) * 1000);
        if ($this->isOptionEnabled('verbose')) {
            $this->line("  ⏱️  Metadata processing took: {$metadataDuration}ms");
        }

        if (!$aiMetadata) {
            return; // Skip if metadata processing failed
        }

        // Collection info is already injected in processAudiobookMetadata()

        // Check for cover image in source directory (prefer local files over remote sources)
        if (!empty($audiobook['cover_image_path'])) {
            if (!empty($aiMetadata['cover_url']) && empty($aiMetadata['cover_is_local_file']) && empty($aiMetadata['cover_data'])) {
                // Preserve remote cover as fallback
                $aiMetadata['fallback_cover_url'] = $aiMetadata['cover_url'];
                $aiMetadata['fallback_cover_source'] = $aiMetadata['cover_source'] ?? null;
            }

            $aiMetadata['cover_url'] = $audiobook['cover_image_path'];
            // Mark as local file so BookImportService knows to copy instead of download
            $aiMetadata['cover_is_local_file'] = true;
            $aiMetadata['cover_source'] = 'Local file in directory';
            $this->info("  ✓ Using local cover image: " . basename($audiobook['cover_image_path']));
        }

        // If enrichment provided both Audible and Google cover URLs, prefer Audible
        if (
            !empty($aiMetadata['google_books_raw']['volumeInfo']['imageLinks']['thumbnail']) &&
            !empty($aiMetadata['audible_raw']['coverImageUrl']) &&
            empty($aiMetadata['cover_is_local_file']) &&
            empty($aiMetadata['cover_data'])
        ) {
            $this->info("  ℹ️  Audible cover preferred over Google Books thumbnail");
            // Preserve Google Books as fallback
            $aiMetadata['fallback_cover_url'] = $aiMetadata['google_books_raw']['volumeInfo']['imageLinks']['thumbnail'];
            $aiMetadata['fallback_cover_source'] = 'Google Books';
            $aiMetadata['cover_url'] = $aiMetadata['audible_raw']['coverImageUrl'];
            $aiMetadata['cover_source'] = 'Audible';
        }

        // Note: We'll check for existing cover images in the DESTINATION directory
        // after the book is created and files are moved, not in the source directory

        // If this is a split book, preserve the pre-set series number and other metadata
        if (!empty($audiobook['metadata'])) {
            // Merge pre-set metadata, giving priority to split book metadata for series info
            if (isset($audiobook['metadata']['series_number'])) {
                $aiMetadata['series_number'] = $audiobook['metadata']['series_number'];
                $this->line("  Using pre-set series number: {$aiMetadata['series_number']}");
            }
            if (isset($audiobook['metadata']['series'])) {
                $aiMetadata['series'] = $audiobook['metadata']['series'];
            }
            if (isset($audiobook['metadata']['title'])) {
                $aiMetadata['title'] = $audiobook['metadata']['title'];
            }
        }

        // CRITICAL: Extract cover image and additional metadata from M4B file
        // This should ALWAYS run for M4B files to extract embedded covers
        // Embedded covers have priority over downloaded covers
        if (!empty($audiobook['files'][0])) {
            $audioFilePath = $audiobook['files'][0];

            // Extract file tags using existing AIBookProcessor method (cached)
            $this->line("  Extracting metadata from audio file: " . basename($audioFilePath));
            $this->line("  Full path: " . $audioFilePath);
            $this->line("  File exists: " . (file_exists($audioFilePath) ? 'YES' : 'NO'));

            $fileTags = $this->getCachedFileTags($audioFilePath);

            $this->line("  File tags extracted: " . (empty($fileTags) ? 'NONE' : count($fileTags) . ' fields'));
            if (!empty($fileTags)) {
                $this->line("  Available fields: " . implode(', ', array_keys($fileTags)));
            } else {
                $this->warn("  ✗ No tags extracted - check if getID3 is installed and file is readable");
            }

            // Use metadata from file tags if available and not already set
            if (!empty($fileTags)) {
                if (empty($aiMetadata['narrator']) && !empty($fileTags['narrator'])) {
                    $aiMetadata['narrator'] = is_array($fileTags['narrator']) ? $fileTags['narrator'] : [$fileTags['narrator']];
                    $this->line("  ✓ Using narrator from audio file: " . (is_array($aiMetadata['narrator']) ? implode(', ', $aiMetadata['narrator']) : $aiMetadata['narrator']));
                }
                if (empty($aiMetadata['year']) && !empty($fileTags['year'])) {
                    $aiMetadata['year'] = $fileTags['year'];
                    $this->line("  ✓ Using year from audio file: {$aiMetadata['year']}");
                }
                if (empty($aiMetadata['publisher']) && !empty($fileTags['publisher'])) {
                    $aiMetadata['publisher'] = $fileTags['publisher'];
                    $this->line("  ✓ Using publisher from audio file: {$aiMetadata['publisher']}");
                }

                // Extract embedded cover image if available
                if (!empty($fileTags['picture']['data'])) {
                    $this->line("  Found embedded cover image in audio file");
                    // Store the cover data temporarily, don't write to source directory
                    $aiMetadata['cover_data'] = $fileTags['picture']['data'];
                    $aiMetadata['cover_source'] = 'Embedded in audio file';

                    // CRITICAL: Clear cover_url so we don't download when we have embedded cover
                    // Embedded covers have priority over downloaded covers
                    if (!empty($aiMetadata['cover_url'])) {
                        $this->line("  Ignoring cover URL in favor of embedded cover");
                        unset($aiMetadata['cover_url']);
                    }

                    $this->info("  ✓ Found embedded cover in audio file (will be saved to final directory)");
                } else {
                    $this->warn("  ✗ No embedded cover image found in audio file");
                    if (isset($fileTags['picture'])) {
                        $this->line("  Picture field exists but no data: " . json_encode(array_keys($fileTags['picture'])));
                    }
                }
            } else {
                $this->warn("  ✗ No file tags extracted from audio file");
            }
        }

        // Extract series number from title and clean metadata (only if not already set)
        if (!empty($aiMetadata['title'])) {
            $this->getEnrichmentService()->extractSeriesNumberFromTitle($aiMetadata);
        }

        // CRITICAL: Fix generic/useless titles extracted from poor ID3 tags
        // If title matches generic patterns, derive it from directory name instead
        if (!empty($aiMetadata['title'])) {
            $title = $aiMetadata['title'];
            $isGenericTitle = preg_match('/unknown|untitled|track\s*\d+|part\s*\d+|chapter\s*\d+|^no\s+title/i', $title);

            if ($isGenericTitle && !empty($audiobook['path'])) {
                // Extract title from directory structure
                // Pattern: .../SeriesName/N BookTitle -> Title: "BookTitle", Series: "SeriesName" #N
                $pathParts = explode('/', trim($audiobook['path'], '/'));
                $dirName = end($pathParts); // e.g., "3 Rebel Undercover"
                $parentDirName = count($pathParts) > 1 ? $pathParts[count($pathParts) - 2] : null;

                // Remove leading number from directory name (e.g., "3 Rebel Undercover" -> "Rebel Undercover")
                $cleanedTitle = preg_replace('/^(\d+[\s\-._]*)+/', '', $dirName);
                $cleanedTitle = trim($cleanedTitle);

                if (!empty($cleanedTitle)) {
                    $this->info("  ✓ Fixing generic title '{$title}' -> '{$cleanedTitle}' (from directory name)");
                    $aiMetadata['title'] = $cleanedTitle;

                    // If series not set and parent directory exists, use it as series
                    if (empty($aiMetadata['series']) && !empty($parentDirName)) {
                        // Extract series number from directory name (e.g., "3 Rebel Undercover" -> 3)
                        if (preg_match('/^(\d+)[\s\-._]/', $dirName, $matches)) {
                            $aiMetadata['series'] = $parentDirName;
                            $aiMetadata['series_number'] = (int)$matches[1];
                            $this->info("  ✓ Extracted series from path: '{$parentDirName}' #{$aiMetadata['series_number']}");
                        }
                    }
                }
            }
        }

        // CRITICAL: Propagate metadata from previous books in the same series
        // When importing multiple books from a series, copy missing fields from previous books
        if (!empty($aiMetadata['series'])) {
            $seriesKey = strtolower(trim($aiMetadata['series']));

            // If we have metadata from a previous book in this series, use it to fill gaps
            if (isset($this->previousBookMetadata[$seriesKey])) {
                $previousMeta = $this->previousBookMetadata[$seriesKey];
                $fieldsToPropagate = ['author', 'narrator', 'genre', 'publisher', 'language'];

                foreach ($fieldsToPropagate as $field) {
                    // Check if field is missing or empty (including empty arrays)
                    $isFieldEmpty = !isset($aiMetadata[$field]) ||
                                    $aiMetadata[$field] === null ||
                                    $aiMetadata[$field] === '' ||
                                    (is_array($aiMetadata[$field]) && count($aiMetadata[$field]) === 0);

                    $hasPreviousValue = isset($previousMeta[$field]) &&
                                       $previousMeta[$field] !== null &&
                                       $previousMeta[$field] !== '' &&
                                       (!is_array($previousMeta[$field]) || count($previousMeta[$field]) > 0);

                    if ($isFieldEmpty && $hasPreviousValue) {
                        $aiMetadata[$field] = $previousMeta[$field];
                        $displayValue = is_array($aiMetadata[$field]) ? implode(', ', $aiMetadata[$field]) : $aiMetadata[$field];
                        $this->info("  ✓ Using {$field} from previous book in series: {$displayValue}");
                    }
                }
            }

            // Store this book's metadata for future books in the series
            $this->previousBookMetadata[$seriesKey] = [
                'author' => $aiMetadata['author'] ?? [],
                'narrator' => $aiMetadata['narrator'] ?? [],
                'genre' => $aiMetadata['genre'] ?? [],
                'publisher' => $aiMetadata['publisher'] ?? null,
                'language' => $aiMetadata['language'] ?? null,
                'year' => $aiMetadata['year'] ?? null,
            ];
        }

        // Handle multi-book patterns (simplified) - skip if already a split book
        // CRITICAL: Only use if series is NOT already set from file tags
        // Check for --no-multi-book flag or '^' suffix in directory name
        $disableMultiBook = $this->option('no-multi-book') || str_ends_with($audiobook['name'], '^');
        if (!$disableMultiBook && empty($audiobook['is_split_book']) && empty($aiMetadata['series'])) {
            $multiBookInfo = $this->getMetadataService()->detectMultiBookPattern($audiobook['name']);
            if ($multiBookInfo) {
                $this->info("📚 Detected multi-book directory: {$multiBookInfo['series_name']} [{$multiBookInfo['start_number']}-{$multiBookInfo['end_number']}]");
                $aiMetadata['series'] = $multiBookInfo['series_name'];
                $aiMetadata['multi_book_numbers'] = range($multiBookInfo['start_number'], $multiBookInfo['end_number']);
            }
        }

        // Strip '^' suffix from directory name if present (used to disable multi-book detection)
        if (str_ends_with($audiobook['name'], '^')) {
            $audiobook['name'] = substr($audiobook['name'], 0, -1);
            if (isset($aiMetadata['title']) && str_ends_with($aiMetadata['title'], '^')) {
                $aiMetadata['title'] = substr($aiMetadata['title'], 0, -1);
            }
        }

        // Check for duplicates with AI-extracted metadata (more accurate than path-based check)
        $duplicateStartTime = microtime(true);
        if (!$this->handleDuplicateDetection($audiobook, $aiMetadata)) {
            return; // Skip if duplicate handling indicated to stop processing
        }
        $duplicateDuration = round((microtime(true) - $duplicateStartTime) * 1000);
        if ($this->isOptionEnabled('verbose')) {
            $this->line("  ⏱️  Duplicate detection took: {$duplicateDuration}ms");
        }

        // Step 2: External data enrichment (before manual review)
        $this->performExternalDataEnrichment($aiMetadata);
        $this->normalizeCoverPriority($aiMetadata);

        // Fix Graphic Audio metadata AFTER enrichment (so it overrides external data)
        $gaStartTime = microtime(true);
        $this->fixGraphicAudioMetadata($aiMetadata, $audiobook);
        $gaDuration = round((microtime(true) - $gaStartTime) * 1000);
        if ($this->isOptionEnabled('verbose')) {
            $this->line("  ⏱️  GraphicAudio metadata fix took: {$gaDuration}ms");
        }

        // Inherit genre from existing series books by same author
        $this->inheritGenreFromSeries($aiMetadata);

        $this->newLine();

        // Add source path for display and processing
        $aiMetadata['source_path'] = $audiobook['path'];

        $displayStartTime = microtime(true);
        $this->displayEnrichedMetadata($aiMetadata);
        $displayDuration = round((microtime(true) - $displayStartTime) * 1000);
        if ($this->isOptionEnabled('verbose')) {
            $this->line("  ⏱️  Metadata display took: {$displayDuration}ms");
        }
        $this->newLine();

        // REMOVED: "Expected directory path" line - confusing and shows incomplete path
        // The full path with title is already shown in the "Directory Path" field in the table above

        // Step 3: Manual review (unless in auto mode)
        if (!$this->handleManualReview($aiMetadata, $audiobook)) {
            return; // User rejected or auto mode skipped
        }

        // CRITICAL: After this point, $aiMetadata contains user-approved data
        // DO NOT modify title, author, series, or custom_directory_path
        // These values must be preserved exactly as approved by the user

        // Step 4: Import to database
        $this->performDatabaseImport($aiMetadata, $audiobook);
    }

    /**
     * Handle duplicate detection and user interaction
     */
    protected function handleDuplicateDetection(array $audiobook, array &$aiMetadata): bool
    {
        $existingBook = $this->findExistingBook($audiobook['path'], $aiMetadata);
        if (!$existingBook) {
            return true; // No duplicate found, continue processing
        }

        $this->warn("⚠️  Book already exists (detected after AI processing)");
        $this->line("  Found existing book: '{$existingBook->title}' (ID: {$existingBook->id})");

        // Get the existing book's directory path in the library
        $bookStoragePath = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        if (!$bookStoragePath || !$existingBook->directory_path) {
            $this->warn("📁 Cannot compare directories (storage path or directory path missing)");
            $this->warn("  This may indicate a configuration issue or corrupted database entry");

            // In auto mode, skip books that need user decision
            if ($this->option('auto')) {
                $this->warn("⚠️  Auto mode: Skipping book that requires user decision");
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'Cannot compare directories - requires manual review',
                ];
                return false;
            }

            $this->line("\nOptions:");
            $this->line("1. Skip import");
            $this->line("2. Continue anyway (may cause issues)");

            $choice = $this->ask("Choose an option (1-2)", '1');

            if ($choice === '2') {
                return true; // Continue with import
            } else {
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'Cannot compare directories - missing configuration or path',
                ];
                return false; // Stop processing
            }
        }

        // Handle both absolute and relative paths in directory_path
        if (str_starts_with($existingBook->directory_path, '/')) {
            $existingDir = $existingBook->directory_path;
        } else {
            $existingDir = $bookStoragePath . '/' . $existingBook->directory_path;
        }

        if (!File::isDirectory($existingDir)) {
            $this->warn("📁 Existing directory not found - files may have been deleted");
            $this->line("  Expected path: {$existingDir}");
            $this->info("  Database entry exists but files are missing");

            // In auto mode, skip books that need user decision
            if ($this->option('auto')) {
                $this->warn("⚠️  Auto mode: Skipping book that requires user decision");
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'Existing book missing files - requires manual review',
                ];
                return false;
            }

            // Offer to restore files from new download
            $this->line("\nOptions:");
            $this->line("1. Restore files from new download (use existing database entry)");
            $this->line("2. Skip import (leave database entry as-is)");

            $choice = $this->ask("Choose an option (1-2)", '1');

            if ($choice === '1') {
                $this->info("📁 Restoring files to existing book entry");
                // Use the existing book and just move the files
                $success = $this->getImportService()->moveFilesToLibrary($audiobook, $existingBook, [
                    'operation' => $this->option('copy-files') ? 'copy' : 'move'
                ]);

                if ($success) {
                    $this->info("✅ Files restored successfully to existing book (ID: {$existingBook->id})");
                    $this->processedBooks[] = [
                        'path' => $audiobook['path'],
                        'book_id' => $existingBook->id,
                        'title' => $existingBook->title,
                    ];
                } else {
                    $this->error("❌ Failed to restore files");
                    $this->failedBooks[] = [
                        'path' => $audiobook['path'],
                        'error' => 'Failed to restore files to existing book',
                    ];
                }
                return false; // Stop processing since we handled it
            } else {
                $this->info("📁 Skipping import");
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'User chose to skip (existing book with missing files)',
                ];
                return false; // Stop processing
            }
        }

        // Compare directories to see if they're identical
        $comparison = $this->compareDirectories($audiobook['path'], $existingDir);

        $this->line("📊 Comparing source and existing directories:");
        $this->line("  Source files: {$comparison['source_count']}");
        $this->line("  Existing files: {$comparison['target_count']}");
        $this->line("  Identical: " . ($comparison['identical'] ? 'Yes' : 'No'));

        if ($comparison['identical']) {
            $this->info("🔍 Source and existing directories are identical - cleaning up source");
            // In auto mode or when forced, delete automatically without confirmation
            $forceDelete = $this->option('auto') || $this->option('force');
            $this->cleanupSourceDirectory($audiobook, $forceDelete);
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'Duplicate - source cleaned up (identical to existing)',
            ];
            return false; // Stop processing this audiobook
        }

        // Directories differ - get user decision
        return $this->handleDirectoryConflict($audiobook, $existingBook, $comparison, $aiMetadata);
    }

    /**
     * Compare two directories to check if they're identical
     */
    protected function compareDirectories(string $sourceDir, string $targetDir): array
    {
        // Handle single file case
        if (File::isFile($sourceDir)) {
            $sourceFileName = basename($sourceDir);
            $targetFiles = File::isDirectory($targetDir) ? File::allFiles($targetDir) : [];
            $targetFileNames = array_map(fn ($file) => $file->getFilename(), $targetFiles);

            $identical = count($targetFiles) === 1 && in_array($sourceFileName, $targetFileNames);

            return [
                'identical' => $identical,
                'source_count' => 1,
                'target_count' => count($targetFiles),
                'source_files' => [$sourceFileName],
                'target_files' => $targetFileNames,
            ];
        }

        // Handle directory case
        if (!File::isDirectory($sourceDir) || !File::isDirectory($targetDir)) {
            return [
                'identical' => false,
                'source_count' => 0,
                'target_count' => 0,
                'source_files' => [],
                'target_files' => [],
            ];
        }

        $sourceFiles = File::allFiles($sourceDir);
        $targetFiles = File::allFiles($targetDir);

        $sourceFileNames = array_map(fn ($file) => $file->getFilename(), $sourceFiles);
        $targetFileNames = array_map(fn ($file) => $file->getFilename(), $targetFiles);

        $identical = count($sourceFiles) === count($targetFiles) &&
            empty(array_diff($sourceFileNames, $targetFileNames));

        return [
            'identical' => $identical,
            'source_count' => count($sourceFiles),
            'target_count' => count($targetFiles),
            'source_files' => $sourceFileNames,
            'target_files' => $targetFileNames,
        ];
    }

    /**
     * Clean up source directory after determining it's a duplicate
     *
     * @param array $audiobook Audiobook data with path
     * @param bool $force Force deletion without prompting
     */
    protected function cleanupSourceDirectory(array $audiobook, bool $force = false): void
    {
        $sourcePath = $audiobook['path'];

        // Safety check: ensure we're not trying to delete important directories
        $protectedPaths = [
            '/media/download',
            '/media/download/audiobooks',
            '/media/lyra_data',
            '/media/lyra_data/download',
            config('app.book_root', '/media/lyra_data1/audiobooks/books'),
        ];

        foreach ($protectedPaths as $protectedPath) {
            if ($protectedPath && rtrim($sourcePath, '/') === rtrim($protectedPath, '/')) {
                $this->warn("  ⚠️  Cannot delete protected directory: {$sourcePath}");
                return;
            }
        }

        // Check if it's a directory or single file
        if (is_dir($sourcePath)) {
            if ($force || $this->confirm("Delete source directory: {$sourcePath}?", true)) {
                try {
                    File::deleteDirectory($sourcePath);
                    $this->info("  ✓ Deleted source directory");
                } catch (\Exception $e) {
                    $this->error("  ✗ Failed to delete source directory: " . $e->getMessage());
                }
            }
        } elseif (is_file($sourcePath)) {
            if ($force || $this->confirm("Delete source file: {$sourcePath}?", true)) {
                try {
                    File::delete($sourcePath);
                    $this->info("  ✓ Deleted source file");
                } catch (\Exception $e) {
                    $this->error("  ✗ Failed to delete source file: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Display directory comparison information
     */
    protected function displayDirectoryComparison(array $comparison): void
    {
        $this->line("\n📊 Directory Comparison:");
        $this->line("  Source files: " . $comparison['source_count']);
        $this->line("  Target files: " . $comparison['target_count']);

        if (!empty($comparison['source_files']) && !empty($comparison['target_files'])) {
            $onlyInSource = array_diff($comparison['source_files'], $comparison['target_files']);
            $onlyInTarget = array_diff($comparison['target_files'], $comparison['source_files']);

            if (!empty($onlyInSource)) {
                $this->line("\n  Files only in source:");
                foreach (array_slice($onlyInSource, 0, 5) as $file) {
                    $this->line("    - {$file}");
                }
                if (count($onlyInSource) > 5) {
                    $this->line("    ... and " . (count($onlyInSource) - 5) . " more");
                }
            }

            if (!empty($onlyInTarget)) {
                $this->line("\n  Files only in target:");
                foreach (array_slice($onlyInTarget, 0, 5) as $file) {
                    $this->line("    - {$file}");
                }
                if (count($onlyInTarget) > 5) {
                    $this->line("    ... and " . (count($onlyInTarget) - 5) . " more");
                }
            }
        }
    }

    /**
     * Handle directory conflict when duplicate books have different content
     */
    protected function handleDirectoryConflict(array $audiobook, $existingBook, array $comparison, array &$aiMetadata): bool
    {
        $this->warn("📁 Directories differ - manual decision needed");

        // In auto mode, skip books that need user decision
        if ($this->option('auto')) {
            $this->warn("⚠️  Auto mode: Skipping book that requires user decision");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'Duplicate with different content - requires manual review',
            ];
            return false;
        }

        if ($this->isOptionEnabled('verbose')) {
            $this->line("🔍 Debug: Comparison data structure exists: " . (is_array($comparison) ? 'YES' : 'NO'));
            if (is_array($comparison)) {
                $this->line("🔍 Debug: Keys present: " . implode(', ', array_keys($comparison)));
            }
        }
        $this->displayDirectoryComparison($comparison);

        // Prompt user for action when directories differ
        $this->line("\nOptions:");
        $this->line("1. Skip import completely (leave both directories unchanged)");
        $this->line("2. Replace existing with source");
        $this->line("3. Delete source (keep existing)");
        $this->line("4. Import anyway with new name (rename source directory)");

        $choice = $this->ask("Choose an option (1-4)", '1');

        switch ($choice) {
            case '2':
                // Replace existing - remove existing and continue with import
                $bookStoragePath = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
                // Handle both absolute and relative paths
                if (str_starts_with($existingBook->directory_path, '/')) {
                    $existingDir = $existingBook->directory_path;
                } else {
                    $existingDir = $bookStoragePath . '/' . $existingBook->directory_path;
                }
                $this->info("🗑️ Removing existing directory to replace with source");
                File::deleteDirectory($existingDir);
                return true; // Continue with import

            case '3':
                // Delete source, keep existing
                $this->info("🗑️ Removing source directory, keeping existing");
                $this->cleanupSourceDirectory($audiobook, true);
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'User chose to keep existing over source',
                ];
                return false; // Stop processing

            case '4':
                // Import with renamed directory - store preference for later
                $this->info("📁 Will import with renamed directory to avoid conflict");
                $aiMetadata['_force_rename_directory'] = true;
                return true; // Continue with import

            case '1':
            default:
                // Skip import completely
                $this->info("📁 Skipping import, leaving both directories unchanged");
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'User chose to skip import (directory conflict)',
                ];
                return false; // Stop processing
        }
    }

    /**
     * Perform external data enrichment on metadata
     */
    protected function performExternalDataEnrichment(array &$aiMetadata): void
    {
        if ($this->option('skip-enrichment')) {
            return;
        }

        $startTime = microtime(true);
        $this->info("🔍 Attempting to enrich with external data...");

        $enrichStartTime = microtime(true);
        $enrichedData = $this->getEnrichmentService()->enrichWithExternalData($aiMetadata);
        $enrichDuration = round((microtime(true) - $enrichStartTime) * 1000);

        if ($this->isOptionEnabled('verbose')) {
            $this->line("  ⏱️  Enrichment API call took: {$enrichDuration}ms");
        }

        if (!$enrichedData) {
            $this->warn("⚠️  No enrichment data found");
            return;
        }

        if ($this->getEnrichmentService()->isValidEnrichment($aiMetadata, $enrichedData)) {
            // Preserve existing cover or M4B-extracted cover (priority over enrichment sources)
            $preservedCover = null;
            $preservedSource = null;
            $preservedIsLocalFile = false;

            if (isset($aiMetadata['cover_source'])) {
                if (
                    $aiMetadata['cover_source'] === 'Existing file in directory' ||
                    $aiMetadata['cover_source'] === 'Local file in directory'
                ) {
                    $preservedCover = $aiMetadata['cover_url'] ?? null;
                    $preservedSource = $aiMetadata['cover_source'];
                    $preservedIsLocalFile = !empty($aiMetadata['cover_is_local_file']);
                    $this->line("  Preserving local cover file (priority over all sources)");
                } elseif ($aiMetadata['cover_source'] === 'Embedded in M4B') {
                    // For embedded covers, we have cover_data, not cover_url
                    // Don't try to preserve cover_url, just preserve the source flag
                    $preservedSource = $aiMetadata['cover_source'];
                    $this->line("  Preserving M4B embedded cover (priority over enrichment sources)");
                }
            }

            // CRITICAL: Only use enrichment to FILL IN missing fields, never override existing data
            // File tags and AI extraction are authoritative
            foreach ($enrichedData as $key => $value) {
                // Skip cover_url if we have embedded cover data or preserved cover
                if ($key === 'cover_url' && ($preservedSource || !empty($aiMetadata['cover_data']))) {
                    continue; // Don't overwrite embedded or preserved covers
                }

                // Special handling for year/published_year - check both
                if ($key === 'year' || $key === 'published_year') {
                    if (empty($aiMetadata['year']) && empty($aiMetadata['published_year'])) {
                        $aiMetadata[$key] = $value;
                    }
                } elseif (empty($aiMetadata[$key])) {
                    $aiMetadata[$key] = $value;
                }
            }

            // Restore preserved cover if it was set
            if ($preservedCover) {
                $aiMetadata['cover_url'] = $preservedCover;
                $aiMetadata['cover_source'] = $preservedSource;
                if ($preservedIsLocalFile) {
                    $aiMetadata['cover_is_local_file'] = true;
                }
            } elseif ($preservedSource === 'Embedded in M4B') {
                // For embedded covers, just restore the source flag
                // The cover_data is already in $aiMetadata
                $aiMetadata['cover_source'] = $preservedSource;
            }

            // Prefer Audible cover over Google Books when no local/embedded cover exists
            $hasLocalCover = !empty($aiMetadata['cover_is_local_file']) || !empty($aiMetadata['cover_data']);
            $audibleCoverUrl = $aiMetadata['audible_raw']['coverImageUrl'] ?? null;
            $googleCoverUrl = $aiMetadata['google_books_raw']['volumeInfo']['imageLinks']['thumbnail'] ?? null;

            if (!$hasLocalCover && $audibleCoverUrl) {
                if (!empty($aiMetadata['cover_url']) && $aiMetadata['cover_url'] !== $audibleCoverUrl) {
                    $aiMetadata['fallback_cover_url'] = $aiMetadata['cover_url'];
                    $aiMetadata['fallback_cover_source'] = $aiMetadata['cover_source'] ?? 'Unknown';
                }

                $aiMetadata['cover_url'] = $audibleCoverUrl;
                $aiMetadata['cover_source'] = 'Audible';
                unset($aiMetadata['cover_is_local_file']);
            } elseif (!$hasLocalCover && !$audibleCoverUrl && $googleCoverUrl && empty($aiMetadata['cover_url'])) {
                $aiMetadata['cover_url'] = $googleCoverUrl;
                $aiMetadata['cover_source'] = 'Google Books';
            }

            $totalDuration = round((microtime(true) - $startTime) * 1000);
            $this->info("✅ Found enrichment data!");
            if ($this->isOptionEnabled('verbose')) {
                $this->line("  ⏱️  Total enrichment took: {$totalDuration}ms");
            }
        } else {
            $this->warn("⚠️  Invalid enrichment data - skipping merge.");
        }
    }

    /**
     * Inherit primary genre from existing books in the same series by the same author
     * Falls back to genre from any book by the same author if no series match found
     */
    protected function inheritGenreFromSeries(array &$metadata): void
    {
        // Skip if genre is already set to something other than generic genres
        $currentGenre = is_array($metadata['genre']) ? ($metadata['genre'][0] ?? '') : ($metadata['genre'] ?? '');
        $normalizedGenre = $this->normalizeGenreName($currentGenre);

        $genericGenres = ['General Fiction', 'Fiction', 'Other'];
        if (!empty($currentGenre) && !in_array($normalizedGenre, $genericGenres)) {
            if ($this->isOptionEnabled('verbose')) {
                $this->line("  Skipping genre inheritance - already have specific genre: {$currentGenre}");
            }
            return;
        }

        // Only proceed if we have author
        if (empty($metadata['author'])) {
            return;
        }

        $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
        $seriesName = $metadata['series'] ?? null;

        if ($this->isOptionEnabled('verbose')) {
            if ($seriesName) {
                $this->line("  Looking for genre from series '{$seriesName}' by " . implode(', ', $authors));
            } else {
                $this->line("  Looking for genre from other books by " . implode(', ', $authors));
            }
        }

        $existingBook = null;

        // First, try to find books in the same series by this author
        if ($seriesName) {
            $existingBook = Book::whereHas('series', function ($query) use ($seriesName) {
                $query->where('name', $seriesName);
            })->whereHas('authors', function ($query) use ($authors) {
                $query->whereIn('name', $authors);
            })->with('genres')->first();

            if ($existingBook && $this->isOptionEnabled('verbose')) {
                $this->line("  Found series book ID {$existingBook->id}: {$existingBook->title}");
            }
        }

        // If no series match, try to find ANY book by this author
        if (!$existingBook) {
            $existingBook = Book::whereHas('authors', function ($query) use ($authors) {
                $query->whereIn('name', $authors);
            })->with('genres')->first();

            if ($existingBook && $this->isOptionEnabled('verbose')) {
                $this->line("  Found author book ID {$existingBook->id}: {$existingBook->title}");
            }
        }

        if ($existingBook && $existingBook->genres->isNotEmpty()) {
            // Get primary genre, or first genre if no primary is set
            $primaryGenre = $existingBook->genres->where('pivot.is_primary', true)->first();
            if (!$primaryGenre) {
                // If no primary genre, use the first one (if there's only one, it's implicitly primary)
                $primaryGenre = $existingBook->genres->first();
            }

            if ($primaryGenre) {
                $primaryGenreName = $primaryGenre->name;
                $oldGenre = is_array($metadata['genre']) ? ($metadata['genre'][0] ?? '') : ($metadata['genre'] ?? '');

                if ($this->isOptionEnabled('verbose')) {
                    $this->line("  Primary genre from existing book: {$primaryGenreName}, Current genre: {$oldGenre}");
                }

                // Only inherit if the old genre was generic or empty
                $normalizedOld = $this->normalizeGenreName($oldGenre);
                $genericGenres = ['General Fiction', 'Fiction', 'Other'];
                if (empty($oldGenre) || in_array($normalizedOld, $genericGenres)) {
                    $metadata['genre'] = $primaryGenreName;
                    $inheritSource = $seriesName ? "series" : "author's other books";
                    $this->info("  ✓ Inherited genre '{$primaryGenreName}' from {$inheritSource} (was: '{$oldGenre}')");
                }
            }
        } elseif ($this->isOptionEnabled('verbose')) {
            if ($existingBook) {
                $this->line("  Existing book has no genres");
            } else {
                $this->line("  No existing books found by this author");
            }
        }
    }

    /**
     * Normalize genre name for comparison
     */
    protected function normalizeGenreName(string $genre): string
    {
        $genre = trim($genre);
        // Remove common variations
        $genre = preg_replace('/\s+/', ' ', $genre);
        return $genre;
    }

    /**
     * Handle manual review process or auto mode validation
     */
    protected function handleManualReview(array &$aiMetadata, array $audiobook): bool
    {
        if (!$this->option('auto') && !$this->option('dry-run')) {
            if (!$this->reviewAndApprove($aiMetadata, $audiobook)) {
                $this->warn("❌ Import rejected by user");
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'Rejected by user',
                ];
                return false;
            }
        } elseif ($this->option('auto') && !$this->getEnrichmentService()->hasEnrichmentData($aiMetadata)) {
            // In auto mode, skip books with no enrichment data as the detected fields might be wrong
            $this->warn("⚠️  No enrichment data found in auto mode - skipping (detected fields might be incorrect)");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'No enrichment data in auto mode',
            ];
            return false;
        }

        return true;
    }

    /**
     * Perform database import and file operations
     */
    protected function performDatabaseImport(array $aiMetadata, array $audiobook): void
    {
        if ($this->isOptionEnabled('verbose')) {
            $this->line("DEBUG: performDatabaseImport called for: " . ($aiMetadata['title'] ?? 'UNKNOWN'));
            $this->line("DEBUG: is_split_book = " . ($audiobook['is_split_book'] ?? 'NOT SET'));
        }

        if ($this->option('dry-run')) {
            $this->info("🔍 [DRY RUN] Would import: {$aiMetadata['title']}");
            return;
        }

        $spinner = $this->output->createProgressBar();
        $spinner->setFormat(" %message%");
        $spinner->setMessage("💾 Creating database record...");
        $spinner->start();

        // Add batch_id to metadata
        $aiMetadata['batch_id'] = $this->batchId;

        // CRITICAL: Check if a book already exists at TARGET path (destination)
        // If so, update it instead of creating a duplicate
        $targetPath = $aiMetadata['custom_directory_path'] ?? null;
        $existingBookAtPath = null;

        // Check target path only - this is where the book will be moved to
        if ($targetPath) {
            $existingBookAtPath = Book::where('directory_path', $targetPath)->first();
        }

        if ($existingBookAtPath) {
            $this->warn("⚠️  Book already exists: ID {$existingBookAtPath->id} - \"{$existingBookAtPath->title}\"");
            $this->warn("   Current path: {$existingBookAtPath->directory_path}");
            $this->warn("   New data: \"{$aiMetadata['title']}\"");

            $choice = $this->choice(
                'A book already exists. What would you like to do?',
                [
                    '1' => 'Update existing book with new metadata',
                    '2' => 'Skip this import',
                    '3' => 'Create new book anyway (will have duplicate!)',
                ],
                '1'
            );

            // Laravel's choice() returns the VALUE (description) not the KEY (number)
            if ($choice === 'Skip this import') {
                $this->info("⏭️  Skipping import");
                return;
            } elseif ($choice === 'Create new book anyway (will have duplicate!)') {
                $existingBookAtPath = null; // Create new book
            }
        }

        try {
            if ($existingBookAtPath) {
                // Update existing book
                $book = $this->getImportService()->updateBookFromMetadata($existingBookAtPath, $aiMetadata, $audiobook);
                $this->info("✓ Updated existing book record");
            } else {
                // Create new book
                $book = $this->getImportService()->createBookFromMetadata($aiMetadata, $audiobook);
            }
        } catch (\Exception $e) {
            $book = null;
            $spinner->finish();
            $this->output->write("\r\033[K");
            $this->error("❌ Exception during book creation: " . $e->getMessage());
            $this->error("   File: " . $e->getFile() . ":" . $e->getLine());
            $this->error("   Trace: " . $e->getTraceAsString());

            // Show metadata that was being used
            $this->error("   Metadata being used:");
            $this->error("   - Title: " . ($aiMetadata['title'] ?? 'NULL'));
            $this->error("   - Author: " . (is_array($aiMetadata['author'] ?? null) ? implode(', ', $aiMetadata['author']) : ($aiMetadata['author'] ?? 'NULL')));
            $this->error("   - Directory: " . ($aiMetadata['custom_directory_path'] ?? 'NULL'));
        }

        if (!isset($book)) {
            $spinner->finish();
            $this->output->write("\r\033[K");
        }

        if ($this->isOptionEnabled('verbose')) {
            $this->line("DEBUG: Book created: " . ($book ? "YES (ID: {$book->id})" : "NO - FAILED"));

            if (!$book) {
                // Check Laravel logs for more details
                $this->line("DEBUG: Check storage/logs/laravel.log for detailed error information");
            }
        }

        if (!$book) {
            // Book creation failed - likely because it already exists
            $this->warn("⚠️  Book creation failed - checking if book already exists...");

            // Try to find existing book
            $existingBook = $this->findExistingBookForRestore($aiMetadata);

            if ($existingBook) {
                $this->info("Found existing book: '{$existingBook->title}' (ID: {$existingBook->id})");

                // Check if files exist
                $bookStoragePath = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
                // Handle both absolute and relative paths
                if (str_starts_with($existingBook->directory_path, '/')) {
                    $existingDir = $existingBook->directory_path;
                } else {
                    $existingDir = $bookStoragePath . '/' . $existingBook->directory_path;
                }

                if (!File::isDirectory($existingDir) || $this->isDirectoryEmpty($existingDir)) {
                    $this->warn("📁 Existing book has missing or empty directory");
                    $this->line("  Expected: {$existingDir}");
                    $this->info("Will merge metadata and restore files to existing book record");

                    $book = $this->mergeAndRestoreBook($existingBook, $aiMetadata, $audiobook);
                } else {
                    $this->warn("Existing book has files - cannot import duplicate");
                    $this->line("  Directory: {$existingDir}");

                    if ($this->confirm("Do you want to overwrite the existing files?", false)) {
                        $book = $this->mergeAndRestoreBook($existingBook, $aiMetadata, $audiobook);
                    } else {
                        $this->info("Skipping - existing files preserved");
                        return;
                    }
                }
            } else {
                $this->error("❌ Failed to create book record and no existing book found");
                return;
            }
        }

        $this->info("✅ Book imported successfully: {$book->title} (ID: {$book->id})");

        // Step 5: Move/copy files
        // CRITICAL: Use the directory path from the APPROVED metadata, not recalculated from book
        // This ensures files go exactly where the user approved
        $bookStoragePath = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $approvedBasePath = $this->getImportService()->generateDirectoryPath($aiMetadata);
        $title = $aiMetadata['title'];

        // Add series number prefix to title if present
        if (!empty($aiMetadata['series_number'])) {
            $formattedNumber = str_pad($aiMetadata['series_number'], 2, '0', STR_PAD_LEFT);
            $title = $formattedNumber . ' ' . $title;
        }

        $approvedTargetDir = $bookStoragePath . '/' . $approvedBasePath . '/' . $title;

        // For split books, we need special handling since we only want to move one file
        if (!empty($audiobook['is_split_book'])) {
            $this->info("📦 Moving split book files...");
            $this->line("  Source file: " . ($audiobook['files'][0] ?? 'NONE'));
            $this->line("  Is split book: " . ($audiobook['is_split_book'] ? 'YES' : 'NO'));

            $moveStartTime = microtime(true);
            $success = $this->moveSplitBookFiles($audiobook, $book, $aiMetadata);
            $moveDuration = round((microtime(true) - $moveStartTime) * 1000);

            if ($this->isOptionEnabled('verbose')) {
                $this->line("  ⏱️  File move took: {$moveDuration}ms");
            }
        } else {
            $fileCount = count($audiobook['files'] ?? []);
            $totalSize = $this->getFileSystemService()->formatBytes($audiobook['total_size'] ?? 0);
            $operation = $this->option('copy-files') ? 'Copying' : 'Moving';

            $this->info("📦 {$operation} {$fileCount} files ({$totalSize})...");

            $moveStartTime = microtime(true);
            $options = [
                'operation' => $this->option('copy-files') ? 'copy' : 'move',
                'target_directory' => $approvedTargetDir  // Use approved path, not recalculated
            ];
            $success = $this->getImportService()->moveFilesToLibrary($audiobook, $book, $options);
            $moveDuration = round((microtime(true) - $moveStartTime) * 1000);

            if ($this->isOptionEnabled('verbose')) {
                $this->line("  ⏱️  File move took: {$moveDuration}ms");
            }
        }

        if ($success) {
            $this->info("📁 Files " . ($this->option('copy-files') ? 'copied' : 'moved') . " to library successfully");

            // Check for cover image in the destination directory and update book if found
            $bookStoragePath = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
            // Handle both absolute and relative paths
            if (str_starts_with($book->directory_path, '/')) {
                $destinationDir = $book->directory_path;
            } else {
                $destinationDir = $bookStoragePath . '/' . $book->directory_path;
            }
            $coverImage = $this->findExistingCoverImage($destinationDir);

            if ($coverImage && empty($book->cover_image)) {
                // Convert absolute path to relative path for database storage
                $relativeCoverPath = str_replace($bookStoragePath . '/', '', $coverImage);
                $book->cover_image = $relativeCoverPath;
                $book->save();
                $this->info("  ✓ Found and set cover image: " . basename($coverImage));
            }

            $this->processedBooks[] = [
                'path' => $audiobook['path'],
                'book_id' => $book->id,
                'title' => $book->title,
            ];
        } else {
            $this->error("❌ Failed to " . ($this->option('copy-files') ? 'copy' : 'move') . " files to library");

            // Get the last error from logs for more details
            $this->displayFileOperationError($audiobook, $book);

            // Clean up the book record since file operation failed
            $this->cleanupFailedBookImport($book);

            // Move to failed books array instead of processed
            $this->failedBooks[] = [
                'path' => $audiobook['path'],
                'error' => 'File operation failed - book record cleaned up',
            ];
        }
    }

    /**
     * Process audiobook with AI
     */
    protected function displayEnrichedMetadata(array &$metadata): void
    {
        // Helper function to convert arrays to strings
        $arrayToString = function ($value) {
            if (is_array($value)) {
                // Filter out nested arrays and objects, then convert to string
                $filtered = array_filter($value, function ($v) {
                    return !is_array($v) && !is_object($v) && $v !== null && $v !== '';
                });
                return implode(', ', $filtered);
            }
            return $value ?? 'N/A';
        };

        // Helper function specifically for authors (uses & separator)
        $formatAuthors = function ($authors) {
            if (is_array($authors)) {
                $filtered = array_filter($authors, function ($v) {
                    return !is_array($v) && !is_object($v) && $v !== null && $v !== '';
                });

                // Process each author name to handle GraphicAudio format
                $processedAuthors = array_map(function ($author) {
                    // Check for GraphicAudio [Author1 / Author2] format
                    if (preg_match('/^Graphic\s*Audio\s*\[([^\]]+)\]$/i', $author, $matches)) {
                        $authors = array_map('trim', explode('/', $matches[1]));
                        return implode(', ', $authors);
                    }
                    return $author;
                }, $filtered);

                return implode(' & ', $processedAuthors);
            }

            // Handle case where $authors is a string
            if (is_string($authors)) {
                if (preg_match('/^Graphic\s*Audio\s*\[([^\]]+)\]$/i', $authors, $matches)) {
                    $authors = array_map('trim', explode('/', $matches[1]));
                    return implode(' & ', $authors);
                }
                return $authors;
            }

            return 'N/A';
        };

        // Clean series name for display
        $displaySeries = '';
        $displayCollection = 'No';
        if (!empty($metadata['series'])) {
            $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
            $cleanedSeriesName = $this->getEnrichmentService()->cleanSeriesName($metadata['series'], $authors);
            $displaySeries = $cleanedSeriesName . (!empty($metadata['series_number']) ? " #{$metadata['series_number']}" : '');
        }

        // Check for collection (stored separately from primary series)
        if (!empty($metadata['collection'])) {
            $displayCollection = $metadata['collection'];
            if (!empty($metadata['collection_number'])) {
                $displayCollection .= " #{$metadata['collection_number']}";
            }
        }

        // Build the basic metadata table
        $tableData = [
            ['Title', $arrayToString($metadata['title'])],
            ['Author', $formatAuthors($metadata['author'])],
            ['Narrator', $arrayToString($metadata['narrator'] ?? null)],
            ['Series', $displaySeries],
            ['Collection', $displayCollection],
            ['Genre', $arrayToString($metadata['genre'])],
            ['Year', $metadata['year'] ?? 'N/A'],
            ['Publisher', $arrayToString($metadata['publisher'] ?? null)],
            ['Language', $metadata['language'] ?? 'N/A'],
            ['ISBN', $metadata['isbn'] ?? 'N/A'],
            ['Confidence', $metadata['confidence'] . '%'],
        ];

        // Add source and directory paths
        if (!empty($metadata['source_path'])) {
            $displayPath = $this->formatSourcePathForDisplay($metadata['source_path']);
            $tableData[] = ['Source Path', $displayPath];
        }

        // CRITICAL: Calculate and store the target directory path FIRST
        // This is THE ONLY target path - what's stored is what's displayed
        $dirPathStartTime = microtime(true);
        $basePath = $this->getImportService()->generateDirectoryPath($metadata);
        $dirPathDuration = round((microtime(true) - $dirPathStartTime) * 1000);
        if ($this->isOptionEnabled('verbose') && $dirPathDuration > 100) {
            $this->line("  ⏱️  Directory path generation took: {$dirPathDuration}ms");
        }
        $title = $metadata['title'] ?? 'Unknown Title';

        // If we have a series number, prefix it to the title
        if (!empty($metadata['series_number'])) {
            $formattedNumber = str_pad($metadata['series_number'], 2, '0', STR_PAD_LEFT);
            $title = $formattedNumber . ' ' . $title;
        }

        // Check if custom directory path already includes the title
        // If it does, don't append it again to avoid duplication
        $expectedPath = $basePath;
        if (!str_ends_with($basePath, $title) && !str_ends_with($basePath, '/' . $title)) {
            $expectedPath = $basePath . '/' . $title;
        }

        // Store in metadata BEFORE displaying
        $metadata['custom_directory_path'] = $expectedPath;

        // Display exactly what will be used
        $tableData[] = ['Directory Path', $metadata['custom_directory_path']];

        // Add description if available (truncated for display)
        if (!empty($metadata['description'])) {
            $description = strlen($metadata['description']) > 80 ? substr($metadata['description'], 0, 80) . '...' : $metadata['description'];
            $tableData[] = ['Description', $description];
        }

        // Determine cover display preference: local/embedded first, fallback second
        $coverDisplayUrl = null;
        $coverDisplaySource = null;

        if (!empty($metadata['cover_data'])) {
            $coverDisplaySource = $metadata['cover_source'] ?? 'Embedded in M4B';
            $coverDisplayUrl = '(embedded cover)';
        } elseif (!empty($metadata['cover_is_local_file']) && !empty($metadata['cover_url'])) {
            $coverDisplaySource = $metadata['cover_source'] ?? 'Local file in directory';
            $coverDisplayUrl = $metadata['cover_url'];
        } elseif (!empty($metadata['cover_url'])) {
            $coverDisplaySource = $metadata['cover_source'] ?? 'Unknown';
            if (empty($metadata['cover_source'])) {
                if (isset($metadata['audible_raw'])) {
                    $coverDisplaySource = 'Audible';
                } elseif (isset($metadata['google_books_raw'])) {
                    $coverDisplaySource = 'Google Books';
                }
            }
            $coverDisplayUrl = $metadata['cover_url'];
        } elseif (!empty($metadata['fallback_cover_url'])) {
            $coverDisplaySource = $metadata['fallback_cover_source'] ?? 'Fallback source';
            $coverDisplayUrl = $metadata['fallback_cover_url'];
        }

        if ($coverDisplaySource !== null) {
            $tableData[] = ['Cover Source', $coverDisplaySource];
            if ($coverDisplayUrl && $coverDisplayUrl !== '(embedded cover)') {
                $tableData[] = ['Cover Path/URL', $coverDisplayUrl];
            }
        }

        $tableStartTime = microtime(true);
        $this->table(['Field', 'Value'], $tableData);
        $tableDuration = round((microtime(true) - $tableStartTime) * 1000);

        if ($this->isOptionEnabled('verbose')) {
            $this->line("  ⏱️  Table rendering took: {$tableDuration}ms");
        }

        // Display cover image if terminal supports it and cover is available
        // Skip image display in auto/dry-run mode to avoid hanging on terminal access
        if ((!empty($metadata['cover_url']) || !empty($metadata['cover_data'])) && !$this->option('auto') && !$this->option('dry-run')) {
            $coverStartTime = microtime(true);

            // For embedded covers, save to temp file first
            if (!empty($metadata['cover_data']) && empty($metadata['cover_url'])) {
                $tempFile = tempnam(sys_get_temp_dir(), 'cover_') . '.jpg';
                file_put_contents($tempFile, $metadata['cover_data']);
                $this->displayCoverImage($tempFile, true); // true = embedded cover
                unlink($tempFile); // Clean up temp file
            } else {
                $this->displayCoverImage($metadata['cover_url'], false);
            }

            $coverDuration = round((microtime(true) - $coverStartTime) * 1000);

            if ($this->isOptionEnabled('verbose')) {
                $this->line("  ⏱️  Cover image display took: {$coverDuration}ms");
            }
        } elseif (!empty($metadata['cover_url']) || !empty($metadata['cover_data'])) {
            // Just show the URL in auto/dry-run mode (or indicate embedded cover)
            if (!empty($metadata['cover_data'])) {
                $this->line("\n📸 Cover available: Embedded in M4B");
            } else {
                $this->line("\n📸 Cover available: {$metadata['cover_url']}");
            }
        }
    }

    /**
     * Ask for input with prompt on the same line
     */
    protected function askInline(string $question, string $default = ''): string
    {
        // Format the question with default value if provided
        $prompt = $question;
        if (!empty($default)) {
            $prompt .= " [<comment>{$default}</comment>]";
        }

        $this->output->write($prompt . " ");

        // Read input
        $input = trim(fgets(STDIN));

        return empty($input) ? $default : $input;
    }

    /**
     * Display cover image if terminal supports it (like Ghostty with Kitty protocol)
     */
    protected function displayCoverImage(string $imageUrl, bool $isEmbedded = false): void
    {
        $this->getTerminalImageService()->displayImage(
            $imageUrl,
            fn ($msg) => $this->line($msg),
            'left', // align
            $isEmbedded ? 'Embedded' : null // displayName
        );
    }

    /**
     * Manual review and approval
     */
    protected function reviewAndApprove(array &$metadata, array $audiobook = []): bool
    {
        // If no enrichment data found, skip auto-approval and go straight to manual review
        if (!$this->getEnrichmentService()->hasEnrichmentData($metadata)) {
            // REMOVED: Useless warning about no enrichment data
            // File tags are authoritative - enrichment is optional, not required
            $this->info("📝 Please review and edit the metadata:");
        } else {
            // Ask if user wants to accept all fields as shown
            $this->line("\nOptions:");
            $this->line("1. (A)ccept all metadata as shown");
            $this->line("2. (E)dit individual fields");
            $this->line("3. (P)ath - edit directory path only");
            $this->line("4. (C)over - edit cover image URL only");
            $this->line("5. (G)enre - change genre only");
            $this->line("6. (S)kip this book");

            // Default logic:
            // - If genre is "General Fiction", default to genre change (5)
            // - Otherwise, if confidence > 80%, default to accept (1)
            // - Otherwise, default to edit (2)
            $confidence = $metadata['confidence'] ?? 0;
            $currentGenre = is_array($metadata['genre']) ? ($metadata['genre'][0] ?? '') : ($metadata['genre'] ?? '');
            $normalizedGenre = $this->normalizeGenreName($currentGenre);

            $genericGenres = ['General Fiction', 'Fiction', 'Other'];
            if (in_array($normalizedGenre, $genericGenres)) {
                $defaultChoice = '5';  // Change genre
                $confidenceNote = " (genre is generic - consider changing)";
            } elseif ($confidence > 80) {
                $defaultChoice = '1';  // Accept
                $confidenceNote = " (high confidence: {$confidence}%)";
            } else {
                $defaultChoice = '2';  // Edit
                $confidenceNote = " (confidence: {$confidence}%)";
            }

            // Prepare background tasks for potential next books
            $backgroundTasks = [
                ['type' => 'scan_directory', 'data' => $audiobook],
                ['type' => 'duplicate_check', 'data' => $audiobook],
            ];

            $choice = $this->askWithBackground("Choose an option (1-6)", $defaultChoice, $backgroundTasks);

            // Normalize choice to handle letters
            $choice = strtolower(trim($choice));
            if (in_array($choice, ['a', 'accept'])) {
                $choice = '1';
            }
            if (in_array($choice, ['e', 'edit'])) {
                $choice = '2';
            }
            if (in_array($choice, ['p', 'path'])) {
                $choice = '3';
            }
            if (in_array($choice, ['c', 'cover'])) {
                $choice = '4';
            }
            if (in_array($choice, ['g', 'genre'])) {
                $choice = '5';
            }
            if (in_array($choice, ['s', 'skip'])) {
                $choice = '6';
            }

            $validChoices = ['1', '2', '3', '4', '5', '6'];
            while (!in_array($choice, $validChoices, true)) {
                $choice = strtolower(trim($this->ask("Invalid option. Please choose 1-6 (or press Enter for default {$defaultChoice}{$confidenceNote}):")));
                if ($choice === '') {
                    $choice = $defaultChoice;
                    break;
                }
                if (in_array($choice, ['a', 'accept'], true)) {
                    $choice = '1';
                } elseif (in_array($choice, ['e', 'edit'], true)) {
                    $choice = '2';
                } elseif (in_array($choice, ['p', 'path'], true)) {
                    $choice = '3';
                } elseif (in_array($choice, ['c', 'cover'], true)) {
                    $choice = '4';
                } elseif (in_array($choice, ['g', 'genre'], true)) {
                    $choice = '5';
                } elseif (in_array($choice, ['s', 'skip'], true)) {
                    $choice = '6';
                }
            }

            switch ($choice) {
                case '1':
                    return true;
                case '2':
                    break;
                case '3':
                    $metadata = $this->editDirectoryPathOnly($metadata, $audiobook);
                    if ($this->inputInterrupted) {
                        return false;
                    }
                    return true;
                case '4':
                    $metadata = $this->editCoverImageOnly($metadata, $audiobook);
                    if ($this->inputInterrupted) {
                        return false;
                    }
                    return true;
                case '5':
                    $metadata = $this->editGenreOnly($metadata, $audiobook);
                    if ($this->inputInterrupted) {
                        return false;
                    }
                    return true;
                case '6':
                    return false;
            }
        }

        // Offer individual field editing
        $this->info("📝 Edit individual fields (press Enter to keep current value):");
        $metadata = $this->editMetadataFields($metadata, $audiobook);
        if ($this->inputInterrupted) {
            return false;
        }

        // Show updated metadata with new directory path
        $metadata['source_path'] = $audiobook['path'] ?? '';
        $expectedPath = $this->getImportService()->generateDirectoryPath($metadata);
        $this->newLine();
        $this->info("📁 Updated directory path: {$expectedPath}");
        $this->displayEnrichedMetadata($metadata);
        $this->newLine();

        // REMOVED: Do NOT attempt enrichment after user has edited metadata
        // User edits are final - enrichment should only happen BEFORE user interaction

        // Ask for final confirmation with option to re-edit
        while (true) {
            $this->line("\nOptions:");
            $this->line("1. (A)ccept all metadata as shown");
            $this->line("2. (E)dit individual fields");
            $this->line("3. (P)ath - edit directory path only");
            $this->line("4. (S)kip this book");

            $choice = $this->askWithImmediateInterrupt("Choose an option (1-4):", '1');

            // Handle quit request or interruption
            if (strtolower(trim($choice)) === 'q' || $this->inputInterrupted) {
                $this->handleUserQuit();
                return false;
            }

            // Normalize choice to handle first letters
            $choice = strtolower(trim($choice));
            if ($choice === 'a' || $choice === 'accept') {
                $choice = '1';
            }
            if ($choice === 'e' || $choice === 'edit') {
                $choice = '2';
            }
            if ($choice === 'p' || $choice === 'path') {
                $choice = '3';
            }
            if ($choice === 's' || $choice === 'skip') {
                $choice = '4';
            }

            $validFinalChoices = ['1', '2', '3', '4'];
            while (!in_array($choice, $validFinalChoices, true)) {
                $choice = strtolower(trim($this->ask("Invalid option. Please choose 1-4 (or press Enter for default 1):")));
                if ($choice === '') {
                    $choice = '1';
                    break;
                }
                if ($choice === 'a' || $choice === 'accept') {
                    $choice = '1';
                } elseif ($choice === 'e' || $choice === 'edit') {
                    $choice = '2';
                } elseif ($choice === 'p' || $choice === 'path') {
                    $choice = '3';
                } elseif ($choice === 's' || $choice === 'skip') {
                    $choice = '4';
                }
            }

            switch ($choice) {
                case '1':
                    return true;
                case '2':
                    // Re-edit metadata - call the editing section again
                    $this->info("📝 Edit individual fields again (press Enter to keep current value):");
                    $metadata = $this->editMetadataFields($metadata, $audiobook);

                    // Show updated metadata with new directory path
                    $metadata['source_path'] = $audiobook['path'] ?? '';
                    $expectedPath = $this->getImportService()->generateDirectoryPath($metadata);
                    $this->newLine();
                    $this->info("📁 Updated directory path: {$expectedPath}");
                    $this->displayEnrichedMetadata($metadata);
                    $this->newLine();

                    // REMOVED: Do NOT attempt enrichment after user has re-edited metadata
                    // User edits are final - enrichment should only happen BEFORE user interaction
                    // Continue the loop to ask again
                    break;
                case '3':
                    // Edit directory path only
                    $metadata = $this->editDirectoryPathOnly($metadata, $audiobook);
                    if ($this->inputInterrupted) {
                        return false;
                    }
                    return true;
                case '4':
                    return false;
                default:
                    $this->warn("Please choose 1-4, or use first letters (A/E/P/S)");
            }
        }
    }

    /**
     * Edit metadata fields interactively
     */
    protected function editMetadataFields(array $metadata, array $audiobook): array
    {
        // Edit title
        $newTitle = $this->askWithImmediateInterrupt("Title", $metadata['title'] ?? '');
        if ($this->inputInterrupted) {
            return $metadata;
        }
        // Always update title, even if it appears unchanged (trim might differ)
        $metadata['title'] = trim($newTitle);

        // Get the author display value from the summary formatting
        $formatAuthors = function ($authors) {
            if (is_array($authors)) {
                $filtered = array_filter($authors, function ($v) {
                    return !is_array($v) && !is_object($v) && $v !== null && $v !== '';
                });

                // Process each author name to handle GraphicAudio format
                $processedAuthors = array_map(function ($author) {
                    // Check for GraphicAudio [Author1 / Author2] format
                    if (preg_match('/^Graphic\s*Audio\s*\[([^\]]+)\]$/i', $author, $matches)) {
                        $authors = array_map('trim', explode('/', $matches[1]));
                        return implode(', ', $authors);
                    }
                    return $author;
                }, $filtered);

                return implode(' & ', $processedAuthors);
            }
            return $authors ?? '';
        };

        $currentAuthor = $formatAuthors($metadata['author'] ?? '');
        $newAuthor = $this->askWithImmediateInterrupt("Author(s)", $currentAuthor);
        if ($this->inputInterrupted) {
            return $metadata;
        }
        // Update author, converting back from display format if needed
        if (str_contains($newAuthor, ' & ')) {
            $metadata['author'] = array_map('trim', explode(' & ', $newAuthor));
        } else {
            $metadata['author'] = [$newAuthor];
        }

        // Edit narrator
        $currentNarrator = '';
        if (isset($metadata['narrator'])) {
            $currentNarrator = is_array($metadata['narrator']) ? implode(', ', $metadata['narrator']) : $metadata['narrator'];
        }
        $newNarrator = $this->askWithImmediateInterrupt("Narrator(s) (comma-separated)", $currentNarrator);
        if ($this->inputInterrupted) {
            return $metadata;
        }
        // Always update narrator
        if (!empty($newNarrator)) {
            $metadata['narrator'] = array_map('trim', explode(',', $newNarrator));
        }

        // Edit genre
        $currentGenre = is_array($metadata['genre']) ? implode(', ', $metadata['genre']) : ($metadata['genre'] ?? '');
        $newGenre = $this->askWithImmediateInterrupt("Genre", $currentGenre);
        if ($this->inputInterrupted) {
            return $metadata;
        }
        // Always update genre
        $metadata['genre'] = $newGenre;

        // Edit series name
        $currentSeries = $metadata['series'] ?? '';
        $newSeries = $this->askWithImmediateInterrupt("Series", $currentSeries);
        if ($this->inputInterrupted) {
            return $metadata;
        }
        $metadata['series'] = trim($newSeries) === '' ? null : trim($newSeries);

        // Edit series number if series is set
        if (!empty($metadata['series'])) {
            $currentSeriesNumber = $metadata['series_number'] ?? '';
            $newSeriesNumber = $this->askWithImmediateInterrupt("Series Number", $currentSeriesNumber);
            if ($this->inputInterrupted) {
                return $metadata;
            }
            $metadata['series_number'] = trim($newSeriesNumber) === '' ? null : trim($newSeriesNumber);
        } else {
            $metadata['series_number'] = null;
        }

        // Edit year
        $currentYear = $metadata['year'] ?? '';
        $newYear = $this->askWithImmediateInterrupt("Year", $currentYear);
        if ($this->inputInterrupted) {
            return $metadata;
        }
        // Always update year
        $metadata['year'] = $newYear;

        // CRITICAL: Clear custom_directory_path so it regenerates from edited metadata
        // Otherwise it will just return the old cached path
        unset($metadata['custom_directory_path']);

        // Regenerate directory path using the NEWLY EDITED metadata
        // This ensures the path reflects all the changes the user just made
        $currentPath = $this->getImportService()->generateDirectoryPath($metadata, ['include_title' => true]);

        // Edit directory path (user can override the generated path if needed)
        $newPath = $this->askWithImmediateInterrupt("Directory Path (relative to library root)", $currentPath);
        if ($this->inputInterrupted) {
            return $metadata;
        }

        // Always set custom_directory_path if any metadata was edited
        // This ensures the path with edited title/author/series is preserved
        $newPath = trim($newPath);
        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        if (str_starts_with($newPath, $bookRoot . '/')) {
            $newPath = substr($newPath, strlen($bookRoot) + 1);
        } elseif (str_starts_with($newPath, $bookRoot)) {
            $newPath = substr($newPath, strlen($bookRoot) + 1);
        }
        $metadata['custom_directory_path'] = $newPath;

        // CRITICAL: Do NOT extract series number from title after user has manually edited
        // The user's approved title should be preserved exactly as entered
        // If they wanted "Spacers Part 5" as the title, that's what it should be

        return $metadata;
    }

    /**
     * Detect multi-book directory patterns like "Series [2-3]" or "Series [1-4]"
     */
    /**
     * Clean series name by removing author names if present
     */
    protected function getEnrichmentService(): BookEnrichmentService
    {
        if (!$this->enrichmentService) {
            $this->enrichmentService = app(BookEnrichmentService::class);
        }
        return $this->enrichmentService;
    }

    /**
     * Get import service instance
     */
    protected function getImportService(): BookImportService
    {
        if (!$this->importService) {
            $this->importService = app(BookImportService::class);
        }
        return $this->importService;
    }

    /**
     * Get background processing service instance
     */
    protected function getBackgroundService(): BackgroundProcessingService
    {
        if (!$this->backgroundService) {
            $this->backgroundService = new BackgroundProcessingService();
        }
        return $this->backgroundService;
    }

    /**
     * Get cache service instance
     */
    protected function getCacheService(): ?ImportCacheService
    {
        if (!$this->cacheService) {
            // Disable caching in testing environment to prevent filesystem issues
            if (App::runningUnitTests()) {
                return null;
            }

            $options = [
                'enabled' => $this->cacheEnabled,
                'cache_file' => $this->cacheFilePath ?? storage_path('app/import_cache.json')
            ];
            // Only resolve Filesystem from container if not in testing environment
            $filesystem = App::runningUnitTests() ? null : app(\Illuminate\Filesystem\Filesystem::class);
            $this->cacheService = new ImportCacheService($filesystem, $options);
        }
        return $this->cacheService;
    }

    /**
     * Get metadata processing service instance
     */
    protected function getMetadataService(): MetadataProcessingService
    {
        if (!$this->metadataService) {
            $this->metadataService = app(MetadataProcessingService::class);
        }
        return $this->metadataService;
    }

    /**
     * Get file system service instance
     */
    protected function getFileSystemService(): FileSystemService
    {
        if (!$this->fileSystemService) {
            $this->fileSystemService = app(FileSystemService::class);
        }
        return $this->fileSystemService;
    }

    /**
     * Get terminal image service instance
     */
    protected function getTerminalImageService(): TerminalImageService
    {
        if (!$this->terminalImageService) {
            $this->terminalImageService = app(TerminalImageService::class);
        }
        return $this->terminalImageService;
    }

    /**
     * Get directory parser service instance
     */
    protected function getDirectoryParser(): BookDirectoryParser
    {
        if (!$this->directoryParser) {
            $this->directoryParser = app(BookDirectoryParser::class);
        }

        return $this->directoryParser;
    }

    /**
     * Get audio file analyzer instance
     */
    protected function getAudioAnalyzer(): AudioFileAnalyzer
    {
        if (!$this->audioAnalyzer) {
            $this->audioAnalyzer = app(AudioFileAnalyzer::class);
        }
        return $this->audioAnalyzer;
    }

    /**
     * Get AI processor instance
     */
    protected function getAIProcessor(): AIBookProcessor
    {
        if (!$this->aiProcessor) {
            $this->aiProcessor = app(AIBookProcessor::class);
        }
        return $this->aiProcessor;
    }

    /**
     * Get OpenAudibleParser instance
     */
    protected function getOpenAudibleParser(): OpenAudibleParser
    {
        if (!$this->openAudibleParser) {
            $this->openAudibleParser = app(OpenAudibleParser::class);
        }
        return $this->openAudibleParser;
    }

    /**
     * Try to load OpenAudible books.json if present
     */
    protected function loadOpenAudibleData(string $path): void
    {
        $checkPath = $path;
        $maxDepth = 5;
        $depth = 0;

        while ($depth < $maxDepth) {
            $booksJsonPath = $checkPath . '/books.json';

            if (File::exists($booksJsonPath)) {
                try {
                    $this->openAudibleRootPath = $checkPath;
                    $this->openAudibleBooksData = $this->getOpenAudibleParser()->loadBooksJson($checkPath);
                    $this->line("  📚 Loaded OpenAudible books.json with " . count($this->openAudibleBooksData) . " books");
                    return;
                } catch (\Exception $e) {
                    Log::warning("Failed to load OpenAudible books.json: " . $e->getMessage());
                }
            }

            $parent = dirname($checkPath);
            if ($parent === $checkPath) {
                break;
            }
            $checkPath = $parent;
            $depth++;
        }
    }

    /**
     * Find OpenAudible metadata for a specific book path
     */
    protected function findOpenAudibleMetadata(string $bookPath): ?array
    {
        if (empty($this->openAudibleBooksData)) {
            return null;
        }

        $bookName = basename($bookPath);

        foreach ($this->openAudibleBooksData as $bookData) {
            $expectedName = $this->getOpenAudibleParser()->getBookDirectoryName($bookData);

            if ($expectedName === $bookName || $bookData['title'] === $bookName) {
                $normalized = $this->getOpenAudibleParser()->normalizeBookData($bookData);
                $this->line("  📖 Found OpenAudible metadata for: {$bookName}");
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Fix Graphic Audio metadata by extracting real author from M4B copyright field
     */
    protected function fixGraphicAudioMetadata(array &$aiMetadata, array $audiobook): void
    {
        // Check if this is a Graphic Audio book by:
        // 1. Author field contains "Graphic Audio"
        // 2. Directory/filename contains "GraphicAudio" or "(GraphicAudio)"
        $author = '';
        if (!empty($aiMetadata['author'])) {
            $author = is_array($aiMetadata['author']) ? ($aiMetadata['author'][0] ?? '') : $aiMetadata['author'];
        }
        $path = $audiobook['path'] ?? '';

        $isGraphicAudio = stripos($author, 'Graphic Audio') !== false ||
            stripos($path, 'GraphicAudio') !== false ||
            stripos($path, 'Graphic Audio') !== false;

        if (!$isGraphicAudio) {
            return; // Not a Graphic Audio book
        }

        $this->line("  🎭 Detected Graphic Audio book - extracting real author...");

        // Try to extract author from M4B file metadata (cached)
        if (!empty($audiobook['files'][0])) {
            try {
                $fileTags = $this->getCachedFileTags($audiobook['files'][0]);

                // Check copyright field (e.g., " 2024 by Brandon Sanderson")
                if (!empty($fileTags['copyright'])) {
                    $copyright = is_array($fileTags['copyright']) ? $fileTags['copyright'][0] : $fileTags['copyright'];
                    if (preg_match('/\bby\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/i', $copyright, $matches)) {
                        $aiMetadata['author'] = [trim($matches[1])];
                        $aiMetadata['publisher'] = 'GraphicAudio';
                        $this->info("  ✓ Extracted author from copyright: {$aiMetadata['author'][0]}");
                        return;
                    }
                }
            } catch (\Exception $e) {
                // Continue to fallback
            }
        }

        // Set publisher even if we couldn't extract author
        $aiMetadata['publisher'] = 'GraphicAudio';
        $this->warn("  ✗ Could not extract real author - keeping 'Graphic Audio'");

        // Clear narrator field for Graphic Audio (they have huge casts that clutter the display)
        if (!empty($aiMetadata['narrator'])) {
            $this->line("  ℹ Clearing narrator field (Graphic Audio has large casts)");
            $aiMetadata['narrator'] = [];
        }

        // Clean up Graphic Audio title suffixes
        if (!empty($aiMetadata['title'])) {
            $originalTitle = $aiMetadata['title'];
            $aiMetadata['title'] = preg_replace('/\s*\[Dramatized Adaptation\]\s*/i', '', $aiMetadata['title']);
            $aiMetadata['title'] = preg_replace('/\s*\(Dramatized Adaptation\)\s*/i', '', $aiMetadata['title']);
            $aiMetadata['title'] = preg_replace('/\s*-\s*Dramatized Adaptation\s*/i', '', $aiMetadata['title']);
            if ($originalTitle !== $aiMetadata['title']) {
                $this->line("  ✓ Cleaned title: '{$aiMetadata['title']}'");
            }
        }
    }

    /**
     * Find existing book for restore operation
     */
    protected function findExistingBookForRestore(array $aiMetadata): ?Book
    {
        $title = $aiMetadata['title'] ?? null;
        $author = is_array($aiMetadata['author']) ? ($aiMetadata['author'][0] ?? null) : ($aiMetadata['author'] ?? null);

        if (!$title || !$author) {
            $this->line("  Cannot search: title='" . ($title ?: 'EMPTY') . "', author='" . ($author ?: 'EMPTY') . "'");
            return null;
        }

        $this->line("  Searching for: title='{$title}', author='{$author}'");

        // Try exact match first
        $book = Book::where('title', $title)
            ->whereHas('authors', function ($query) use ($author) {
                $query->where('name', $author);
            })
            ->first();

        if ($book) {
            $this->line("  Found by exact title match");
            return $book;
        }

        // Try with series info if available
        if (!empty($aiMetadata['series']) && !empty($aiMetadata['series_number'])) {
            $series = $aiMetadata['series'];
            $seriesNumber = $aiMetadata['series_number'];

            $this->line("  Trying series match: series='{$series}', number={$seriesNumber}");

            $book = Book::whereHas('authors', function ($query) use ($author) {
                $query->where('name', $author);
            })
                ->whereHas('series', function ($query) use ($series) {
                    $query->where('name', $series);
                })
                ->get()
                ->first(function ($b) use ($seriesNumber) {
                    // Check series number in the pivot
                    $bookSeries = $b->series->first();
                    return $bookSeries && $bookSeries->pivot->series_number == $seriesNumber;
                });

            if ($book) {
                $this->line("  ✓ Found by series + number match: '{$book->title}'");
                return $book;
            }
        }

        $this->line("  ✗ No existing book found");
        return null;
    }

    /**
     * Check if directory is empty
     */
    protected function isDirectoryEmpty(string $directory): bool
    {
        if (!is_dir($directory)) {
            return true;
        }

        $files = scandir($directory);
        return count($files) <= 2; // Only . and ..
    }

    /**
     * Merge metadata and restore book
     */
    protected function mergeAndRestoreBook(Book $existingBook, array $newMetadata, array $audiobook): Book
    {
        $this->info("🔄 Merging metadata with existing book...");

        // Compare and prompt for differences
        $fieldsToCheck = ['title', 'description', 'language', 'publisher', 'year', 'isbn'];

        foreach ($fieldsToCheck as $field) {
            $existingValue = $existingBook->$field;
            $newValue = $newMetadata[$field] ?? null;

            // Skip if values are the same or new value is empty
            if ($existingValue == $newValue || empty($newValue)) {
                continue;
            }

            $this->newLine();
            $this->line("Field: <info>{$field}</info>");
            $this->line("  Existing: " . ($existingValue ?: '(empty)'));
            $this->line("  New:      " . ($newValue ?: '(empty)'));

            $choice = $this->choice(
                "Which value should we use?",
                ['Keep existing', 'Use new', 'Skip (keep existing)'],
                0
            );

            if ($choice === 'Use new') {
                $existingBook->$field = $newValue;
            }
        }

        $existingBook->save();
        $this->info("✓ Metadata merged");

        return $existingBook;
    }

    /**
     * Move files for a split book (single M4B file + cover)
     *
     * @param  array  $audiobook  Audiobook data
     * @param  Book  $book  Book model
     * @param  array  $aiMetadata  Metadata including cover path
     * @return bool Success status
     */
    protected function moveSplitBookFiles(array $audiobook, Book $book, array $aiMetadata): bool
    {
        try {
            $bookStoragePath = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
            if (!$bookStoragePath) {
                throw new \Exception('Book storage path not configured');
            }

            // Generate target directory using metadata
            $relativePath = $this->getImportService()->generateDirectoryPath($aiMetadata);

            // For split books, the directory path should include the book-specific subdirectory
            // which includes the series number (e.g., "01 Willful Child")
            $bookSubdir = $aiMetadata['title'];
            if (!empty($aiMetadata['series_number'])) {
                $bookSubdir = str_pad($aiMetadata['series_number'], 2, '0', STR_PAD_LEFT) . ' ' . $bookSubdir;
            }
            $targetDir = $bookStoragePath . '/' . $relativePath . '/' . $bookSubdir;

            $this->line("  Creating target directory: {$targetDir}");
            if (!File::isDirectory($targetDir)) {
                File::makeDirectory($targetDir, 0775, true);
            }

            // Get the single M4B file for this book
            $sourceFile = $audiobook['files'][0];
            $targetFile = $targetDir . '/' . basename($sourceFile);

            $operation = $this->option('copy-files') ? 'copy' : 'move';
            $this->line("  {$operation}ing M4B file: " . basename($sourceFile));

            if ($operation === 'copy') {
                if (!File::copy($sourceFile, $targetFile)) {
                    throw new \Exception("Failed to copy file from {$sourceFile} to {$targetFile}");
                }
            } else {
                // Use copy+delete instead of move to avoid cross-filesystem issues
                if (!File::copy($sourceFile, $targetFile)) {
                    throw new \Exception("Failed to copy file from {$sourceFile} to {$targetFile}");
                }
                if (!File::delete($sourceFile)) {
                    $this->warn("  Warning: Failed to delete source file after copy: {$sourceFile}");
                }
            }

            $this->getImportService()->assertDirectoryHasAudioFiles($targetDir, [
                'book_id' => $book->id,
                'source' => $sourceFile,
                'target' => $targetDir,
                'operation' => $operation,
                'import_mode' => 'split_book',
            ]);

            // Save cover image to target directory
            $coverSaved = false;

            // First check if we have embedded cover data
            if (!empty($aiMetadata['cover_data'])) {
                $coverTarget = $targetDir . '/cover.jpg';
                $this->line("  Saving embedded cover image");
                file_put_contents($coverTarget, $aiMetadata['cover_data']);
                $book->coverImage = $relativePath . '/' . $bookSubdir . '/cover.jpg';
                $coverSaved = true;
            } elseif (!empty($aiMetadata['cover_url']) && File::exists($aiMetadata['cover_url'])) {
                // Otherwise copy existing cover file if it exists
                $coverTarget = $targetDir . '/cover.jpg';
                $this->line("  Copying cover image");
                File::copy($aiMetadata['cover_url'], $coverTarget);

                // Delete the source cover.jpg from download directory
                if ($operation === 'move' && File::exists($aiMetadata['cover_url'])) {
                    File::delete($aiMetadata['cover_url']);
                    $this->line("  Deleted source cover image");
                }

                // Update book's cover path to the new location (relative path)
                $book->coverImage = $relativePath . '/' . $bookSubdir . '/cover.jpg';
                $coverSaved = true;
            }

            if ($coverSaved) {
                $this->line("  ✓ Cover image saved to final directory");
            }

            // Update book directory path (relative to storage path)
            $book->directory_path = $relativePath . '/' . $bookSubdir;
            $book->save();

            // Check if source directory is empty and prompt for cleanup
            $sourceDir = $audiobook['path'];
            if ($operation === 'move' && File::isDirectory($sourceDir)) {
                $this->checkAndCleanupSourceDirectory($sourceDir, $targetDir);
            }

            $this->line("  ✓ Split book files moved successfully");
            return true;
        } catch (\Exception $e) {
            $this->error("  ✗ Failed to move split book files: " . $e->getMessage());
            Log::error("Failed to move split book files", [
                'audiobook' => $audiobook,
                'book_id' => $book->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Check if source directory has remaining files and prompt for cleanup
     */
    protected function checkAndCleanupSourceDirectory(string $directory, ?string $targetDirectory = null): void
    {
        if (!File::isDirectory($directory)) {
            return;
        }

        // Safety check: Never delete parent/root directories
        $protectedPaths = [
            '/media/download',
            '/media/download/audiobooks',
            config('app.book_root', '/media/lyra_data1/audiobooks/books'),
        ];

        foreach ($protectedPaths as $protectedPath) {
            if ($protectedPath && rtrim($directory, '/') === rtrim($protectedPath, '/')) {
                if ($this->isOptionEnabled('verbose')) {
                    $this->line("  Skipping cleanup check for protected directory: {$directory}");
                }
                return;
            }
        }

        $files = File::files($directory);
        $directories = File::directories($directory);

        if (empty($files) && empty($directories)) {
            // Directory is empty, delete it
            $this->line("  Source directory is empty, deleting: {$directory}");
            File::deleteDirectory($directory);
            $this->info("  ✓ Deleted empty source directory");
        } elseif (!empty($files) || !empty($directories)) {
            // Safety check: Never delete directories with more than 10 files
            if (count($files) > 10) {
                if ($this->isOptionEnabled('verbose')) {
                    $this->info("  ℹ️  Source directory has many files (" . count($files) . ") - preserved automatically");
                }
                return;
            }

            // Check if there are any audio files remaining
            $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
            $hasAudioFiles = false;

            foreach ($files as $file) {
                $extension = strtolower($file->getExtension());
                if (in_array($extension, $audioExtensions)) {
                    $hasAudioFiles = true;
                    break;
                }
            }

            // If audio files remain, preserve directory automatically without prompting
            if ($hasAudioFiles) {
                if ($this->isOptionEnabled('verbose')) {
                    $this->info("  ℹ️  Source directory contains audio files - preserved automatically");
                }
                return;
            }

            // Directory has remaining non-audio files
            // In auto mode, automatically move files to target if available, otherwise keep
            if ($this->option('auto')) {
                if ($targetDirectory && File::isDirectory($targetDirectory)) {
                    if ($this->isOptionEnabled('verbose')) {
                        $this->info("  Auto mode: Moving remaining files to imported directory");
                    }
                    $movedCount = 0;
                    foreach ($files as $file) {
                        $targetFile = $targetDirectory . '/' . $file->getFilename();
                        if (File::copy($file->getPathname(), $targetFile)) {
                            File::delete($file->getPathname());
                            $movedCount++;
                        }
                    }
                    if ($this->isOptionEnabled('verbose')) {
                        $this->info("  ✓ Moved {$movedCount} files");
                    }

                    // Check if directory is now empty
                    if (empty(File::files($directory)) && empty(File::directories($directory))) {
                        File::deleteDirectory($directory);
                        if ($this->isOptionEnabled('verbose')) {
                            $this->info("  ✓ Deleted empty source directory");
                        }
                    }
                } else {
                    if ($this->isOptionEnabled('verbose')) {
                        $this->info("  Auto mode: Preserving source directory (no target available)");
                    }
                }
                return;
            }

            // Manual mode - show and prompt
            $this->newLine();
            $this->warn("⚠️  Source directory still contains files:");
            $this->line("  Directory: {$directory}");

            // Show directory contents
            $this->line("  Contents:");
            $process = new \Symfony\Component\Process\Process(['ls', '-lh', $directory]);
            $process->run();
            $this->line($process->getOutput());

            // Offer options for handling remaining files
            $this->line("\nOptions:");
            $this->line("1. (M)ove remaining files to imported directory" . ($targetDirectory ? " (default)" : ""));
            $this->line("2. (D)elete this directory and all remaining files");
            $this->line("3. (K)eep - preserve source directory");

            $choice = $this->ask("Choose an option (1-3)", $targetDirectory ? '1' : '3');
            $choice = strtolower(trim($choice));

            // Normalize choice
            if (in_array($choice, ['m', 'move'])) {
                $choice = '1';
            } elseif (in_array($choice, ['d', 'delete'])) {
                $choice = '2';
            } elseif (in_array($choice, ['k', 'keep'])) {
                $choice = '3';
            }

            switch ($choice) {
                case '1':
                    // Move remaining files to target directory
                    if ($targetDirectory && File::isDirectory($targetDirectory)) {
                        $this->info("  Moving remaining files to: {$targetDirectory}");
                        $movedCount = 0;
                        foreach ($files as $file) {
                            $targetFile = $targetDirectory . '/' . $file->getFilename();
                            if (File::copy($file->getPathname(), $targetFile)) {
                                File::delete($file->getPathname());
                                $movedCount++;
                            }
                        }
                        $this->info("  ✓ Moved {$movedCount} files");

                        // Check if directory is now empty
                        if (empty(File::files($directory)) && empty(File::directories($directory))) {
                            File::deleteDirectory($directory);
                            $this->info("  ✓ Deleted empty source directory");
                        }
                    } else {
                        $this->warn("  Target directory not available - preserving source");
                    }
                    break;

                case '2':
                    // Delete directory
                    File::deleteDirectory($directory);
                    $this->info("  ✓ Deleted source directory");
                    break;

                case '3':
                default:
                    // Keep directory
                    $this->info("  Source directory preserved");
                    break;
            }
        }
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
     * Process single audio file
     */


    /**
     * Get directory modification time
     */
    protected function getDirectoryModificationTime(string $path): int
    {
        $latestTime = 0;

        try {
            $files = File::allFiles($path);
            foreach ($files as $file) {
                $time = $file->getMTime();
                if ($time > $latestTime) {
                    $latestTime = $time;
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error getting directory modification time for {$path}: " . $e->getMessage());
        }

        return $latestTime ?: filemtime($path);
    }

    /**
     * Recursively scan directory for audiobooks
     */
    protected function scanDirectoryRecursive(string $directory, array &$audiobooks, int $depth = 0): void
    {
        if ($depth > 3) { // Limit recursion depth
            return;
        }

        // Check if directory should be skipped
        if ($this->shouldSkipDirectory($directory)) {
            return;
        }

        // Check if current directory has audio files
        $audioFiles = $this->getDirectoryParser()->getAudioFiles($directory);
        if (!empty($audioFiles)) {
            $audiobooks[] = [
                'name' => basename($directory),
                'path' => $directory,
                'files' => $audioFiles,
                'type' => 'directory',
                'size' => $this->getFileSystemService()->getDirectorySize($directory),
                'last_modified' => $this->getDirectoryModificationTime($directory),
            ];
            return; // Don't recurse further if this directory has audio files
        }

        // Scan subdirectories
        try {
            $subdirs = File::directories($directory);
            foreach ($subdirs as $subdir) {
                $this->scanDirectoryRecursive($subdir, $audiobooks, $depth + 1);
            }
        } catch (\Exception $e) {
            Log::warning("Error scanning subdirectories in {$directory}: " . $e->getMessage());
        }
    }

    /**
     * Edit cover image URL only
     */
    protected function editCoverImageOnly(array $metadata, array $audiobook): array
    {
        $this->newLine();
        $this->info("📸 Edit Cover Image URL");

        $currentCover = $metadata['cover_url'] ?? '';
        $this->line("Current cover: {$currentCover}");

        $newCover = $this->askWithImmediateInterrupt("Cover Image URL (or local path)", $currentCover);
        if ($this->inputInterrupted) {
            return $metadata;
        }

        if ($newCover !== $currentCover && !empty($newCover)) {
            $metadata['cover_url'] = trim($newCover);
            $this->info("✓ Cover image URL updated");

            // Show updated metadata
            $this->newLine();
            $this->displayEnrichedMetadata($metadata);
        }

        return $metadata;
    }

    /**
     * Edit genre only
     */
    protected function editGenreOnly(array $metadata, array $audiobook): array
    {
        $this->newLine();
        $this->info("📚 Change Genre");

        $currentGenre = is_array($metadata['genre']) ? implode(', ', $metadata['genre']) : ($metadata['genre'] ?? '');
        $this->line("Current genre: {$currentGenre}");

        // Get list of all genres from database for suggestions
        $allGenres = Genre::orderBy('name')->pluck('name')->toArray();
        if (!empty($allGenres)) {
            $this->newLine();
            $this->line("Available genres:");
            $chunks = array_chunk($allGenres, 4);
            foreach ($chunks as $chunk) {
                $this->line("  " . implode(', ', $chunk));
            }
            $this->newLine();
        }

        $newGenre = $this->askWithImmediateInterrupt("Genre", $currentGenre);
        if ($this->inputInterrupted) {
            return $metadata;
        }

        // Handle ' ' as a special value meaning "blank/clear this field"
        if ($newGenre === ' ') {
            $newGenre = '';
        } else {
            $newGenre = trim($newGenre);
        }

        if ($newGenre !== $currentGenre) {
            if (empty($newGenre)) {
                $metadata['genre'] = 'General Fiction';  // Default to General Fiction if cleared
                $this->info("✓ Genre cleared - defaulting to: General Fiction");
            } else {
                $metadata['genre'] = $newGenre;
                $this->info("✓ Genre updated to: {$newGenre}");
            }

            // Clear and regenerate custom_directory_path since genre affects path
            unset($metadata['custom_directory_path']);
            $newPath = $this->getImportService()->generateDirectoryPath($metadata, ['include_title' => true]);

            // Show updated metadata
            $this->newLine();
            $this->info("📁 Updated directory path: {$newPath}");
            $this->displayEnrichedMetadata($metadata);
        }

        return $metadata;
    }

    /**
     * Edit directory path only
     */
    protected function editDirectoryPathOnly(array $metadata, array $audiobook): array
    {
        $this->newLine();
        $this->info("📁 Edit Directory Path");

        // Generate current path
        $currentPath = $this->getImportService()->generateDirectoryPath($metadata, ['include_title' => true]);
        $this->line("Current path: {$currentPath}");

        // Allow user to edit the path
        $newPath = $this->ask("Enter new directory path (relative to library root)", $currentPath);

        if ($this->inputInterrupted) {
            return $metadata;
        }

        // Ensure path is relative, not absolute
        $newPath = trim($newPath);
        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        if (str_starts_with($newPath, $bookRoot . '/')) {
            $newPath = substr($newPath, strlen($bookRoot) + 1);
        } elseif (str_starts_with($newPath, $bookRoot)) {
            $newPath = substr($newPath, strlen($bookRoot) + 1);
        }

        // Store the custom path in metadata
        $metadata['custom_directory_path'] = $newPath;

        $this->newLine();
        $this->info("📁 Updated directory path: {$newPath}");
        $this->displayEnrichedMetadata($metadata);
        $this->newLine();

        return $metadata;
    }

    /**
     * Display detailed file operation error information
     */
    protected function displayFileOperationError(array $audiobook, Book $book): void
    {
        $this->line("📋 File Operation Details:");
        $this->line("   Source: {$audiobook['path']}");

        // Check recent logs for specific error
        $this->checkRecentFileOperationLogs($audiobook['path']);
    }

    /**
     * Check recent logs for file operation errors
     */
    protected function checkRecentFileOperationLogs(string $sourcePath): void
    {
        $logFile = storage_path('logs/laravel-' . date('Y-m-d') . '.log');
        if (!file_exists($logFile)) {
            $this->line("   Error: Could not access log file for detailed error information");
            return;
        }

        try {
            $logs = file_get_contents($logFile);
            $lines = explode("\n", $logs);
            $recentLines = array_slice($lines, -50); // Get last 50 lines

            foreach (array_reverse($recentLines) as $line) {
                if (
                    strpos($line, 'Failed to move files to library') !== false &&
                    strpos($line, basename($sourcePath)) !== false
                ) {
                    // Extract the error message
                    if (preg_match('/Failed to move files to library: (.+?) \{/', $line, $matches)) {
                        $errorMsg = trim($matches[1]);
                        $this->line("   Error: {$errorMsg}");

                        // Provide specific help based on error type
                        if (strpos($errorMsg, 'Operation not permitted') !== false) {
                            $this->line("   💡 This is likely a permissions issue. Check:");
                            $this->line("      - File/directory ownership");
                            $this->line("      - Write permissions on target directory");
                            $this->line("      - SELinux or AppArmor restrictions");
                        } elseif (strpos($errorMsg, 'No such file or directory') !== false) {
                            $this->line("   💡 The source file may have been moved or deleted");
                        } elseif (strpos($errorMsg, 'File exists') !== false) {
                            $this->line("   💡 Target file already exists - conflict resolution may be needed");
                        }
                        return;
                    }
                }
            }

            $this->line("   Error: Specific error details not found in recent logs");
        } catch (\Exception $e) {
            $this->line("   Error: Could not read log file for detailed error information");
        }
    }

    /**
     * Clean up book record and related data after failed file operation
     */
    protected function cleanupFailedBookImport(Book $book): void
    {
        try {
            $bookTitle = $book->title;
            $bookId = $book->id;

            $this->line("🧹 Cleaning up book record: {$bookTitle} (ID: {$bookId})");

            // Delete the book (this should cascade to related pivot tables)
            $book->delete();

            $this->line("   ✅ Book record and relationships cleaned up");
        } catch (\Exception $e) {
            $this->error("   ❌ Failed to clean up book record: " . $e->getMessage());
            $this->line("   ⚠️  Manual cleanup may be required for book ID: {$book->id}");
        }
    }

    /**
     * Validate audiobook files before processing
     */
    protected function validateAudiobookFiles(array $audiobook): bool
    {
        // Check if source still exists before processing
        if (!file_exists($audiobook['path'])) {
            $this->warn("⚠️  Skipping {$audiobook['name']} - source path no longer exists");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'Source path no longer exists',
            ];
            return false;
        }

        // Verify some key audio files still exist
        $missingFiles = 0;
        $sampleSize = min(3, count($audiobook['files'])); // Check up to 3 files
        for ($i = 0; $i < $sampleSize; $i++) {
            if (!file_exists($audiobook['files'][$i])) {
                $missingFiles++;
            }
        }

        if ($missingFiles > 0) {
            $this->warn("⚠️  Skipping {$audiobook['name']} - {$missingFiles} of {$sampleSize} sample files missing");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'Some audio files no longer exist',
            ];
            return false;
        }

        return true;
    }

    /**
     * Process audiobook metadata with AI and audio analysis
     */
    protected function processAudiobookMetadata(array $audiobook): ?array
    {
        // Skip directories with no audio files
        if (empty($audiobook['files']) || count($audiobook['files']) === 0) {
            $this->warn("⚠️  Skipping {$audiobook['name']} - no audio files found");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'No audio files found',
            ];
            return null;
        }

        $this->newLine();
        $this->info("📖 Processing: " . $audiobook['name']);
        $this->line("📁 Path: " . $audiobook['path']);
        $this->line(
            "📄 Files: " . count($audiobook['files']) .
            " (" . $this->getFileSystemService()->formatBytes($audiobook['total_size']) . ")"
        );

        // Try to inject OpenAudible metadata if available
        $openAudibleMeta = $this->findOpenAudibleMetadata($audiobook['path']);
        if ($openAudibleMeta) {
            $audiobook['openaudible_metadata'] = $openAudibleMeta;
            if (!empty($openAudibleMeta['genre'])) {
                $this->line("  🏷️  Genre from OpenAudible: {$openAudibleMeta['genre']}");
            }
        }

        // Pre-process Graphic Audio titles to improve AI recognition
        $cleanedAudiobook = $audiobook;
        if (stripos($audiobook['path'], 'GraphicAudio') !== false || stripos($audiobook['path'], 'Graphic Audio') !== false) {
            // Clean up the name for AI processing
            $cleanedAudiobook['name'] = preg_replace('/\s*\(GraphicAudio\)\s*/i', '', $audiobook['name']);
            $cleanedAudiobook['name'] = preg_replace('/\s*\(Graphic Audio\)\s*/i', '', $cleanedAudiobook['name']);
            $cleanedAudiobook['name'] = preg_replace('/\s*\(GA\)\s*/i', '', $cleanedAudiobook['name']);
        }

        // Detect collection from directory path
        // Check if path contains known collection patterns
        $collectionInfo = $this->detectCollectionFromPath($audiobook['path']);
        if ($collectionInfo) {
            $this->line("  📚 Detected collection: {$collectionInfo['name']} #{$collectionInfo['number']}");
            // Store for later use after AI processing
            $cleanedAudiobook['detected_collection'] = $collectionInfo;
        }

        // Step 1: AI Processing (skip if --skip-ai is set)
        if ($this->option('skip-ai')) {
            $this->info("⏩ Skipping AI processing (--skip-ai enabled)");
            $aiMetadata = $this->getMetadataService()->processWithoutAI($cleanedAudiobook);
        } else {
            $spinner = $this->output->createProgressBar();
            $spinner->setFormat(" %message%");
            $spinner->setMessage("🤖 Analyzing metadata with AI...");
            $spinner->start();

            $aiStartTime = microtime(true);
            $aiMetadata = $this->getMetadataService()->processWithAI($cleanedAudiobook);
            $aiDuration = round((microtime(true) - $aiStartTime) * 1000);

            $spinner->finish();
            $this->output->write("\r\033[K");

            if ($this->isOptionEnabled('verbose')) {
                $this->line("  ⏱️  AI processing took: {$aiDuration}ms");
            }
        }

        // Check if we should try audio analysis (low confidence OR forced) - skip if --skip-ai is set
        $shouldTryAudio = !$this->option('skip-ai') && (
            !$aiMetadata ||
            $aiMetadata['confidence'] < $this->option('min-confidence') ||
            $this->option('force-audio')
        );

        if ($shouldTryAudio) {
            if ($this->option('force-audio')) {
                $this->info("🎵 Forcing audio analysis (--force-audio flag used)");
            } else {
                $this->warn(
                    "⚠️  AI confidence too low (" . ($aiMetadata['confidence'] ?? 0) . "%) - " .
                    "trying audio analysis fallback"
                );
            }

            // Try audio analysis fallback
            $audioStartTime = microtime(true);
            $audioMetadata = $this->getMetadataService()->processWithAudioAnalysis($audiobook);
            $audioDuration = round((microtime(true) - $audioStartTime) * 1000);

            if ($this->isOptionEnabled('verbose')) {
                $this->line("  ⏱️  Audio analysis took: {$audioDuration}ms");
            }

            if ($audioMetadata && $audioMetadata['confidence'] >= $this->option('min-confidence')) {
                $this->info("✅ Audio analysis successful with " . $audioMetadata['confidence'] . "% confidence");
                $aiMetadata = $audioMetadata;
            } else {
                // Only skip if we tried due to low confidence, not if forced
                if (!$this->option('force-audio')) {
                    if ($this->option('auto')) {
                        // Auto mode: skip to avoid bad metadata
                        $this->warn("⚠️  Audio analysis also failed - skipping (auto mode)");
                        $currentProvider = config('services.ai.default_provider', 'gemini');
                        if ($currentProvider === 'gemini' && empty(config('services.gemini.api_key'))) {
                            $this->warn("   💡 Tip: Add GEMINI_API_KEY to your .env file to enable audio transcription");
                        } elseif ($currentProvider === 'claude' && empty(config('services.openai.api_key'))) {
                            $this->warn(
                                "   💡 Tip: Claude doesn't support audio transcription. Add OPENAI_API_KEY for fallback"
                            );
                        }
                        $this->skippedBooks[] = [
                            'path' => $audiobook['path'],
                            'reason' => 'Low AI confidence (tried audio analysis)',
                        ];
                        return null;
                    }
                    // Non-auto mode: continue with best-effort metadata (file tags), then manual review
                    $this->warn("⚠️  Audio analysis failed; continuing with file tag metadata for manual review");
                    if (!$aiMetadata) {
                        $fallback = $this->getMetadataService()->processWithoutAI($cleanedAudiobook);
                        if ($fallback) {
                            $aiMetadata = $fallback;
                        } else {
                            // Minimal placeholder to allow manual review
                            $aiMetadata = [
                                'title' => $audiobook['name'] ?? basename($audiobook['path']),
                                'author' => [],
                                'narrator' => [],
                                'genre' => [],
                                'confidence' => 50,
                            ];
                        }
                    }
                } else {
                    $this->warn("⚠️  Audio analysis failed but continuing due to --force-audio flag");
                    // Continue with original metadata if forced
                }
            }
        }

        $this->info("✅ AI processing successful (confidence: {$aiMetadata['confidence']}%)");

        // Inject detected collection information into metadata before returning
        if (!empty($cleanedAudiobook['detected_collection'])) {
            $collection = $cleanedAudiobook['detected_collection'];
            $aiMetadata['collection'] = $collection['name'];
            $aiMetadata['collection_number'] = $collection['number'];
        }

        return $aiMetadata;
    }

    /**
     * Check if directory should be skipped based on skip patterns
     */
    protected function shouldSkipDirectory(string $path): bool
    {
        $patterns = $this->option('skip-pattern');
        if (empty($patterns)) {
            return false;
        }

        // Check current directory name
        $dirName = basename($path);

        foreach ($patterns as $pattern) {
            // Use fnmatch for wildcard matching on directory name
            if (fnmatch($pattern, $dirName)) {
                if ($this->isOptionEnabled('verbose')) {
                    $this->line("  Skipping '{$path}' (matches pattern: {$pattern})");
                }
                return true;
            }

            // Also check if pattern contains path separator, then match full path
            if (str_contains($pattern, '/') && fnmatch($pattern, $path)) {
                if ($this->isOptionEnabled('verbose')) {
                    $this->line("  Skipping '{$path}' (matches pattern: {$pattern})");
                }
                return true;
            }

            // Check if any parent directory matches the pattern
            $pathParts = explode('/', trim($path, '/'));
            foreach ($pathParts as $part) {
                if (fnmatch($pattern, $part)) {
                    if ($this->isOptionEnabled('verbose')) {
                        $this->line("  Skipping '{$path}' (parent '{$part}' matches pattern: {$pattern})");
                    }
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find existing cover image in directory
     * Priority: cover.jpg, cover.jpeg, folder.jpg, *.jpg (first found)
     */
    protected function findExistingCoverImage(string $directory): ?string
    {
        $storagePath = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');

        // Only look for covers if directory is already in storage path
        // Don't return covers from download/source directories
        if (!str_starts_with($directory, $storagePath)) {
            return null;
        }

        $coverPatterns = [
            'cover.jpg',
            'cover.jpeg',
            'folder.jpg',
            'folder.jpeg',
        ];

        // Check for specific cover files first
        foreach ($coverPatterns as $pattern) {
            $coverPath = $directory . '/' . $pattern;
            if (File::exists($coverPath)) {
                // Return relative path from storage base
                return substr($coverPath, strlen($storagePath) + 1);
            }
        }

        // Fall back to any JPG/JPEG file in the directory
        $imageFiles = glob($directory . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
        if (!empty($imageFiles)) {
            // Return relative path from storage base
            return substr($imageFiles[0], strlen($storagePath) + 1);
        }

        return null;
    }

    /**
     * Detect collection information from directory path
     * Looks for patterns like: "Top 100-ish Sci-Fi Books/24 - Snow Crash - Neal Stephenson - 1992"
     *
     * @param string $path Full path to the book directory
     * @return array|null Array with 'name' and 'number' keys, or null if not a collection
     */
    protected function detectCollectionFromPath(string $path): ?array
    {
        // Collection patterns to detect in parent directory names
        $collectionPatterns = [
            '/top\s+\d+/i',                    // "Top 100", "Top 50"
            '/best\s+of/i',                    // "Best of"
            '/greatest/i',                     // "Greatest"
            '/collection/i',                   // "Collection"
            '/anthology/i',                    // "Anthology"
            '/\d+\s*essential/i',              // "100 Essential"
            '/must\s*read/i',                  // "Must Read"
            '/classics/i',                     // "Classics"
        ];

        // Split path into parts
        $pathParts = explode('/', trim($path, '/'));

        // Check each directory level for collection patterns
        for ($i = count($pathParts) - 2; $i >= 0; $i--) {
            $dirName = $pathParts[$i];

            // Check if this directory matches a collection pattern
            foreach ($collectionPatterns as $pattern) {
                if (preg_match($pattern, $dirName)) {
                    // Found a collection directory
                    // Try to extract number from the book directory name (last part)
                    $bookDirName = end($pathParts);
                    $number = null;

                    // Pattern: "24 - Snow Crash - Neal Stephenson - 1992"
                    if (preg_match('/^(\d+)\s*-/', $bookDirName, $matches)) {
                        $number = (int) $matches[1];
                    }

                    return [
                        'name' => $dirName,
                        'number' => $number,
                    ];
                }
            }
        }

        return null;
    }
}
