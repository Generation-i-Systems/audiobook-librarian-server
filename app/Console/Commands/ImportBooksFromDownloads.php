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
use App\Services\BackgroundProcessingService;
use App\Services\BookEnrichmentService;
use App\Services\BookImportService;
use App\Services\ExternalCoverService;
use App\Services\GoogleBooksApiService;
use App\Services\ImportCacheService;
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
                            {path?* : Specific files or folders to process (if none provided, scans default directories)}
                            {--directory=* : Custom directories to scan (ignored if paths are provided)}
                            {--model=gemini-2.5-flash-lite : AI model to use for processing}
                            {--min-confidence=80 : Minimum AI confidence for auto-import}
                            {--auto : Fully automated mode - no manual review}
                            {--dry-run : Show what would be imported without making changes}
                            {--limit=0 : Maximum number of books to process per run (0 = no limit)}
                            {--force : Skip confirmation prompts}
                            {--skip-enrichment : Skip external data enrichment (Audible, Google Books)}
                            {--copy-files : Copy files after successful import instead of moving (default is move)}
                            {--no-backup : Skip automatic database backup}
                            {--no-cache : Disable background processing cache}
                            {--clear-cache : Clear background processing cache before starting}
                            {--force-audio : Force audio transcription even when AI confidence is high}';

    /**
     * The console command description.
     */
    protected $description = 'Import audiobooks from download directories using AI processing and external data enrichment (creates a database backup by default)';

    protected ?AIBookProcessor $aiProcessor = null;
    protected ?AudioFileAnalyzer $audioAnalyzer = null;
    protected ?AudibleService $audibleService = null;
    protected ?ExternalCoverService $coverService = null;
    protected ?GoogleBooksApiService $googleBooksService = null;
    
    // New services
    protected ?BookEnrichmentService $enrichmentService = null;
    protected ?BookImportService $importService = null;
    protected ?BackgroundProcessingService $backgroundService = null;
    protected ?ImportCacheService $cacheService = null;
    
    // Background processing
    protected array $backgroundTasks = [];
    protected array $preloadedData = [];
    protected bool $backgroundProcessingEnabled = true;
    protected array $taskQueue = [];
    protected int $maxConcurrentTasks = 3;
    protected int $runningTaskCount = 0;
    protected bool $inputInterrupted = false;
    
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
        $this->initializeCache();
        
        // Create a database backup unless --no-backup is specified
        if (!$this->option('no-backup')) {
            $this->info('Creating a database backup before importing books...');
            $this->call('backup:database', ['--suffix' => 'import-books']);
            $this->info('Database backup created.');
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
            $this->info("📁 Processing specific paths: " . implode(', ', $specificPaths));
            $audiobooks = $this->processSpecificPaths($specificPaths);
        } else {
            // Get directories to scan
            $directories = $this->getDirectoriesToScan();
            if (empty($directories)) {
                $this->error("❌ No valid directories found to scan");
                return Command::FAILURE;
            }

            $this->info("📁 Scanning directories: " . implode(', ', $directories));

            // Scan for audiobooks
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
        $this->showCostEstimate(count($audiobooks));


        // Process each audiobook
        $progressBar = $this->output->createProgressBar(count($audiobooks));
        $progressBar->start();

        foreach ($audiobooks as $index => $audiobook) {
            try {
                // Start background processing for upcoming books
                $this->startBackgroundProcessing($audiobooks, $index);
                
                $this->info("Debug: Calling processAudiobook for: " . $audiobook['name']);
                $this->processAudiobook($audiobook);
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                
                // Handle user interruption specially
                if (str_contains($errorMessage, '[Request interrupted by user]')) {
                    $this->skippedBooks[] = [
                        'path' => $audiobook['path'],
                        'reason' => 'User interruption - skipped current book'
                    ];
                    $this->info("⏭️  Skipped due to user interruption: " . basename($audiobook['path']));
                } else {
                    // Regular error handling with detailed stack trace for debugging
                    $stackTrace = $e->getTraceAsString();
                    $fullError = $errorMessage . "\n\nStack trace:\n" . $stackTrace;
                    
                    $this->failedBooks[] = [
                        'path' => $audiobook['path'],
                        'error' => $errorMessage
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
        $this->displaySummary();
        
        // Save cache before exit and show cache statistics
        if ($this->cacheEnabled) {
            $this->saveCache();
            $this->displayCacheStatistics();
        }

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
     * Process specific files or folders provided as arguments
     */
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

        return [
            'path' => $filePath,
            'name' => pathinfo($filePath, PATHINFO_FILENAME),
            'files' => [$filePath],
            'total_size' => $fileSize
        ];
    }

    /**
     * Process a single directory as an audiobook
     */
    protected function processAudiobookDirectory(string $directory): ?array
    {
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
            return [
                'path' => $directory,
                'name' => basename($directory),
                'files' => $files,
                'total_size' => $totalSize
            ];
        }

        return null;
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

            // Group CD directories under their parent before filtering
            $potentialBooks = $this->groupCdDirectories($potentialBooks);
            
            // Filter out directories with too few files or too small size
            foreach ($potentialBooks as $bookData) {
                if (count($bookData['files']) >= 1 && $bookData['total_size'] > 10 * 1024 * 1024) { // At least 10MB
                    // Skip if this is one of the parent scan directories
                    if (in_array($bookData['path'], $directories)) {
                        $this->skippedBooks[] = [
                            'path' => $bookData['path'],
                            'reason' => 'Parent scan directory - contains subdirectories'
                        ];
                        continue;
                    }
                    
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
     * Start background processing tasks while waiting for user input (enhanced)
     */
    protected function startBackgroundProcessing(array $audiobooks, int $currentIndex = 0): void
    {
        if (!$this->backgroundProcessingEnabled) {
            return;
        }
        
        // Process more books ahead (increased to 7 for deeper queue)
        $lookaheadCount = min(7, count($audiobooks) - $currentIndex - 1);
        
        for ($i = $currentIndex + 1; $i <= $currentIndex + $lookaheadCount; $i++) {
            if (isset($audiobooks[$i])) {
                $audiobook = $audiobooks[$i];
                $distance = $i - $currentIndex;
                
                // Prioritize tasks for closer books
                $priority = $distance <= 2 ? 'high' : 'normal';
                
                // Queue multiple task types for each upcoming book
                $this->queueBackgroundTask('preprocess_metadata', $audiobook, $priority);
                $this->queueBackgroundTask('scan_directory', $audiobook, $priority);
                $this->queueBackgroundTask('duplicate_check', $audiobook, $priority);
                $this->queueBackgroundTask('extract_metadata', $audiobook, $priority);
                $this->queueBackgroundTask('analyze_audio_files', $audiobook, $priority);
                $this->queueBackgroundTask('prepare_cover_image', $audiobook, $priority);
            }
        }
        
        // Continuously process background tasks to maintain 3+ concurrent operations
        $this->processBackgroundTasks();
        
        // Show enhanced background processing status
        $this->showEnhancedBackgroundStatus();
    }
    
    /**
     * Schedule a background task
     */
    protected function scheduleBackgroundTask(string $type, array $data): void
    {
        $taskId = md5(serialize($data));
        
        if (!isset($this->backgroundTasks[$taskId])) {
            $this->backgroundTasks[$taskId] = [
                'type' => $type,
                'data' => $data,
                'status' => 'pending',
                'result' => null
            ];
        }
    }
    
    /**
     * Process pending background tasks (enhanced with concurrent task management)
     */
    protected function processBackgroundTasks(): void
    {
        // Maintain at least 3 concurrent background tasks
        $this->maintainConcurrentTasks();
        
        // Process currently running tasks
        foreach ($this->backgroundTasks as $taskId => &$task) {
            if ($task['status'] === 'processing') {
                // Check if task should be completed (simulated async processing)
                if (!isset($task['start_time'])) {
                    $task['start_time'] = microtime(true);
                }
                
                // Simulate processing time (remove this in real async implementation)
                $processingTime = microtime(true) - $task['start_time'];
                if ($processingTime > 0.1) { // 100ms simulation
                    try {
                        $task['result'] = $this->executeBackgroundTask($task);
                        $task['status'] = 'completed';
                        $task['end_time'] = microtime(true);
                        $this->runningTaskCount--;
                    } catch (\Exception $e) {
                        $task['status'] = 'failed';
                        $task['error'] = $e->getMessage();
                        $task['end_time'] = microtime(true);
                        $this->runningTaskCount--;
                    }
                }
            }
        }
        
        // Start new tasks if we have capacity
        $this->startQueuedTasks();
    }
    
    /**
     * Execute a specific background task (with caching)
     */
    protected function executeBackgroundTask(array $task): array
    {
        $audiobook = $task['data'];
        $taskType = $task['type'];
        
        // Check cache first
        $cachedResult = $this->getCachedResult($audiobook, $taskType);
        if ($cachedResult !== null) {
            return array_merge($cachedResult, ['from_cache' => true]);
        }
        
        // Execute task if not cached
        $result = $this->executeBackgroundTaskInternal($taskType, $audiobook);
        
        // Store result in cache
        $this->setCachedResult($audiobook, $taskType, $result);
        
        return array_merge($result, ['from_cache' => false]);
    }
    
    /**
     * Internal task execution without caching
     */
    protected function executeBackgroundTaskInternal(string $taskType, array $audiobook): array
    {
        switch ($taskType) {
            case 'preprocess_metadata':
                return $this->preprocessMetadataInBackground($audiobook);
            case 'scan_directory':
                return $this->scanDirectoryInBackground($audiobook);
            case 'duplicate_check':
                return $this->checkDuplicatesInBackground($audiobook);
            case 'extract_metadata':
                return $this->extractMetadataInBackground($audiobook);
            case 'analyze_audio_files':
                return $this->analyzeAudioFilesInBackground($audiobook);
            case 'prepare_cover_image':
                return $this->prepareCoverImageInBackground($audiobook);
            default:
                throw new \Exception("Unknown task type: {$taskType}");
        }
    }
    
    /**
     * Maintain at least 3 concurrent background tasks
     */
    protected function maintainConcurrentTasks(): void
    {
        // Count currently running tasks
        $runningTasks = 0;
        foreach ($this->backgroundTasks as $task) {
            if ($task['status'] === 'processing') {
                $runningTasks++;
            }
        }
        $this->runningTaskCount = $runningTasks;
        
        // Start new tasks to maintain minimum concurrent count
        while ($this->runningTaskCount < $this->maxConcurrentTasks && !empty($this->taskQueue)) {
            $nextTask = array_shift($this->taskQueue);
            $this->startBackgroundTask($nextTask);
        }
    }
    
    /**
     * Start a background task immediately
     */
    protected function startBackgroundTask(array $taskInfo): void
    {
        $taskId = md5(serialize($taskInfo));
        
        if (!isset($this->backgroundTasks[$taskId])) {
            $this->backgroundTasks[$taskId] = [
                'type' => $taskInfo['type'],
                'data' => $taskInfo['data'],
                'status' => 'processing',
                'result' => null,
                'start_time' => microtime(true),
                'priority' => $taskInfo['priority'] ?? 'normal'
            ];
            $this->runningTaskCount++;
        }
    }
    
    /**
     * Start queued tasks if we have capacity
     */
    protected function startQueuedTasks(): void
    {
        while ($this->runningTaskCount < $this->maxConcurrentTasks && !empty($this->taskQueue)) {
            $nextTask = array_shift($this->taskQueue);
            $this->startBackgroundTask($nextTask);
        }
    }
    
    /**
     * Queue a background task with priority
     */
    protected function queueBackgroundTask(string $type, array $data, string $priority = 'normal'): void
    {
        $taskInfo = [
            'type' => $type,
            'data' => $data,
            'priority' => $priority
        ];
        
        // Insert based on priority (high priority tasks go first)
        if ($priority === 'high') {
            array_unshift($this->taskQueue, $taskInfo);
        } else {
            array_push($this->taskQueue, $taskInfo);
        }
    }
    
    /**
     * Get result from background task if available
     */
    protected function getBackgroundResult(string $taskId): ?array
    {
        if (isset($this->backgroundTasks[$taskId]) && $this->backgroundTasks[$taskId]['status'] === 'completed') {
            return $this->backgroundTasks[$taskId]['result'];
        }
        
        return null;
    }
    
    /**
     * Preprocess metadata in background (enhanced)
     */
    protected function preprocessMetadataInBackground(array $audiobook): array
    {
        // Start comprehensive metadata extraction
        $metadata = $this->extractBasicMetadata($audiobook);
        
        // Pre-analyze directory structure
        $directoryAnalysis = [
            'has_subdirectories' => !empty(File::directories($audiobook['path'])),
            'cd_directories' => $this->hasCdDirectories($audiobook['path']),
            'file_types' => $this->analyzeFileTypes($audiobook['files']),
            'total_size' => array_sum(array_map('filesize', $audiobook['files'])),
            'directory_depth' => substr_count($audiobook['path'], '/'),
        ];
        
        // Pre-extract basic info from directory name
        $directoryInfo = $this->analyzeDirectoryName(basename($audiobook['path']));
        
        // Check for special markers
        $specialMarkers = [
            'multi_book' => $this->isMultiBookDirectory($audiobook['path']),
            'graphic_audio' => str_contains(strtolower($audiobook['path']), 'graphic'),
            'series_book' => preg_match('/\d+/', basename($audiobook['path'])),
        ];
        
        return [
            'basic_metadata' => $metadata,
            'directory_analysis' => $directoryAnalysis,
            'directory_info' => $directoryInfo,
            'special_markers' => $specialMarkers,
            'audio_files_counted' => count($audiobook['files']),
            'cover_image_found' => $this->findCoverImage($audiobook['path']) !== null,
            'ready_for_processing' => true,
            'timestamp' => time()
        ];
    }
    
    /**
     * Scan directory structure in background
     */
    protected function scanDirectoryInBackground(array $data): array
    {
        $path = $data['path'];
        
        return [
            'file_count' => count(File::allFiles($path)),
            'has_cd_directories' => $this->hasCdDirectories($path),
            'directory_size' => $this->getDirectorySize($path),
            'potential_duplicates' => $this->findPotentialDuplicates($path)
        ];
    }
    
    /**
     * Check for duplicates in background
     */
    protected function checkDuplicatesInBackground(array $audiobook): array
    {
        return [
            'existing_books' => $this->findSimilarBooks($audiobook),
            'duplicate_paths' => $this->findDuplicatePaths($audiobook['path'])
        ];
    }
    
    /**
     * Extract detailed metadata in background
     */
    protected function extractMetadataInBackground(array $audiobook): array
    {
        $metadata = [];
        
        // Get first audio file for metadata extraction
        $audioFiles = array_filter($audiobook['files'], function($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac']);
        });
        
        if (!empty($audioFiles)) {
            $audioFileValues = array_values($audioFiles);
            if (empty($audioFileValues)) {
                return ['confidence' => 0];
            }
            $firstAudioFile = $audioFileValues[0];
            
            // Extract basic file metadata
            if (function_exists('getid3_analyze')) {
                try {
                    $getID3 = new \getID3;
                    $fileInfo = $getID3->analyze($firstAudioFile);
                    
                    $metadata = [
                        'title' => $fileInfo['tags']['id3v2']['title'][0] ?? basename($audiobook['path']),
                        'artist' => $fileInfo['tags']['id3v2']['artist'][0] ?? null,
                        'album' => $fileInfo['tags']['id3v2']['album'][0] ?? null,
                        'year' => $fileInfo['tags']['id3v2']['year'][0] ?? null,
                        'genre' => $fileInfo['tags']['id3v2']['genre'][0] ?? null,
                        'duration' => $fileInfo['playtime_seconds'] ?? 0,
                        'bitrate' => $fileInfo['audio']['bitrate'] ?? 0,
                        'sample_rate' => $fileInfo['audio']['sample_rate'] ?? 0
                    ];
                } catch (\Exception $e) {
                    // Fallback to basic extraction
                    $metadata['title'] = basename($audiobook['path']);
                }
            }
        }
        
        // Extract NFO data if available
        $nfoFiles = glob($audiobook['path'] . '/*.nfo');
        if (!empty($nfoFiles)) {
            $metadata['has_nfo'] = true;
            $metadata['nfo_data'] = $this->extractNfoDataInBackground($nfoFiles[0]);
        }
        
        return $metadata;
    }
    
    /**
     * Analyze audio files in background
     */
    protected function analyzeAudioFilesInBackground(array $audiobook): array
    {
        $analysis = [
            'total_files' => 0,
            'audio_files' => 0,
            'total_duration' => 0,
            'average_bitrate' => 0,
            'file_formats' => [],
            'largest_file' => null,
            'smallest_file' => null
        ];
        
        $durations = [];
        $bitrates = [];
        $fileSizes = [];
        
        foreach ($audiobook['files'] as $file) {
            $analysis['total_files']++;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            
            if (in_array($ext, ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'])) {
                $analysis['audio_files']++;
                $analysis['file_formats'][$ext] = ($analysis['file_formats'][$ext] ?? 0) + 1;
                
                $fileSize = filesize($file);
                $fileSizes[] = $fileSize;
                
                // Track largest and smallest files
                if ($analysis['largest_file'] === null || $fileSize > $fileSizes[array_search($analysis['largest_file']['size'], $fileSizes)]) {
                    $analysis['largest_file'] = ['path' => $file, 'size' => $fileSize];
                }
                if ($analysis['smallest_file'] === null || $fileSize < $fileSizes[array_search($analysis['smallest_file']['size'], $fileSizes)]) {
                    $analysis['smallest_file'] = ['path' => $file, 'size' => $fileSize];
                }
            }
        }
        
        $analysis['total_size'] = array_sum($fileSizes);
        $analysis['average_file_size'] = $analysis['audio_files'] > 0 ? $analysis['total_size'] / $analysis['audio_files'] : 0;
        
        return $analysis;
    }
    
    /**
     * Prepare cover image in background
     */
    protected function prepareCoverImageInBackground(array $audiobook): array
    {
        $result = [
            'has_cover' => false,
            'cover_path' => null,
            'cover_size' => null,
            'thumbnail_ready' => false
        ];
        
        $coverPath = $this->findCoverImage($audiobook['path']);
        
        if ($coverPath && file_exists($coverPath)) {
            $result['has_cover'] = true;
            $result['cover_path'] = $coverPath;
            $result['cover_size'] = filesize($coverPath);
            
            // Pre-create thumbnail if using Kitty protocol
            if ($this->isGhosttyTerminal()) {
                try {
                    $thumbnailPath = $this->createThumbnail($coverPath);
                    if ($thumbnailPath) {
                        $result['thumbnail_ready'] = true;
                        $result['thumbnail_path'] = $thumbnailPath;
                    }
                } catch (\Exception $e) {
                    // Thumbnail creation failed, but we still have the original cover
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Extract NFO data in background
     */
    protected function extractNfoDataInBackground(string $nfoPath): array
    {
        $data = [];
        
        if (file_exists($nfoPath)) {
            $content = file_get_contents($nfoPath);
            
            // Try to parse as XML first
            if (strpos($content, '<?xml') !== false) {
                try {
                    $xml = simplexml_load_string($content);
                    if ($xml) {
                        $data['title'] = (string)$xml->title ?? null;
                        $data['plot'] = (string)$xml->plot ?? null;
                        $data['year'] = (string)$xml->year ?? null;
                        $data['genre'] = (string)$xml->genre ?? null;
                    }
                } catch (\Exception $e) {
                    // XML parsing failed, treat as plain text
                }
            }
            
            // Extract key-value pairs from plain text
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                if (strpos($line, ':') !== false) {
                    [$key, $value] = explode(':', $line, 2);
                    $data[strtolower(trim($key))] = trim($value);
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Show background processing status
     */
    protected function showBackgroundProcessingStatus(): void
    {
        $completed = 0;
        $failed = 0;
        $pending = 0;
        
        foreach ($this->backgroundTasks as $task) {
            switch ($task['status']) {
                case 'completed':
                    $completed++;
                    break;
                case 'failed':
                    $failed++;
                    break;
                case 'pending':
                    $pending++;
                    break;
            }
        }
        
        if ($completed > 0 || $failed > 0) {
            $status = "🔄 Background: {$completed} completed";
            if ($failed > 0) {
                $status .= ", {$failed} failed";
            }
            if ($pending > 0) {
                $status .= ", {$pending} pending";
            }
            $this->line($status);
        }
    }
    
    /**
     * Show enhanced background processing status
     */
    protected function showEnhancedBackgroundStatus(): void
    {
        $completed = 0;
        $failed = 0;
        $processing = 0;
        $cached = 0;
        $queued = count($this->taskQueue);
        
        foreach ($this->backgroundTasks as $task) {
            switch ($task['status']) {
                case 'completed':
                    $completed++;
                    // Check if result came from cache
                    if (isset($task['result']['from_cache']) && $task['result']['from_cache']) {
                        $cached++;
                    }
                    break;
                case 'failed':
                    $failed++;
                    break;
                case 'processing':
                    $processing++;
                    break;
            }
        }
        
        if ($processing > 0 || $completed > 0 || $queued > 0) {
            $parts = [];
            
            if ($processing > 0) {
                $parts[] = "{$processing} running";
            }
            if ($queued > 0) {
                $parts[] = "{$queued} queued";
            }
            if ($completed > 0) {
                $parts[] = "{$completed} done";
                if ($cached > 0) {
                    $parts[] = "{$cached} cached";
                }
            }
            if ($failed > 0) {
                $parts[] = "{$failed} failed";
            }
            
            $status = "🔄 Background: " . implode(', ', $parts);
            $this->line($status);
        }
    }
    
    /**
     * Add helper methods for enhanced background processing
     */
    protected function analyzeFileTypes(array $files): array
    {
        $types = [];
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types[$ext] = ($types[$ext] ?? 0) + 1;
        }
        return $types;
    }
    
    protected function analyzeDirectoryName(string $directoryName): array
    {
        return [
            'contains_numbers' => preg_match('/\d+/', $directoryName),
            'contains_series_markers' => preg_match('/\b(book|vol|volume|part|chapter)\b/i', $directoryName),
            'has_separators' => preg_match('/[-:_]/', $directoryName),
            'word_count' => str_word_count($directoryName),
            'length' => strlen($directoryName)
        ];
    }
    
    protected function isMultiBookDirectory(string $path): bool
    {
        $files = File::files($path);
        $multiBookPattern = '/\[(\d{2,3})\]/';
        
        foreach ($files as $file) {
            if (preg_match($multiBookPattern, $file->getFilename())) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if directory contains audio files (recursively)
     */
    protected function hasAudioFiles(string $path): bool
    {
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $extension = strtolower($file->getExtension());
                    if (in_array($extension, $audioExtensions)) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            return false;
        }
        
        return false;
    }

    /**
     * Find individual audiobook directories within a parent directory
     */
    protected function findAudiobookDirectories(string $parentPath): array
    {
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
    protected function initializeCache(): void
    {
        // Check if caching is disabled
        if ($this->option('no-cache')) {
            $this->cacheEnabled = false;
            $this->info("📦 Background processing cache disabled");
            return;
        }
        
        // Set up cache directory
        $this->cacheDirectory = storage_path('app/audiobook-cache');
        $this->cacheFilePath = $this->cacheDirectory . '/background-processing-cache.json';
        
        // Create cache directory if it doesn't exist
        if (!File::isDirectory($this->cacheDirectory)) {
            File::makeDirectory($this->cacheDirectory, 0775, true);
        }
        
        // Clear cache if requested
        if ($this->option('clear-cache')) {
            if (file_exists($this->cacheFilePath)) {
                unlink($this->cacheFilePath);
                $this->info("🗑️  Background processing cache cleared");
            }
            $this->backgroundCache = [];
            return;
        }
        
        // Load existing cache
        $this->loadCache();
        
        // Clean up old/invalid cache entries
        $this->cleanupCache();
        
        $cacheSize = count($this->backgroundCache);
        if ($cacheSize > 0) {
            $this->info("📦 Loaded {$cacheSize} cached background processing results");
        }
    }
    
    /**
     * Load cache from disk
     */
    protected function loadCache(): void
    {
        if (!$this->cacheEnabled || !file_exists($this->cacheFilePath)) {
            $this->backgroundCache = [];
            return;
        }
        
        try {
            $cacheData = json_decode(file_get_contents($this->cacheFilePath), true);
            
            if (!$cacheData || !is_array($cacheData)) {
                $this->backgroundCache = [];
                return;
            }
            
            // Check cache version compatibility
            if (($cacheData['version'] ?? 1) !== $this->cacheVersion) {
                $this->info("📦 Cache version mismatch - rebuilding cache");
                $this->backgroundCache = [];
                return;
            }
            
            $this->backgroundCache = $cacheData['data'] ?? [];
            
        } catch (\Exception $e) {
            $this->warn("⚠️  Failed to load cache: " . $e->getMessage());
            $this->backgroundCache = [];
        }
    }
    
    /**
     * Save cache to disk
     */
    protected function saveCache(): void
    {
        if (!$this->cacheEnabled || !isset($this->cacheFilePath)) {
            return;
        }
        
        try {
            $cacheData = [
                'version' => $this->cacheVersion,
                'last_updated' => time(),
                'data' => $this->backgroundCache
            ];
            
            file_put_contents($this->cacheFilePath, json_encode($cacheData, JSON_PRETTY_PRINT));
            
        } catch (\Exception $e) {
            $this->warn("⚠️  Failed to save cache: " . $e->getMessage());
        }
    }
    
    /**
     * Clean up old or invalid cache entries
     */
    protected function cleanupCache(): void
    {
        $cleaned = 0;
        $maxAge = 86400 * 7; // 7 days
        $currentTime = time();
        
        foreach ($this->backgroundCache as $cacheKey => $cacheEntry) {
            // Remove entries older than max age
            if (isset($cacheEntry['timestamp']) && ($currentTime - $cacheEntry['timestamp']) > $maxAge) {
                unset($this->backgroundCache[$cacheKey]);
                $cleaned++;
                continue;
            }
            
            // Remove entries for directories that no longer exist
            if (isset($cacheEntry['path']) && !is_dir($cacheEntry['path'])) {
                unset($this->backgroundCache[$cacheKey]);
                $cleaned++;
                continue;
            }
            
            // Remove entries where files have been modified
            if (isset($cacheEntry['path']) && isset($cacheEntry['directory_mtime'])) {
                $currentMtime = $this->getDirectoryModificationTime($cacheEntry['path']);
                if ($currentMtime > $cacheEntry['directory_mtime']) {
                    unset($this->backgroundCache[$cacheKey]);
                    $cleaned++;
                    continue;
                }
            }
        }
        
        if ($cleaned > 0) {
            $this->info("🧹 Cleaned {$cleaned} stale cache entries");
        }
    }
    
    /**
     * Get cache key for an audiobook
     */
    protected function getCacheKey(array $audiobook): string
    {
        return md5($audiobook['path'] . '|' . ($audiobook['total_size'] ?? 0));
    }
    
    /**
     * Get cached result for a background task
     */
    protected function getCachedResult(array $audiobook, string $taskType): ?array
    {
        if (!$this->cacheEnabled) {
            return null;
        }
        
        $cacheKey = $this->getCacheKey($audiobook);
        $fullKey = $cacheKey . '_' . $taskType;
        
        if (isset($this->backgroundCache[$fullKey])) {
            $cached = $this->backgroundCache[$fullKey];
            
            // Check if cache is still valid
            if (isset($cached['timestamp']) && (time() - $cached['timestamp']) < 86400) {
                return $cached['result'];
            }
        }
        
        return null;
    }
    
    /**
     * Store result in cache
     */
    protected function setCachedResult(array $audiobook, string $taskType, array $result): void
    {
        if (!$this->cacheEnabled) {
            return;
        }
        
        $cacheKey = $this->getCacheKey($audiobook);
        $fullKey = $cacheKey . '_' . $taskType;
        
        $this->backgroundCache[$fullKey] = [
            'path' => $audiobook['path'],
            'task_type' => $taskType,
            'result' => $result,
            'timestamp' => time(),
            'directory_mtime' => $this->getDirectoryModificationTime($audiobook['path'])
        ];
    }
    
    /**
     * Get directory modification time
     */
    protected function getDirectoryModificationTime(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        
        $latestMtime = filemtime($path);
        
        // Check files in directory for latest modification
        $files = File::allFiles($path);
        foreach ($files as $file) {
            $mtime = $file->getMTime();
            if ($mtime > $latestMtime) {
                $latestMtime = $mtime;
            }
        }
        
        return $latestMtime;
    }
    
    /**
     * Display cache statistics
     */
    protected function displayCacheStatistics(): void
    {
        $totalEntries = count($this->backgroundCache);
        
        if ($totalEntries === 0) {
            $this->info("💾 Cache: empty");
            return;
        }
        
        $taskTypes = [];
        $totalSize = 0;
        $cacheHits = 0;
        
        foreach ($this->backgroundCache as $entry) {
            $taskType = $entry['task_type'] ?? 'unknown';
            $taskTypes[$taskType] = ($taskTypes[$taskType] ?? 0) + 1;
            $totalSize += strlen(json_encode($entry));
        }
        
        // Count cache hits from this session
        foreach ($this->backgroundTasks as $task) {
            if (isset($task['result']['from_cache']) && $task['result']['from_cache']) {
                $cacheHits++;
            }
        }
        
        $this->info("💾 Cache: {$totalEntries} entries, " . $this->formatBytes($totalSize) . " size");
        
        if ($cacheHits > 0) {
            $this->info("🎯 Cache hits this session: {$cacheHits}");
        }
        
        if (!empty($taskTypes)) {
            $typesList = [];
            foreach ($taskTypes as $type => $count) {
                $typesList[] = "{$type}({$count})";
            }
            $this->line("   Types: " . implode(', ', $typesList));
        }
    }
    
    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . 'MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 1) . 'KB';
        } else {
            return $bytes . 'B';
        }
    }
    
    /**
     * Save cache before application exit
     */
    public function __destruct()
    {
        $this->saveCache();
    }
    
    /**
     * Enhanced ask method with background processing and quit handling
     */
    protected function askWithBackground(string $question, string $default = null, array $backgroundData = []): string
    {
        // Start background processing if data provided
        if (!empty($backgroundData)) {
            foreach ($backgroundData as $task) {
                $this->queueBackgroundTask($task['type'], $task['data'], 'high');
            }
        }
        
        // Continuously process background tasks while waiting for user input
        $this->startContinuousBackgroundProcessing();
        
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
            $this->processBackgroundTasks();
            
            // Small delay to simulate processing time
            usleep(50000); // 50ms
        }
        
        // Show final status
        $this->showEnhancedBackgroundStatus();
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
            pcntl_signal(SIGINT, function() {
                $this->handleUserInterruption();
            });
            
            pcntl_signal(SIGTERM, function() {
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
            
            // Return default if empty
            return $input ?: ($default ?? '');
        }
    }

    /**
     * Helper methods for background processing
     */
    protected function hasCdDirectories(string $path): bool
    {
        $cdPattern = '/^(cd|disc|disk)[\s_-]*(\d+)$/i';
        $directories = File::directories($path);
        
        foreach ($directories as $dir) {
            if (preg_match($cdPattern, basename($dir))) {
                return true;
            }
        }
        
        return false;
    }
    
    protected function getDirectorySize(string $path): int
    {
        $size = 0;
        $files = File::allFiles($path);
        
        foreach ($files as $file) {
            $size += $file->getSize();
        }
        
        return $size;
    }
    
    protected function findPotentialDuplicates(string $path): array
    {
        $baseName = basename($path);
        
        // Look for similar directory names in the library
        return Book::where('directory_path', 'LIKE', "%{$baseName}%")
                   ->orWhere('title', 'LIKE', "%{$baseName}%")
                   ->limit(5)
                   ->get()
                   ->toArray();
    }
    
    protected function findSimilarBooks(array $audiobook): array
    {
        $baseName = basename($audiobook['path']);
        
        return Book::where('title', 'LIKE', "%{$baseName}%")
                   ->orWhere('directory_path', 'LIKE', "%{$baseName}%")
                   ->limit(10)
                   ->get()
                   ->toArray();
    }
    
    protected function findDuplicatePaths(string $path): array
    {
        $results = [];
        $baseName = basename($path);
        
        // Check for existing books with similar paths
        $existingBooks = Book::where('directory_path', 'LIKE', "%{$baseName}%")->get();
        
        foreach ($existingBooks as $book) {
            $results[] = [
                'id' => $book->id,
                'title' => $book->title,
                'path' => $book->directory_path,
                'similarity' => similar_text($baseName, basename($book->directory_path))
            ];
        }
        
        return $results;
    }

    /**
     * Group CD directories under their parent directory to treat multi-disc books as single audiobooks
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
                    'cd_number' => (int)$matches[2],
                    'data' => $bookData
                ];
                
                // Initialize parent directory data
                if (!isset($parentDirectories[$parentPath])) {
                    $parentDirectories[$parentPath] = [
                        'path' => $parentPath,
                        'name' => basename($parentPath),
                        'files' => [],
                        'total_size' => 0,
                        'cd_count' => 0
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
            $author = is_array($metadata['author']) ? $metadata['author'][0] : $metadata['author'];
            
            $existingBook = Book::where('title', '=', $title)
                ->whereHas('authors', function($query) use ($author) {
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
            $author = is_array($metadata['author']) ? $metadata['author'][0] : $metadata['author'];
            
            // Use exact title match only - no partial matching to avoid series conflicts
            $existingBook = Book::where('title', '=', $title)
                ->whereHas('authors', function($query) use ($author) {
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
        // Check if source still exists before processing
        if (!file_exists($audiobook['path'])) {
            $this->warn("⚠️  Skipping {$audiobook['name']} - source path no longer exists");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'Source path no longer exists'
            ];
            return;
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
                'reason' => 'Some audio files no longer exist'
            ];
            return;
        }
        
        // Skip directories with no audio files
        if (empty($audiobook['files']) || count($audiobook['files']) === 0) {
            $this->warn("⚠️  Skipping {$audiobook['name']} - no audio files found");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'No audio files found'
            ];
            return;
        }
        
        $this->newLine();
        $this->info("📖 Processing: " . $audiobook['name']);
        $this->line("📁 Path: " . $audiobook['path']);
        $this->line("📄 Files: " . count($audiobook['files']) . " (" . $this->formatBytes($audiobook['total_size']) . ")");

        // Step 1: AI Processing
        $spinner = $this->output->createProgressBar();
        $spinner->setFormat(" %message%");
        $spinner->setMessage("🤖 Analyzing metadata with AI...");
        $spinner->start();
        
        $aiMetadata = $this->processWithAI($audiobook);
        
        $spinner->finish();
        $this->output->write("\r\033[K");
        
        // Check if we should try audio analysis (low confidence OR forced)
        $shouldTryAudio = !$aiMetadata || 
                         $aiMetadata['confidence'] < $this->option('min-confidence') || 
                         $this->option('force-audio');
                         
        if ($shouldTryAudio) {
            if ($this->option('force-audio')) {
                $this->info("🎵 Forcing audio analysis (--force-audio flag used)");
            } else {
                $this->warn("⚠️  AI confidence too low (" . ($aiMetadata['confidence'] ?? 0) . "%) - trying audio analysis fallback");
            }
            
            // Try audio analysis fallback
            $audioMetadata = $this->processWithAudioAnalysis($audiobook);
            if ($audioMetadata && $audioMetadata['confidence'] >= $this->option('min-confidence')) {
                $this->info("✅ Audio analysis successful with " . $audioMetadata['confidence'] . "% confidence");
                $aiMetadata = $audioMetadata;
            } else {
                // Only skip if we tried due to low confidence, not if forced
                if (!$this->option('force-audio')) {
                    $this->warn("⚠️  Audio analysis also failed - skipping");
                    $currentProvider = config('services.ai.default_provider', 'gemini');
                    if ($currentProvider === 'gemini' && empty(config('services.gemini.api_key'))) {
                        $this->warn("   💡 Tip: Add GEMINI_API_KEY to your .env file to enable audio transcription");
                    } elseif ($currentProvider === 'claude' && empty(config('services.openai.api_key'))) {
                        $this->warn("   💡 Tip: Claude doesn't support audio transcription. Add OPENAI_API_KEY for fallback");
                    }
                    $this->skippedBooks[] = [
                        'path' => $audiobook['path'],
                        'reason' => 'Low AI confidence (tried audio analysis)'
                    ];
                    return;
                } else {
                    $this->warn("⚠️  Audio analysis failed but continuing due to --force-audio flag");
                    // Continue with original metadata if forced
                }
            }
        }

        $this->info("✅ AI processing successful (confidence: {$aiMetadata['confidence']}%)");
        
        // Check for multi-book directory pattern first
        $multiBookInfo = $this->detectMultiBookPattern($audiobook['name']);
        if ($multiBookInfo) {
            // Clean series name by removing author names
            $authors = is_array($aiMetadata['author']) ? $aiMetadata['author'] : [$aiMetadata['author']];
            $cleanedSeriesName = $this->cleanSeriesName($multiBookInfo['series_name'], $authors);
            $multiBookInfo['series_name'] = $cleanedSeriesName;
            
            $this->info("📚 Detected multi-book directory: {$cleanedSeriesName} [{$multiBookInfo['start_number']}-{$multiBookInfo['end_number']}]");
            
            // Analyze files to see if they can be split
            $splitGroups = $this->analyzeMultiBookFiles($audiobook, $multiBookInfo);
            
            if (count($splitGroups) >= 2) {
                $this->info("🔍 Found individual book files - will split during import");
                $this->processMultiBookSplit($audiobook, $multiBookInfo, $splitGroups, $aiMetadata);
                return;
            } else {
                $this->info("📖 No individual book files found - will create combined entry with multiple series numbers");
                // Update metadata to reflect multi-book nature
                $aiMetadata['series'] = $cleanedSeriesName;
                $aiMetadata['multi_book_numbers'] = $multiBookInfo['numbers'];
                $aiMetadata['title'] = $cleanedSeriesName; // Clean title
            }
        } else {
            // Extract series number from title if present (single book)
            $this->extractSeriesNumberFromTitle($aiMetadata);
        }
        
        // Check for duplicates with AI-extracted metadata (more accurate than path-based check)
        
        $existingBook = $this->findExistingBook($audiobook['path'], $aiMetadata);
        if ($existingBook) {
            $this->warn("⚠️  Book already exists (detected after AI processing)");
            $this->line("  Found existing book: '{$existingBook->title}' (ID: {$existingBook->id})");
            
            // Get the existing book's directory path in the library
            $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
            if ($bookStoragePath && $existingBook->directory_path) {
                $existingDir = $bookStoragePath . '/' . $existingBook->directory_path;
                
                // Compare directories to see if they're identical
                if (File::isDirectory($existingDir)) {
                    $comparison = $this->compareDirectories($audiobook['path'], $existingDir);
                    
                    if ($comparison['identical']) {
                        $this->info("🔍 Source and existing directories are identical - cleaning up source");
                        $this->cleanupSourceDirectory($audiobook, true);
                        $this->skippedBooks[] = [
                            'path' => $audiobook['path'],
                            'reason' => 'Duplicate - source cleaned up (identical to existing)'
                        ];
                        return;
                    } else {
                        $this->warn("📁 Directories differ - manual decision needed");
                        $this->line("🔍 Debug: Comparison data structure exists: " . (is_array($comparison) ? 'YES' : 'NO'));
                        if (is_array($comparison)) {
                            $this->line("🔍 Debug: Keys present: " . implode(', ', array_keys($comparison)));
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
                                $this->info("🗑️ Removing existing directory to replace with source");
                                File::deleteDirectory($existingDir);
                                break;
                                
                            case '3':
                                // Delete source, keep existing
                                $this->info("🗑️ Removing source directory, keeping existing");
                                $this->cleanupSourceDirectory($audiobook, true);
                                $this->skippedBooks[] = [
                                    'path' => $audiobook['path'],
                                    'reason' => 'User chose to keep existing over source'
                                ];
                                return;
                                
                            case '4':
                                // Import with renamed directory - store preference for later
                                $this->info("📁 Will import with renamed directory to avoid conflict");
                                $aiMetadata['_force_rename_directory'] = true;
                                break;
                                
                            case '1':
                            default:
                                // Skip import completely
                                $this->info("📁 Skipping import, leaving both directories unchanged");
                                $this->skippedBooks[] = [
                                    'path' => $audiobook['path'],
                                    'reason' => 'User chose to skip import (directory conflict)'
                                ];
                                return;
                        }
                    }
                } else {
                    $this->warn("📁 Cannot compare directories (existing directory not found)");
                    $this->line("  Existing path: {$existingDir}");
                    $shouldContinue = $this->promptForDuplicateAction($audiobook, $existingBook);
                    if (!$shouldContinue) return;
                }
            } else {
                $this->warn("📁 Cannot compare directories (storage path or directory path missing)");
                $shouldContinue = $this->promptForDuplicateAction($audiobook, $existingBook);
                if (!$shouldContinue) return;
            }
        }
        
        // Step 2: External data enrichment (before manual review)
        if (!$this->option('skip-enrichment')) {
            $this->info("🔍 Attempting to enrich with external data...");
            $enrichedData = $this->enrichWithExternalData($aiMetadata);
            if ($enrichedData) {
                if ($this->getEnrichmentService()->isValidEnrichment($aiMetadata, $enrichedData)) {
                    $aiMetadata = array_merge($aiMetadata, $enrichedData);
                    $this->info("✅ Found enrichment data!");
                } else {
                    $this->warn("⚠️  Invalid enrichment data - skipping merge.");
                }
            } else {
                $this->warn("⚠️  No enrichment data found");
            }
        }
        
        $this->newLine();
        
        // Add source path for display and processing
        $aiMetadata['source_path'] = $audiobook['path'];
        $this->displayEnrichedMetadata($aiMetadata);
        $this->newLine();
        
        // Show expected directory path
        $expectedPath = $this->getImportService()->generateDirectoryPath($aiMetadata);
        $this->info("📁 Expected directory path: {$expectedPath}");

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
            $spinner = $this->output->createProgressBar();
            $spinner->setFormat(" %message%");
            $spinner->setMessage("💾 Creating database record...");
            $spinner->start();
            
            $book = $this->getImportService()->createBookFromMetadata($aiMetadata, $audiobook);
            
            $spinner->finish();
            $this->output->write("\r\033[K");
            
            if ($book) {
                $this->info("✅ Book imported successfully: {$book->title} (ID: {$book->id})");
                
                // Step 5: Move/copy files
                $options = [
                    'operation' => $this->option('copy-files') ? 'copy' : 'move'
                ];
                $success = $this->getImportService()->moveFilesToLibrary($audiobook, $book, $options);
                
                if ($success) {
                    $this->info("📁 Files " . ($this->option('copy-files') ? 'copied' : 'moved') . " to library successfully");
                    
                    $this->processedBooks[] = [
                        'path' => $audiobook['path'],
                        'book_id' => $book->id,
                        'title' => $book->title
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
                        'error' => 'File operation failed - book record cleaned up'
                    ];
                    return;
                }
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

            // Debug: Show what we're passing to the AI
            $this->line("🔍 AI Input Debug:");
            $this->line("  Directory: " . $audiobook['path']);
            $this->line("  Files (" . count($fileNames) . "): " . implode(', ', array_slice($fileNames, 0, 5)) . (count($fileNames) > 5 ? '...' : ''));
            $this->line("  Has NFO: " . (empty($nfoData) ? 'No' : 'Yes'));
            $this->line("  Has Tags: " . (empty($fileTags) ? 'No' : count($fileTags) . ' files'));

            // Process with AI, passing NFO data as priority information
            $aiResult = $this->aiProcessor->processBookDirectory(
                $audiobook['path'],
                $fileNames,
                $fileTags,
                $nfoData
            );
            
            // Debug: Show raw AI output before post-processing
            if ($aiResult) {
                $this->line("🤖 Raw AI Output:");
                $this->line("  Title: " . ($aiResult['title'] ?? 'N/A'));
                $this->line("  Series: " . ($aiResult['series'] ?? 'N/A'));
                $this->line("  Series Number: " . ($aiResult['series_number'] ?? 'N/A'));
                $this->line("  Author: " . (is_array($aiResult['author'] ?? []) ? implode(', ', $aiResult['author']) : ($aiResult['author'] ?? 'N/A')));
                
                // Post-process AI result to fix common issues with numbered series books
                $aiResult = $this->postProcessAIResult($aiResult, $audiobook);
            }
            
            return $aiResult;
        } catch (\Exception $e) {
            $this->error("❌ AI processing failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Process audiobook using audio analysis fallback when metadata extraction fails
     */
    protected function processWithAudioAnalysis(array $audiobook): ?array
    {
        try {
            $this->line("🎵 Attempting audio analysis of first 30 seconds...");
            
            // Get the first audio file (alphanumerically sorted)
            if (empty($audiobook['files'])) {
                return null;
            }
            
            // Sort files alphanumerically to ensure we get the intro file first
            $sortedFiles = $audiobook['files'];
            sort($sortedFiles, SORT_STRING);
            
            if (empty($sortedFiles)) {
                $this->warn("⚠️  No audio files found for transcription");
                return null;
            }
            
            $firstAudioFile = $sortedFiles[0];
            
            $this->line("📁 Using first audio file: " . basename($firstAudioFile));
            if (!file_exists($firstAudioFile)) {
                return null;
            }
            
            // Extract first 30 seconds using ffmpeg
            $tempAudioFile = tempnam(sys_get_temp_dir(), 'audio_sample_') . '.mp3';
            $ffmpegCommand = sprintf(
                'ffmpeg -i %s -t 30 -acodec libmp3lame -ab 64k %s -y 2>/dev/null',
                escapeshellarg($firstAudioFile),
                escapeshellarg($tempAudioFile)
            );
            
            exec($ffmpegCommand, $output, $returnCode);
            
            if ($returnCode !== 0 || !file_exists($tempAudioFile)) {
                $this->warn("⚠️  Failed to extract audio sample");
                return null;
            }
            
            $this->line("🎵 Audio sample extracted, sending to AI for transcription...");
            
            // Send audio to AI for transcription and analysis
            $audioAnalysis = $this->aiProcessor->processAudioSample(
                $tempAudioFile,
                basename($audiobook['path'])
            );
            
            // Clean up temp file
            @unlink($tempAudioFile);
            
            if ($audioAnalysis) {
                $this->line("🎵 Audio transcription successful");
                $this->line("  Transcribed: " . substr($audioAnalysis['transcription'] ?? '', 0, 100) . "...");
                return $audioAnalysis;
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->error("❌ Audio analysis failed: " . $e->getMessage());
            return null;
        }
    }


    /**
     * Post-process AI result to fix common issues with numbered series books
     */
    protected function postProcessAIResult(array $aiResult, array $audiobook): array
    {
        $directoryName = basename($audiobook['path']);
        $parentDirectory = basename(dirname($audiobook['path']));
        
        // Pattern 1: "05 - Convergence" 
        if (preg_match('/^(\d{1,2})\s*-\s*(.+)$/', $directoryName, $matches)) {
            $bookNumber = (int)$matches[1];
            $bookTitle = trim($matches[2]);
            
            $this->line("🔧 Post-processing: Detected numbered book '{$bookTitle}' (#{$bookNumber})");
            
            // Check if AI put the series name in the title field incorrectly
            $aiTitle = $aiResult['title'] ?? '';
            $aiSeries = $aiResult['series'] ?? '';
            
            // If the AI title doesn't match the directory title, it might have extracted series as title
            if (strcasecmp($aiTitle, $bookTitle) !== 0) {
                $this->line("  AI title '{$aiTitle}' doesn't match directory title '{$bookTitle}'");
                
                // If AI title looks like a series name and series field is empty/different
                if (empty($aiSeries) || strcasecmp($aiTitle, $aiSeries) !== 0) {
                    $this->line("  Moving AI title to series field and using directory title");
                    $aiResult['series'] = $aiTitle;  // AI title becomes series
                    $aiResult['title'] = $bookTitle;  // Directory title becomes book title
                } else {
                    $this->line("  Using directory title, keeping AI series");
                    $aiResult['title'] = $bookTitle;
                }
            }
            
            // Set the series number from directory
            $aiResult['series_number'] = $bookNumber;
            
            $this->line("  Final: Title='{$aiResult['title']}', Series='{$aiResult['series']}' #{$bookNumber}");
        }
        // Pattern 2: "Series Name, Book 02 - Actual Title"
        elseif (preg_match('/^(.+),\s*Book\s*(\d{1,2})\s*-\s*(.+)$/', $directoryName, $matches)) {
            $seriesName = trim($matches[1]);
            $bookNumber = (int)$matches[2];
            $bookTitle = trim($matches[3]);
            
            $this->line("🔧 Post-processing: Detected series book '{$bookTitle}' from '{$seriesName}' series (#{$bookNumber})");
            
            // Override AI result with directory-based extraction
            $aiResult['series'] = $seriesName;
            $aiResult['title'] = $bookTitle;
            $aiResult['series_number'] = $bookNumber;
            
            $this->line("  Final: Title='{$bookTitle}', Series='{$seriesName}' #{$bookNumber}");
        }
        
        // Apply series name removal from title if it contains colons
        if (!empty($aiResult['title'])) {
            $originalTitle = $aiResult['title'];
            $cleanedTitle = $this->removeSeriesFromTitle($originalTitle);
            
            if ($cleanedTitle !== $originalTitle) {
                $this->line("🧹 Cleaned series from title: '{$originalTitle}' → '{$cleanedTitle}'");
                $aiResult['title'] = $cleanedTitle;
            }
        }
        
        return $aiResult;
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
        
        // Helper function specifically for authors (uses & separator)
        $formatAuthors = function($authors) {
            if (is_array($authors)) {
                $filtered = array_filter($authors, function($v) {
                    return !is_array($v) && !is_object($v) && $v !== null && $v !== '';
                });
                return implode(' & ', $filtered);
            }
            return $authors ?? 'N/A';
        };

        // Clean series name for display
        $displaySeries = '';
        if (!empty($metadata['series'])) {
            $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
            $cleanedSeriesName = $this->cleanSeriesName($metadata['series'], $authors);
            $displaySeries = $cleanedSeriesName . ($metadata['series_number'] ? " #{$metadata['series_number']}" : '');
        }

        // Build the basic metadata table
        $tableData = [
            ['Title', $arrayToString($metadata['title'])],
            ['Author', $formatAuthors($metadata['author'])],
            ['Narrator', $arrayToString($metadata['narrator'])],
            ['Series', $displaySeries],
            ['Genre', $arrayToString($metadata['genre'])],
            ['Year', $metadata['year'] ?? 'N/A'],
            ['Publisher', $arrayToString($metadata['publisher'])],
            ['Language', $metadata['language'] ?? 'N/A'],
            ['ISBN', $metadata['isbn'] ?? 'N/A'],
            ['Confidence', $metadata['confidence'] . '%'],
        ];
        
        // Add source and directory paths
        if (!empty($metadata['source_path'])) {
            $displayPath = $this->formatSourcePathForDisplay($metadata['source_path']);
            $tableData[] = ['Source Path', $displayPath];
        }
        
        // Calculate and add expected directory path (including book title)
        $basePath = $this->getImportService()->generateDirectoryPath($metadata);
        $expectedPath = $basePath . '/' . ($metadata['title'] ?? 'Unknown Title');
        $tableData[] = ['Directory Path', $expectedPath];
        
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
    protected function displayCoverImage(string $imageUrl): void
    {
        // Check if we're in a terminal that supports image display
        $term = getenv('TERM_PROGRAM') ?: getenv('TERM');
        $termEnv = getenv('TERM') ?? '';
        $termProgram = getenv('TERM_PROGRAM') ?? '';
        
        $kittySupport = $termEnv === 'xterm-kitty' || 
                       $termEnv === 'xterm-ghostty' ||
                       strpos($termEnv, 'kitty') !== false ||
                       $termProgram === 'ghostty';
        
        if ($kittySupport || in_array($term, ['Ghostty', 'iTerm.app', 'WezTerm'])) {
            try {
                // Download and process image
                $imageData = @file_get_contents($imageUrl);
                
                if ($imageData) {
                    $this->line("\n📸 Cover Preview: {$imageUrl}");
                    
                    if ($kittySupport) {
                        // Use Kitty graphics protocol (supported by Ghostty)
                        $this->displayKittyImage($imageData);
                    } elseif ($term === 'iTerm.app') {
                        // iTerm2 inline image protocol
                        $base64Image = base64_encode($imageData);
                        $this->line("\033]1337;File=inline=1;width=200px;height=150px:{$base64Image}\007");
                    }
                    
                    $this->line(""); // Add spacing after image
                } else {
                    $this->line("📸 Cover available: {$imageUrl}");
                }
            } catch (\Exception $e) {
                $this->line("📸 Cover available: {$imageUrl} (display error: {$e->getMessage()})");
            }
        } else {
            $this->line("📸 Cover available: {$imageUrl}");
        }
    }

    /**
     * Display image using Kitty graphics protocol or kitten icat
     */
    protected function displayKittyImage(string $imageData): void
    {
        // Create temporary file for thumbnail
        $tempFile = tempnam(sys_get_temp_dir(), 'cover_') . '.png';
        
        try {
            // Get image info from original data
            $tempOriginal = tempnam(sys_get_temp_dir(), 'orig_') . '.jpg';
            file_put_contents($tempOriginal, $imageData);
            
            $imageInfo = getimagesize($tempOriginal);
            if ($imageInfo === false) {
                $this->line("  (Could not read image dimensions)");
                return;
            }
            $width = $imageInfo[0] ?? 200;
            $height = $imageInfo[1] ?? 300;
            
            // Calculate thumbnail size (max 200px wide, maintain aspect ratio)
            $maxWidth = 200;
            $scale = min($maxWidth / $width, $maxWidth / $height);
            $thumbWidth = (int)($width * $scale);
            $thumbHeight = (int)($height * $scale);
            
            // Create thumbnail
            $thumb = $this->createThumbnail($tempOriginal, $thumbWidth, $thumbHeight);
            if ($thumb) {
                // Save thumbnail as PNG
                imagepng($thumb, $tempFile);
                imagedestroy($thumb);
                
                // Use kitten icat directly since it works
                if (file_exists('/usr/bin/kitten') && is_executable('/usr/bin/kitten')) {
                    system("kitten icat --align=left '$tempFile' 2>/dev/null");
                } else {
                    // Fallback to protocol if kitten not available
                    $base64Image = base64_encode(file_get_contents($tempFile));
                    fwrite(STDOUT, "\033_Ga=T,f=100;{$base64Image}\033\\");
                    echo "\n";
                }
            } else {
                $this->line("  (Could not create thumbnail)");
            }
            
            @unlink($tempOriginal);
        } catch (\Exception $e) {
            $this->line("  (Image display error: " . $e->getMessage() . ")");
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Create thumbnail image
     */
    protected function createThumbnail(string $imagePath, int $width, int $height)
    {
        // Check if GD extension is available
        if (!extension_loaded('gd')) {
            return null;
        }
        
        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) return null;
        
        $mime = $imageInfo['mime'];
        
        // Create source image
        switch ($mime) {
            case 'image/jpeg':
                $source = imagecreatefromjpeg($imagePath);
                break;
            case 'image/png':
                $source = imagecreatefrompng($imagePath);
                break;
            case 'image/gif':
                $source = imagecreatefromgif($imagePath);
                break;
            default:
                return null;
        }
        
        if (!$source) return null;
        
        // Create thumbnail
        $thumb = imagecreatetruecolor($width, $height);
        
        // Preserve transparency for PNG
        if ($mime === 'image/png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefill($thumb, 0, 0, $transparent);
        }
        
        // Resize
        imagecopyresampled(
            $thumb, $source,
            0, 0, 0, 0,
            $width, $height,
            $imageInfo[0], $imageInfo[1]
        );
        
        imagedestroy($source);
        return $thumb;
    }

    /**
     * Manual review and approval
     */
    protected function reviewAndApprove(array &$metadata, array $audiobook = []): bool
    {
        
        // If no enrichment data found, assume detected fields are wrong and skip auto-approval
        if (!$this->hasEnrichmentData($metadata)) {
            $this->warn("⚠️  No external enrichment data found - detected fields may be incorrect");
            $this->info("📝 Please review and edit the metadata:");
        } else {
            // Ask if user wants to accept all fields as shown
            $this->line("\nOptions:");
            $this->line("1. (A)ccept all metadata as shown");
            $this->line("2. (E)dit individual fields");
            $this->line("3. (P)ath - edit directory path only");
            $this->line("4. (S)kip this book");
            
            // Default to accept all if confidence is over 80%, otherwise default to edit
            $confidence = $metadata['confidence'] ?? 0;
            $defaultChoice = $confidence > 80 ? '1' : '2';
            $confidenceNote = $confidence > 80 ? " (high confidence: {$confidence}%)" : " (confidence: {$confidence}%)";
            
            // Prepare background tasks for potential next books
            $backgroundTasks = [
                ['type' => 'scan_directory', 'data' => $audiobook],
                ['type' => 'duplicate_check', 'data' => $audiobook]
            ];
            
            $choice = $this->askWithBackground("Choose an option (1-4)", $defaultChoice, $backgroundTasks);
            
            // Normalize choice to handle letters
            $choice = strtolower(trim($choice));
            if (in_array($choice, ['a', 'accept'])) $choice = '1';
            if (in_array($choice, ['e', 'edit'])) $choice = '2';
            if (in_array($choice, ['p', 'path'])) $choice = '3';
            if (in_array($choice, ['s', 'skip'])) $choice = '4';
            
            switch ($choice) {
                case '1':
                    return true;
                case '2':
                    // Continue to field editing below
                    break;
                case '3':
                    // Edit directory path only
                    $metadata = $this->editDirectoryPathOnly($metadata, $audiobook);
                    if ($this->inputInterrupted) return false;
                    return true;
                case '4':
                    return false;
                default:
                    // Use the determined default behavior
                    if ($defaultChoice === '1') {
                        return true;
                    }
                    break;
            }
        }
        
        // Offer individual field editing
        $this->info("📝 Edit individual fields (press Enter to keep current value):");
        $metadata = $this->editMetadataFields($metadata, $audiobook);
        if ($this->inputInterrupted) return $metadata;
        
        // Show updated metadata with new directory path
        $metadata['source_path'] = $audiobook['path'] ?? '';
        $expectedPath = $this->getImportService()->generateDirectoryPath($metadata);
        $this->newLine();
        $this->info("📁 Updated directory path: {$expectedPath}");
        $this->displayEnrichedMetadata($metadata);
        $this->newLine();
        
        // If we started with no enrichment data, automatically try to enrich with the edited metadata
        if (!$this->hasEnrichmentData($metadata) && !$this->option('skip-enrichment')) {
            $this->info("🔍 Attempting to enrich with edited metadata...");
            $enrichedData = $this->enrichWithExternalData($metadata);
            if ($enrichedData) {
                if ($this->getEnrichmentService()->isValidEnrichment($metadata, $enrichedData)) {
                    $metadata = array_merge($metadata, $enrichedData);
                    $this->info("✅ Found enrichment data with edited metadata!");
                    $this->newLine();
                    $this->displayEnrichedMetadata($metadata);
                    $this->newLine();
                } else {
                    $this->warn("⚠️  Invalid enrichment data - skipping merge.");
                }
            } else {
                $this->warn("⚠️  Still no enrichment data found");
            }
        }

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
            if ($choice === 'a' || $choice === 'accept') $choice = '1';
            if ($choice === 'e' || $choice === 'edit') $choice = '2';
            if ($choice === 'p' || $choice === 'path') $choice = '3';
            if ($choice === 's' || $choice === 'skip') $choice = '4';
            
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
                    
                    // Re-enrich after editing
                    if (!$this->option('skip-enrichment')) {
                        $this->info("🔍 Attempting to enrich with re-edited metadata...");
                        $enrichedData = $this->enrichWithExternalData($metadata);
                        if ($enrichedData) {
                            if ($this->getEnrichmentService()->isValidEnrichment($metadata, $enrichedData)) {
                                $metadata = array_merge($metadata, $enrichedData);
                                $this->info("✅ Found enrichment data with re-edited metadata!");
                                $this->displayEnrichedMetadata($metadata);
                            } else {
                                $this->warn("⚠️  Invalid enrichment data - skipping merge.");
                            }
                        } else {
                            $this->warn("⚠️  Still no enrichment data found");
                        }
                    }
                    // Continue the loop to ask again
                    break;
                case '3':
                    // Edit directory path only
                    $metadata = $this->editDirectoryPathOnly($metadata, $audiobook);
                    if ($this->inputInterrupted) return false;
                    return true;
                case '4':
                    return false;
                default:
                    $this->warn("Please choose 1-4, or use first letters (A/E/P/S)");
                    // No break here, so it will re-prompt
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
        if ($this->inputInterrupted) return $metadata;
        if ($newTitle !== ($metadata['title'] ?? '')) {
            // Only trim whitespace for user-entered titles
            $metadata['title'] = trim($newTitle);
        }

        // Edit author
        $currentAuthor = is_array($metadata['author']) ? implode(', ', $metadata['author']) : ($metadata['author'] ?? '');
        $newAuthor = $this->askWithImmediateInterrupt("Author(s) (comma-separated)", $currentAuthor);
        if ($this->inputInterrupted) return $metadata;
        if ($newAuthor !== $currentAuthor) {
            $metadata['author'] = array_map('trim', explode(',', $newAuthor));
        }

        // Edit narrator
        $currentNarrator = is_array($metadata['narrator']) ? implode(', ', $metadata['narrator']) : ($metadata['narrator'] ?? '');
        $newNarrator = $this->askWithImmediateInterrupt("Narrator(s) (comma-separated)", $currentNarrator);
        if ($this->inputInterrupted) return $metadata;
        if ($newNarrator !== $currentNarrator) {
            $metadata['narrator'] = array_map('trim', explode(',', $newNarrator));
        }

        // Edit genre
        $currentGenre = is_array($metadata['genre']) ? implode(', ', $metadata['genre']) : ($metadata['genre'] ?? '');
        $newGenre = $this->askWithImmediateInterrupt("Genre", $currentGenre);
        if ($this->inputInterrupted) return $metadata;
        if ($newGenre !== $currentGenre) {
            $metadata['genre'] = $newGenre;
        }

        // Edit series
        $currentSeries = $metadata['series'] ?? '';
        $newSeries = $this->askWithImmediateInterrupt("Series", $currentSeries);
        if ($this->inputInterrupted) return $metadata;
        if ($newSeries !== $currentSeries) {
            $metadata['series'] = $newSeries;
        }

        // Edit series number
        $currentSeriesNumber = $metadata['series_number'] ?? '';
        $newSeriesNumber = $this->askWithImmediateInterrupt("Series Number", $currentSeriesNumber);
        if ($this->inputInterrupted) return $metadata;
        if ($newSeriesNumber !== $currentSeriesNumber) {
            $metadata['series_number'] = $newSeriesNumber;
        }

        // Edit year
        $currentYear = $metadata['year'] ?? '';
        $newYear = $this->askWithImmediateInterrupt("Year", $currentYear);
        if ($this->inputInterrupted) return $metadata;
        if ($newYear !== $currentYear) {
            $metadata['year'] = $newYear;
        }

        // Edit directory path
        $currentPath = $this->getImportService()->generateDirectoryPath($metadata);
        $newPath = $this->askWithImmediateInterrupt("Directory Path (relative to library root)", $currentPath);
        if ($this->inputInterrupted) return $metadata;
        if ($newPath !== $currentPath) {
            $metadata['custom_directory_path'] = trim($newPath);
        }

        // Extract series number from edited title if present
        $this->extractSeriesNumberFromTitle($metadata);
        
        return $metadata;
    }

    /**
     * Detect multi-book directory patterns like "Series [2-3]" or "Series [1-4]"
     */
    protected function detectMultiBookPattern(string $title): ?array
    {
        // Patterns for multi-book directories
        $patterns = [
            '/^(.+?)\s*\[(\d+)-(\d+)\]$/i',          // "Series [2-3]"
            '/^(.+?)\s*\[(\d+)–(\d+)\]$/i',          // "Series [2–3]" (em dash)
            '/^(.+?)\s*\[(\d+)—(\d+)\]$/i',          // "Series [2—3]" (em dash variant)
            '/^(.+?)\s*\((\d+)-(\d+)\)$/i',          // "Series (2-3)"
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                $seriesName = trim($matches[1]);
                $startNum = (int)$matches[2];
                $endNum = (int)$matches[3];
                
                if ($startNum < $endNum && $endNum - $startNum <= 10) { // Reasonable range limit
                    return [
                        'series_name' => $seriesName,
                        'start_number' => $startNum,
                        'end_number' => $endNum,
                        'numbers' => range($startNum, $endNum)
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Analyze files in multi-book directory to determine if they can be split
     */
    protected function analyzeMultiBookFiles(array $audiobook, array $multiBookInfo): array
    {
        $files = $audiobook['files'];
        $numbers = $multiBookInfo['numbers'];
        $splitGroups = [];

        // Look for files that clearly indicate individual books
        foreach ($files as $file) {
            $filename = basename($file);
            
            // Check if filename contains any of the expected book numbers
            foreach ($numbers as $bookNum) {
                $patterns = [
                    "/\[0?{$bookNum}\]/i",                   // "[02]", "[2]" - bracket notation
                    "/book\s*0?{$bookNum}[^\d]/i",           // "Book 2", "Book 02"
                    "/part\s*0?{$bookNum}[^\d]/i",           // "Part 2", "Part 02"
                    "/vol\s*0?{$bookNum}[^\d]/i",            // "Vol 2", "Vol 02"
                    "/volume\s*0?{$bookNum}[^\d]/i",         // "Volume 2"
                    "/^0?{$bookNum}[\s\-_]/i",               // "2 - Title", "02_Title"
                    "/[\s\-_]0?{$bookNum}[\s\-_]/i",         // "Title_2_Chapter", "Title-02-"
                ];
                
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $filename)) {
                        if (!isset($splitGroups[$bookNum])) {
                            $splitGroups[$bookNum] = [];
                        }
                        
                        // Extract individual book title from filename
                        $bookTitle = $this->extractBookTitleFromFilename($filename, $multiBookInfo['series_name'], $bookNum);
                        
                        $splitGroups[$bookNum][] = [
                            'file' => $file,
                            'title' => $bookTitle
                        ];
                        break 2; // Break both loops
                    }
                }
            }
        }

        return $splitGroups;
    }

    /**
     * Extract individual book title from filename
     */
    protected function extractBookTitleFromFilename(string $filename, string $seriesName, int $bookNumber): string
    {
        // Remove file extension
        $name = preg_replace('/\.[^.]+$/', '', $filename);
        
        // Remove author names if present at the beginning
        $name = preg_replace('/^[^-]+-\s*/', '', $name);
        
        // Remove series name if present
        $name = preg_replace('/' . preg_quote($seriesName, '/') . '\s*/i', '', $name);
        
        // Remove book number patterns
        $patterns = [
            "/\[0?{$bookNumber}\]\s*-?\s*/i",        // "[02] - " or "[2] - "
            "/book\s*0?{$bookNumber}\s*-?\s*/i",     // "Book 02 - " or "Book 2 - "
            "/part\s*0?{$bookNumber}\s*-?\s*/i",     // "Part 02 - "
            "/vol\s*0?{$bookNumber}\s*-?\s*/i",      // "Vol 02 - "
            "/volume\s*0?{$bookNumber}\s*-?\s*/i",   // "Volume 02 - "
        ];
        
        foreach ($patterns as $pattern) {
            $name = preg_replace($pattern, '', $name);
        }
        
        // Clean up remaining separators and whitespace
        $name = preg_replace('/^[\s\-_]+|[\s\-_]+$/', '', $name);
        $name = trim($name);
        
        // If we couldn't extract a meaningful title, use series name + book number
        if (empty($name) || strlen($name) < 2) {
            $name = $seriesName . " Book " . $bookNumber;
        }
        
        return $name;
    }

    /**
     * Clean series name by removing author names if present
     */
    protected function cleanSeriesName(string $seriesName, array $authors): string
    {
        $originalSeries = $seriesName;
        $cleanedSeries = $seriesName;
        
        // Preserve GraphicAudio markers - extract and reapply later
        $graphicAudioMarker = '';
        if (preg_match('/\(Graphic\s*Audio\)/i', $cleanedSeries, $matches)) {
            $graphicAudioMarker = ' (GraphicAudio)';
            $cleanedSeries = preg_replace('/\(Graphic\s*Audio\)/i', '', $cleanedSeries);
        }
        
        // First try to remove the complete author list as a combined string
        // Try both comma and & separators since both are common
        $combinedAuthorsComma = implode(', ', $authors);
        $combinedAuthorsAmpersand = implode(' & ', $authors);
        
        $combinedPatterns = [
            // Patterns with comma separator
            '/^' . preg_quote($combinedAuthorsComma, '/') . '\s*-\s*/i',     // "Author1, Author2, Author3 - Series"
            '/^' . preg_quote($combinedAuthorsComma, '/') . '\s+/i',         // "Author1, Author2, Author3 Series"
            '/\s*-\s*' . preg_quote($combinedAuthorsComma, '/') . '$/i',     // "Series - Author1, Author2, Author3"
            // Patterns with & separator  
            '/^' . preg_quote($combinedAuthorsAmpersand, '/') . '\s*-\s*/i', // "Author1 & Author2 & Author3 - Series"
            '/^' . preg_quote($combinedAuthorsAmpersand, '/') . '\s+/i',     // "Author1 & Author2 & Author3 Series"
            '/\s*-\s*' . preg_quote($combinedAuthorsAmpersand, '/') . '$/i', // "Series - Author1 & Author2 & Author3"
        ];
        
        foreach ($combinedPatterns as $pattern) {
            $before = $cleanedSeries;
            $cleanedSeries = preg_replace($pattern, '', $cleanedSeries);
            if ($before !== $cleanedSeries) {
                // If we found a match with combined authors, we can return early
                $cleanedSeries = preg_replace('/^[\s\-_]+|[\s\-_]+$/', '', $cleanedSeries);
                $cleanedSeries = trim($cleanedSeries);
                if (!empty($cleanedSeries) && strlen($cleanedSeries) >= 2) {
                    return $cleanedSeries . $graphicAudioMarker;
                }
            }
        }
        
        // If combined pattern didn't work, try individual authors
        foreach ($authors as $author) {
            $authorName = trim($author);
            
            // Try different patterns to remove author names from series
            $patterns = [
                '/^' . preg_quote($authorName, '/') . '\s*-\s*/i',     // "Author - Series"
                '/^' . preg_quote($authorName, '/') . '\s+/i',         // "Author Series"
                '/\s*-\s*' . preg_quote($authorName, '/') . '$/i',     // "Series - Author"
                '/\s+' . preg_quote($authorName, '/') . '$/i',         // "Series Author"
            ];
            
            foreach ($patterns as $pattern) {
                $cleanedSeries = preg_replace($pattern, '', $cleanedSeries);
            }
            
            // Also try with normalized author name (with periods)
            $normalizedAuthor = $this->normalizeAuthorName($authorName);
            if ($normalizedAuthor !== $authorName) {
                $patterns = [
                    '/^' . preg_quote($normalizedAuthor, '/') . '\s*-\s*/i',
                    '/^' . preg_quote($normalizedAuthor, '/') . '\s+/i',
                    '/\s*-\s*' . preg_quote($normalizedAuthor, '/') . '$/i',
                    '/\s+' . preg_quote($normalizedAuthor, '/') . '$/i',
                ];
                
                foreach ($patterns as $pattern) {
                    $cleanedSeries = preg_replace($pattern, '', $cleanedSeries);
                }
            }
        }
        
        // Clean up any remaining separators and whitespace
        $cleanedSeries = preg_replace('/^[\s\-_]+|[\s\-_]+$/', '', $cleanedSeries);
        $cleanedSeries = trim($cleanedSeries);
        
        // If we cleaned too much and ended up with nothing, return original
        if (empty($cleanedSeries) || strlen($cleanedSeries) < 2) {
            return $seriesName;
        }
        
        return $cleanedSeries . $graphicAudioMarker;
    }

    /**
     * Process multi-book directory by splitting into individual books
     */
    protected function processMultiBookSplit(array $audiobook, array $multiBookInfo, array $splitGroups, array $aiMetadata): void
    {
        $this->info("🔄 Processing {$multiBookInfo['series_name']} as split books...");
        
        foreach ($splitGroups as $bookNumber => $fileInfos) {
            $this->info("📖 Processing Book {$bookNumber} with " . count($fileInfos) . " files");
            
            // Extract files and get the title from the first file info
            if (empty($fileInfos)) {
                $this->warn("⚠️  No file info found for book {$bookNumber}, skipping");
                continue;
            }
            
            $files = array_map(function($fileInfo) { return $fileInfo['file']; }, $fileInfos);
            $bookTitle = $fileInfos[0]['title']; // Use title from first file
            
            $this->info("📚 Book title: {$bookTitle}");
            
            // Create metadata for this individual book
            $bookMetadata = $aiMetadata;
            $bookMetadata['title'] = $bookTitle; // Use extracted individual book title
            $bookMetadata['series'] = $multiBookInfo['series_name']; // This is already cleaned
            $bookMetadata['series_number'] = $bookNumber;
            
            // Ensure any original uncleaned series name is overwritten
            unset($bookMetadata['series_original']);
            
            // Create a virtual audiobook entry for this book
            $virtualAudiobook = [
                'path' => $audiobook['path'], // Keep original path
                'name' => $bookTitle,
                'files' => $files,
                'total_size' => array_sum(array_map('filesize', $files)),
                'is_multi_book_part' => true, // Flag to indicate this is part of a multi-book
                'multi_book_files_only' => $files // Specific files for this book
            ];
            
            // Process this book individually
            $this->processSingleBook($virtualAudiobook, $bookMetadata);
        }
    }

    /**
     * Process a single book (used for both regular books and split multi-books)
     */
    protected function processSingleBook(array $audiobook, array $metadata): void
    {
        // Continue with enrichment and import process
        if (!$this->option('skip-enrichment')) {
            $this->info("🔍 Enriching with external data...");
            $enrichedData = $this->enrichWithExternalData($metadata);
            if ($enrichedData) {
                if ($this->getEnrichmentService()->isValidEnrichment($metadata, $enrichedData)) {
                    $metadata = array_merge($metadata, $enrichedData);
                    $this->info("✅ External data enrichment completed");
                } else {
                    $this->warn("⚠️  Invalid enrichment data - skipping merge.");
                }
            }
        }
        
        // Show expected directory path
        $metadata['source_path'] = $audiobook['path']; // Add source path for GraphicAudio detection
        $expectedPath = $this->getImportService()->generateDirectoryPath($metadata);
        $this->info("📁 Expected directory path: {$expectedPath}");
        
        $this->displayEnrichedMetadata($metadata);

        // Manual review (unless in auto mode)
        if (!$this->option('auto') && !$this->option('dry-run')) {
            if (!$this->reviewAndApprove($metadata)) {
                $this->warn("❌ Import rejected by user");
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'Rejected by user'
                ];
                return;
            }
        } elseif ($this->option('auto') && !$this->hasEnrichmentData($metadata)) {
            // In auto mode, skip books with no enrichment data
            $this->warn("⚠️  No enrichment data found in auto mode - skipping (detected fields might be incorrect)");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'No enrichment data in auto mode'
            ];
            return;
        }

        // Import to database
        if (!$this->option('dry-run')) {
            $spinner = $this->output->createProgressBar();
            $spinner->setFormat(" %message%");
            $spinner->setMessage("💾 Creating database record...");
            $spinner->start();
            
            $book = $this->getImportService()->createBookFromMetadata($metadata, $audiobook);
            
            $spinner->finish();
            $this->output->write("\r\033[K");
            
            if ($book) {
                $this->info("✅ Book imported successfully: {$book->title} (ID: {$book->id})");
                
                // Move/copy files
                $options = [
                    'operation' => $this->option('copy-files') ? 'copy' : 'move'
                ];
                $success = $this->getImportService()->moveFilesToLibrary($audiobook, $book, $options);
                
                if ($success) {
                    $this->info("📁 Files " . ($this->option('copy-files') ? 'copied' : 'moved') . " to library successfully");
                    
                    $this->processedBooks[] = [
                        'path' => $audiobook['path'],
                        'book_id' => $book->id,
                        'title' => $book->title
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
                        'error' => 'File operation failed - book record cleaned up'
                    ];
                    return;
                }
            }
        } else {
            $this->info("🔍 [DRY RUN] Would import: {$metadata['title']}");
        }
    }

    /**
     * Extract series number from title and clean the title
     */
    protected function extractSeriesNumberFromTitle(array &$metadata): void
    {
        if (empty($metadata['title'])) {
            return;
        }

        $title = trim($metadata['title']);
        
        // Common patterns for book numbers in titles
        $patterns = [
            '/^(.+?),\s*Book\s+(\d+)$/i',            // "Title, Book 1"
            '/^(.+?)\s+Book\s+(\d+)$/i',             // "Title Book 1"
            '/^(.+?),\s*Volume\s+(\d+)$/i',          // "Title, Volume 1"
            '/^(.+?)\s+Volume\s+(\d+)$/i',           // "Title Volume 1"
            '/^(.+?),\s*#(\d+)$/i',                  // "Title, #1"
            '/^(.+?)\s+#(\d+)$/i',                   // "Title #1"
            '/^(.+?),\s*Part\s+(\d+)$/i',            // "Title, Part 1"  
            '/^(.+?)\s+Part\s+(\d+)$/i',             // "Title Part 1"
            '/^(.+?)\s+(\d+)$/',                     // "Title 1" (last resort)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                $cleanTitle = trim($matches[1]);
                $bookNumber = (int)$matches[2];

                // Apply the extraction
                $metadata['title'] = $cleanTitle;
                $metadata['series_number'] = $bookNumber;
                
                $this->info("📚 Extracted series number {$bookNumber} from title: '{$title}' → '{$cleanTitle}'");
                return; // Exit after first match
            }
        }
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
            $title = $metadata['title'];
            $author = is_array($metadata['author']) ? $metadata['author'][0] : $metadata['author'];
            $audibleData = $this->retryApiCall(function() use ($title, $author) {
                return $this->searchAudible($title, $author);
            }, 'Audible', "Searching Audible for '{$title}' by {$author}");
            
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
            $title = $metadata['title'];
            $author = is_array($metadata['author']) && !empty($metadata['author']) ? $metadata['author'][0] : $metadata['author'];
            $authorString = is_array($author) ? implode(', ', $author) : ($author ?: 'Unknown');
            
            // Ensure author is a string for the API call
            $authorForApi = is_array($author) ? implode(', ', $author) : ($author ?: '');
            
            $googleData = $this->retryApiCall(function() use ($title, $authorForApi) {
                return $this->searchGoogleBooks($title, $authorForApi);
            }, 'Google Books', "Searching Google Books for '{$title}' by {$authorString}");
            
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
                $metadata['source_path'] = $audiobook['path']; // Add source path for GraphicAudio detection
                $basePath = $this->getImportService()->generateDirectoryPath($metadata);
                $book->directory_path = $basePath . '/' . $metadata['title'];
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
                    // Clean series name by removing author names
                    $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
                    $cleanedSeriesName = $this->cleanSeriesName($metadata['series'], $authors);
                    
                    $series = Series::firstOrCreate(['name' => trim($cleanedSeriesName)]);
                    
                    // Handle multi-book entries (e.g., books 2-3 combined)
                    if (!empty($metadata['multi_book_numbers'])) {
                        $seriesData = [];
                        foreach ($metadata['multi_book_numbers'] as $number) {
                            $seriesData[$series->id] = ['series_number' => $number];
                        }
                        
                        // Note: This might require updating the pivot table to allow multiple entries
                        // For now, we'll use the first number and log the multi-book nature
                        $firstNumber = $metadata['multi_book_numbers'][0];
                        $lastNumber = end($metadata['multi_book_numbers']);
                        
                        $book->series()->sync([
                            $series->id => [
                                'series_number' => $firstNumber,
                                'series_end_number' => $lastNumber // This field may need to be added to the pivot table
                            ]
                        ]);
                        
                        $this->info("📚 Multi-book entry: Books {$firstNumber}-{$lastNumber} combined");
                    } else {
                        $seriesNumber = $metadata['series_number'] ?? 1;
                        $book->series()->sync([
                            $series->id => ['series_number' => $seriesNumber]
                        ]);
                    }
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
            $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
            // If the single author string contains commas, split it into array
            if (count($authors) === 1 && strpos($authors[0], ',') !== false) {
                $authors = array_map('trim', explode(',', $authors[0]));
            }
            
            // Check for existing author directory first (use cleaned series name)
            $cleanedSeries = null;
            if (!empty($metadata['series'])) {
                $cleanedSeries = $this->cleanSeriesName($metadata['series'], $authors);
            }
            
            $existingAuthorDir = $this->findExistingAuthorDirectory($authors, $cleanedSeries);
            
            if ($existingAuthorDir) {
                $this->info("📁 Found existing author directory: {$existingAuthorDir}");
                $parts[] = $existingAuthorDir;
            } else {
                // Use formatted author names with & separator and normalized initials
                $authorDir = $this->formatAuthorsForDirectory($authors);
                $parts[] = $authorDir;
            }
        }
        
        if (!empty($metadata['series'])) {
            // Clean series name by removing author names for directory path
            $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
            // Handle comma-separated authors here too
            if (count($authors) === 1 && strpos($authors[0], ',') !== false) {
                $authors = array_map('trim', explode(',', $authors[0]));
            }
            $cleanedSeriesName = $this->cleanSeriesName($metadata['series'], $authors);
            $parts[] = $cleanedSeriesName;
        }
        
        if (!empty($metadata['title'])) {
            $title = $metadata['title'];
            // If we have a series number, prefix it to the title
            if (!empty($metadata['series_number'])) {
                $seriesNumber = str_pad($metadata['series_number'], 2, '0', STR_PAD_LEFT);
                $title = $seriesNumber . ' ' . $title;
            }
            
            // Add GraphicAudio marker if detected from source directory or narrator
            $title = $this->addGraphicAudioMarker($title, $metadata);
            
            $parts[] = $title;
        }
        
        return implode('/', $parts);
    }

    /**
     * Add GraphicAudio marker if detected from source or metadata
     */
    protected function addGraphicAudioMarker(string $title, array $metadata): string
    {
        // Check if GraphicAudio marker is already present
        if (preg_match('/\(Graphic\s*Audio\)/i', $title)) {
            return preg_replace('/\(Graphic\s*Audio\)/i', '(GraphicAudio)', $title);
        }
        
        // Check various fields for GraphicAudio indicators
        $sourcePath = $metadata['source_path'] ?? '';
        $narrator = $metadata['narrator'] ?? '';
        $series = $metadata['series'] ?? '';
        $originalTitle = $metadata['original_title'] ?? $title;
        
        $isGraphicAudio = false;
        
        // Check source directory path
        if (preg_match('/\(Graphic\s*Audio\)/i', $sourcePath)) {
            $isGraphicAudio = true;
        }
        
        // Check narrator field (handle arrays)
        $narratorString = is_array($narrator) ? implode(' ', $narrator) : (string)$narrator;
        if (preg_match('/Graphic\s*Audio/i', $narratorString)) {
            $isGraphicAudio = true;
        }
        
        // Check series name
        if (is_string($series) && preg_match('/\(Graphic\s*Audio\)/i', $series)) {
            $isGraphicAudio = true;
        }
        
        // Check original title
        if (is_string($originalTitle) && preg_match('/\(Graphic\s*Audio\)/i', $originalTitle)) {
            $isGraphicAudio = true;
        }
        
        // Check if narrator contains typical GraphicAudio cast indicators
        $graphicAudioNarratorPatterns = [
            '/full\s*cast/i',
            '/ensemble\s*cast/i',
            '/multi\s*cast/i',
            '/cast\s*of\s*voices/i'
        ];
        
        foreach ($graphicAudioNarratorPatterns as $pattern) {
            if (preg_match($pattern, $narratorString)) {
                $isGraphicAudio = true;
                break;
            }
        }
        
        // Add GraphicAudio marker if detected
        if ($isGraphicAudio) {
            return $title . ' (GraphicAudio)';
        }
        
        return $title;
    }

    /**
     * Flatten CD subdirectories by moving all files to the main directory
     */
    protected function flattenCdDirectories(string $sourcePath): void
    {
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $cdPattern = '/^(cd|disc|disk)[\s_-]*(\d+)$/i';
        
        // Find CD subdirectories
        $cdDirs = [];
        if (File::isDirectory($sourcePath)) {
            $directories = glob($sourcePath . '/*', GLOB_ONLYDIR);
            
            foreach ($directories as $dir) {
                $dirName = basename($dir);
                if (preg_match($cdPattern, $dirName, $matches)) {
                    $cdNumber = (int)$matches[2];
                    $cdDirs[$cdNumber] = $dir;
                }
            }
        }
        
        if (empty($cdDirs)) {
            return; // No CD directories found
        }
        
        $this->info("📀 Found " . count($cdDirs) . " CD directories - flattening structure");
        
        // Get all files (not just audio) from all CD directories
        $allFiles = [];
        $audioConflicts = [];
        $duplicatesDeleted = 0;
        
        foreach ($cdDirs as $cdNumber => $cdDir) {
            $files = $this->getAllFilesFromDirectory($cdDir);
            
            foreach ($files as $file) {
                $filename = basename($file);
                $isAudioFile = in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $audioExtensions);
                
                // Delete torrent/piracy tracking files
                if ($this->isTorrentTrackingFile($filename)) {
                    File::delete($file);
                    $duplicatesDeleted++;
                    $this->line("  🗑️ Deleted tracking file: {$filename}");
                    continue;
                }
                
                $targetPath = $sourcePath . '/' . $filename;
                
                // Handle existing files in main directory
                if (File::exists($targetPath)) {
                    if ($isAudioFile) {
                        // Audio files get renamed with CD/track prefix
                        $trackNumber = $this->extractTrackNumber($filename);
                        $newFilename = sprintf('%02d-%02d %s', 
                            $cdNumber, 
                            $trackNumber ?: 1, 
                            $filename
                        );
                        $audioConflicts[] = $filename;
                        $allFiles[] = [
                            'source_path' => $file,
                            'new_name' => $newFilename,
                            'original_name' => $filename,
                            'type' => 'audio_conflict'
                        ];
                    } else {
                        // Non-audio files: check if identical, delete duplicate if so
                        if ($this->areFilesIdentical($file, $targetPath)) {
                            File::delete($file);
                            $duplicatesDeleted++;
                            $this->line("  🗑️ Deleted duplicate: {$filename}");
                            continue;
                        } else {
                            // Different files with same name - rename with CD prefix
                            $pathInfo = pathinfo($filename);
                            $newFilename = sprintf('CD%02d_%s.%s', 
                                $cdNumber, 
                                $pathInfo['filename'],
                                $pathInfo['extension'] ?? ''
                            );
                            $allFiles[] = [
                                'source_path' => $file,
                                'new_name' => $newFilename,
                                'original_name' => $filename,
                                'type' => 'other_conflict'
                            ];
                        }
                    }
                } else {
                    // No conflict - move as-is
                    $allFiles[] = [
                        'source_path' => $file,
                        'new_name' => $filename,
                        'original_name' => $filename,
                        'type' => 'no_conflict'
                    ];
                }
            }
        }
        
        if (!empty($audioConflicts)) {
            $this->line("  🔄 Renaming " . count($audioConflicts) . " conflicting audio files with CD-track prefix");
        }
        
        if ($duplicatesDeleted > 0) {
            $this->line("  🗑️ Deleted {$duplicatesDeleted} duplicate files");
        }
        
        // Move all files to main directory
        foreach ($allFiles as $fileInfo) {
            $sourceFilePath = $fileInfo['source_path'];
            $targetPath = $sourcePath . '/' . $fileInfo['new_name'];
            
            if (File::move($sourceFilePath, $targetPath)) {
                if ($fileInfo['type'] !== 'no_conflict') {
                    $this->line("  ✓ {$fileInfo['original_name']} → {$fileInfo['new_name']}");
                }
            } else {
                $this->warn("  ✗ Failed to move: {$fileInfo['original_name']}");
            }
        }
        
        // Remove now-empty CD directories
        foreach ($cdDirs as $cdDir) {
            if ($this->isDirectoryEmpty($cdDir)) {
                File::deleteDirectory($cdDir);
                $this->line("  🗑️ Removed empty directory: " . basename($cdDir));
            } else {
                $this->warn("  ⚠️  Directory not empty, keeping: " . basename($cdDir));
            }
        }
        
        $totalFiles = count($allFiles) + $duplicatesDeleted;
        $this->info("📀 CD flattening complete: {$totalFiles} files processed");
    }
    
    /**
     * Get all files from a directory recursively  
     */
    protected function getAllFilesFromDirectory(string $path): array
    {
        $files = [];
        
        if (!File::isDirectory($path)) {
            return $files;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    /**
     * Check if two files are identical by comparing size and hash
     */
    protected function areFilesIdentical(string $file1, string $file2): bool
    {
        if (!File::exists($file1) || !File::exists($file2)) {
            return false;
        }
        
        // Quick size check first
        if (File::size($file1) !== File::size($file2)) {
            return false;
        }
        
        // Compare file contents hash for small files or first 1MB for large files
        $maxHashSize = 1024 * 1024; // 1MB
        
        $size1 = File::size($file1);
        $size2 = File::size($file2);
        
        if ($size1 <= $maxHashSize && $size2 <= $maxHashSize) {
            // Hash entire file for small files
            return hash_file('md5', $file1) === hash_file('md5', $file2);
        } else {
            // Hash first 1MB for large files
            $handle1 = fopen($file1, 'rb');
            $handle2 = fopen($file2, 'rb');
            
            if (!$handle1 || !$handle2) {
                if ($handle1) fclose($handle1);
                if ($handle2) fclose($handle2);
                return false;
            }
            
            $chunk1 = fread($handle1, $maxHashSize);
            $chunk2 = fread($handle2, $maxHashSize);
            
            fclose($handle1);
            fclose($handle2);
            
            return hash('md5', $chunk1) === hash('md5', $chunk2);
        }
    }
    
    /**
     * Check if a filename indicates a torrent/piracy tracking file
     */
    protected function isTorrentTrackingFile(string $filename): bool
    {
        $filename = strtolower($filename);
        
        // Common torrent/piracy tracking file patterns
        $patterns = [
            '/torrent.*download.*from/i',           // "Torrent_downloaded_from_..."
            '/downloaded.*from.*\.txt$/i',          // "Downloaded from site.txt"
            '/\.torrent$/i',                        // .torrent files
            '/read.*me.*first.*\.txt$/i',          // "Read me first.txt"
            '/please.*seed.*\.txt$/i',             // "Please seed.txt"
            '/visit.*for.*more.*\.txt$/i',         // "Visit site for more.txt"
            '/source.*\.txt$/i',                   // "Source.txt"
            '/magnet.*link.*\.txt$/i',             // "Magnet link.txt"
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }
        
        // Check for specific known tracking file names
        $knownTrackingFiles = [
            'demonoid.me.txt',
            'piratebay.txt',
            'kickass.txt',
            'extratorrent.txt',
            'thepiratebay.org.txt',
            'rarbg.txt',
            'torrentday.txt',
            'iptorrents.txt',
            'what.cd.txt',
            'passthepopcorn.txt',
            'redacted.ch.txt',
            'orpheus.network.txt',
            'source.txt',
            'readme.txt',
            'read me.txt',
            'info.txt'
        ];
        
        foreach ($knownTrackingFiles as $trackingFile) {
            if (str_contains($filename, $trackingFile)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract track number from filename
     */
    protected function extractTrackNumber(string $filename): ?int
    {
        // Common track number patterns
        $patterns = [
            '/^(\d{1,3})[\s\-_\.]+/',           // "01 - Title.mp3"
            '/^Track[\s_]*(\d{1,3})/i',        // "Track 01.mp3"
            '/^(\d{1,3})\./',                  // "01.Title.mp3"
            '/[\s\-_](\d{1,3})[\s\-_\.]+/',    // "Chapter 01 - Title.mp3"
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                return (int)$matches[1];
            }
        }
        
        return null;
    }
    
    /**
     * Check if directory is empty (no files, only empty subdirectories allowed)
     */
    protected function isDirectoryEmpty(string $path): bool
    {
        if (!File::isDirectory($path)) {
            return true;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                return false;
            }
        }
        
        return true;
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
        
        // If directories are identical, automatically clean up source
        if ($comparison['identical']) {
            $this->info("🔍 Directories are identical - source will be automatically deleted");
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
        $this->line("4. Rename both directories by narrator: title (narrator)");
        $this->line("5. Cancel import");
        
        // Prepare background tasks for directory analysis
        $backgroundTasks = [
            ['type' => 'scan_directory', 'data' => ['path' => $audiobook['path']]],
            ['type' => 'scan_directory', 'data' => ['path' => $targetDir]]
        ];
        
        $choice = $this->askWithBackground("Choose an option (1-5)", '1', $backgroundTasks);
        
        switch ($choice) {
            case '1':
                return 'replace';
            case '2':
                return 'rename_existing';
            case '3':
                return 'rename_new';
            case '4':
                return 'rename_both_narrator';
            case '5':
                return 'cancel';
            default:
                return 'replace';
        }
    }
    
    /**
     * Rename both directories by narrator format: "title (narrator)"
     */
    protected function renameBothDirectoriesByNarrator(array $audiobook, string $targetDir, Book $book): void
    {
        $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
        
        // Get book title from the existing book in database
        $existingBook = Book::where('directory_path', str_replace($bookStoragePath . '/', '', $targetDir))->first();
        
        // Get narrator information for both books
        $existingNarrator = $this->getNarratorFromDirectory($targetDir, $existingBook);
        $newNarrator = $this->getNarratorFromMetadata($audiobook);
        
        // Generate new directory names with narrator format
        $existingTitle = $existingBook ? $existingBook->title : basename($targetDir);
        $newTitle = $book->title;
        
        $baseDir = dirname($targetDir);
        $existingNewPath = $baseDir . '/' . $this->createNarratorDirectoryName($existingTitle, $existingNarrator);
        $newImportPath = $baseDir . '/' . $this->createNarratorDirectoryName($newTitle, $newNarrator);
        
        // Rename existing directory
        if (File::exists($targetDir)) {
            File::move($targetDir, $existingNewPath);
            $this->info("📁 Renamed existing directory to: " . basename($existingNewPath));
            
            // Update database record for existing book
            if ($existingBook) {
                $existingBook->directory_path = str_replace($bookStoragePath . '/', '', $existingNewPath);
                $existingBook->save();
            }
        }
        
        // Move new files to narrator-named directory
        $this->moveFilesToNarratorDirectory($audiobook, $newImportPath, $book);
        
        // Update database record for new book
        $book->directory_path = str_replace($bookStoragePath . '/', '', $newImportPath);
        $book->save();
        
        $this->info("📁 Imported new files to: " . basename($newImportPath));
    }
    
    /**
     * Create directory name in format "title (narrator)"
     */
    protected function createNarratorDirectoryName(string $title, string $narrator): string
    {
        // Remove series names from title if they contain colons
        $cleanTitle = $this->removeSeriesFromTitle($title);
        
        // Simplify title - remove extra metadata like [05], [1986], etc.
        $cleanTitle = preg_replace('/\[[^\]]*\]/', '', $cleanTitle);
        $cleanTitle = preg_replace('/\{[^}]*\}/', '', $cleanTitle);
        $cleanTitle = trim($cleanTitle);
        
        // Clean up title and narrator for directory name (remove invalid filesystem characters)
        $cleanTitle = str_replace(['<', '>', ':', '"', '/', '\\', '|', '?', '*'], '', $cleanTitle);
        $cleanNarrator = str_replace(['<', '>', ':', '"', '/', '\\', '|', '?', '*'], '', $narrator);
        
        // Trim extra whitespace and normalize spaces
        $cleanTitle = preg_replace('/\s+/', ' ', trim($cleanTitle));
        $cleanNarrator = preg_replace('/\s+/', ' ', trim($cleanNarrator));
        
        if (empty($cleanNarrator) || $cleanNarrator === 'Unknown Narrator') {
            return $cleanTitle;
        }
        
        // Limit total directory name length to avoid filesystem issues
        $maxLength = 100; // Conservative limit for directory names
        $combined = "{$cleanTitle} ({$cleanNarrator})";
        
        if (strlen($combined) > $maxLength) {
            $availableForTitle = $maxLength - strlen($cleanNarrator) - 3; // 3 for " ()"
            if ($availableForTitle > 10) { // Ensure minimum title length
                $cleanTitle = substr($cleanTitle, 0, $availableForTitle) . '...';
                $combined = "{$cleanTitle} ({$cleanNarrator})";
            } else {
                // If narrator name is too long, just use title
                return substr($cleanTitle, 0, $maxLength);
            }
        }
        
        return $combined;
    }
    
    /**
     * Remove series names from title when they contain colons
     */
    protected function removeSeriesFromTitle(string $title): string
    {
        // Handle pattern "Series: Title" - remove series before colon
        if (preg_match('/^([^:]+):\s*(.+)$/', $title, $matches)) {
            $beforeColon = trim($matches[1]);
            $afterColon = trim($matches[2]);
            
            // Always prioritize the part after the colon as the title (e.g., "Battle Mage Farmer: Culmination" → "Culmination")
            // Exception: if the part after colon is clearly metadata (Book, Vol, etc.)
            if (preg_match('/^\b(book|vol|volume|part|chapter)\s*\d+/i', $afterColon)) {
                return $beforeColon; // Keep the part before colon
            }
            
            return $afterColon; // Return the title part
        }
        
        // Handle pattern "Title: Series" - remove series after colon
        if (preg_match('/^(.+?):\s*([^:]+)$/', $title, $matches)) {
            $beforeColon = trim($matches[1]);
            $afterColon = trim($matches[2]);
            
            // If the part after colon looks like metadata/series info, keep the part before
            if (strlen($afterColon) < strlen($beforeColon) || 
                preg_match('/\b(series|book|vol|volume|\d+|saga|chronicles|collection)\b/i', $afterColon)) {
                return $beforeColon;
            }
        }
        
        return $title;
    }
    
    /**
     * Get narrator information from audiobook metadata
     */
    protected function getNarratorFromMetadata(array $audiobook): string
    {
        // Check if narrator is in the audiobook metadata
        if (isset($audiobook['metadata']['narrator'])) {
            $narrator = $audiobook['metadata']['narrator'];
            if (is_array($narrator)) {
                return implode(', ', $narrator);
            }
            return $narrator;
        }
        
        // Try to extract narrator from directory name patterns
        $dirName = basename($audiobook['path']);
        
        // Look for patterns like "{Narrator}", "(Narrator)", "- Narrator", etc.
        $patterns = [
            '/\{([^}]+)\}/',           // {Larry A. McKeever}
            '/\(([^)]+)\)$/',          // (Narrator) at end
            '/\[([^\]]+)\]$/',         // [Narrator] at end
            '/ - ([^-]+)$/',           // - Narrator at end
            '/ narrated by ([^,]+)/i', // "narrated by Narrator"
            '/ read by ([^,]+)/i',     // "read by Narrator"
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $dirName, $matches)) {
                $narrator = trim($matches[1]);
                // Skip if it looks like a year, series info, or other metadata
                if (!preg_match('/^\d{4}$/', $narrator) && 
                    !preg_match('/\b(book|vol|volume|series|edition|unabridged|audiobook)\b/i', $narrator)) {
                    return $narrator;
                }
            }
        }
        
        return 'Unknown Narrator';
    }
    
    /**
     * Get narrator information from existing directory/book
     */
    protected function getNarratorFromDirectory(string $targetDir, ?Book $existingBook): string
    {
        if ($existingBook && !empty($existingBook->narrator)) {
            return $existingBook->narrator;
        }
        
        // Try to extract from directory name
        $dirName = basename($targetDir);
        
        // Look for patterns like "{Narrator}", "(Narrator)", "- Narrator", etc.
        $patterns = [
            '/\{([^}]+)\}/',           // {Larry A. McKeever}
            '/\(([^)]+)\)$/',          // (Narrator) at end
            '/\[([^\]]+)\]$/',         // [Narrator] at end
            '/ - ([^-]+)$/',           // - Narrator at end
            '/ narrated by ([^,]+)/i', // "narrated by Narrator"
            '/ read by ([^,]+)/i',     // "read by Narrator"
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $dirName, $matches)) {
                $narrator = trim($matches[1]);
                // Skip if it looks like a year, series info, or other metadata
                if (!preg_match('/^\d{4}$/', $narrator) && 
                    !preg_match('/\b(book|vol|volume|series|edition|unabridged|audiobook)\b/i', $narrator)) {
                    return $narrator;
                }
            }
        }
        
        return 'Unknown Narrator';
    }
    
    /**
     * Move files to narrator-named directory
     */
    protected function moveFilesToNarratorDirectory(array $audiobook, string $targetDir, Book $book): void
    {
        // Create the target directory
        File::makeDirectory($targetDir, 0775, true);
        
        // Flatten CD subdirectories before moving files
        $this->flattenCdDirectories($audiobook['path']);
        
        // Move files similar to the main moveFilesToLibrary method
        $copyFiles = $this->option('copy-files');
        $allFiles = File::allFiles($audiobook['path']);
        $filesToMove = array_map(function($file) { return $file->getPathname(); }, $allFiles);
        
        foreach ($filesToMove as $sourceFilePath) {
            $filename = basename($sourceFilePath);
            
            // Skip torrent/piracy tracking files
            if ($this->isTorrentTrackingFile($filename)) {
                File::delete($sourceFilePath);
                continue;
            }
            
            $relativePath = str_replace($audiobook['path'] . '/', '', $sourceFilePath);
            $targetFile = $targetDir . '/' . $relativePath;
            
            // Create subdirectories if needed
            $targetFileDir = dirname($targetFile);
            if (!File::isDirectory($targetFileDir)) {
                File::makeDirectory($targetFileDir, 0775, true);
            }
            
            // Move or copy the file with error handling
            try {
                if ($copyFiles) {
                    if (!File::copy($sourceFilePath, $targetFile)) {
                        $this->warn("❌ Failed to copy: {$filename}");
                        continue;
                    }
                } else {
                    if (!File::move($sourceFilePath, $targetFile)) {
                        $this->warn("❌ Failed to move: {$filename}");
                        continue;
                    }
                }
            } catch (\Exception $e) {
                $this->warn("❌ Error processing {$filename}: " . $e->getMessage());
                continue;
            }
        }
        
        // Clean up source directory if not copying
        if (!$copyFiles) {
            $this->cleanupSourceDirectory($audiobook, false);
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
            'target' => $targetFiles,
            'source_path' => $sourcePath,
            'target_path' => $targetPath
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
        
        // Handle individual files
        if (File::isFile($path)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, $audioExtensions)) {
                $size = filesize($path);
                $filename = basename($path);
                return [
                    'files' => [[
                        'name' => $filename,
                        'size' => $size,
                        'extension' => $extension,
                        'hash' => md5($filename . $size)
                    ]],
                    'total_size' => $size,
                    'file_types' => [$extension => 1],
                    'count' => 1
                ];
            } else {
                return [
                    'files' => [],
                    'total_size' => 0,
                    'file_types' => [],
                    'count' => 0
                ];
            }
        }
        
        // Handle directories
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
        $this->line("🔍 Debug: displayDirectoryComparison called");
        $this->line("🔍 Debug: Comparison keys: " . implode(', ', array_keys($comparison)));
        
        // Show the full paths first
        $this->line("");
        $this->line("📁 Directory Comparison:");
        $this->line("   Source:  " . ($comparison['source_path'] ?? 'N/A'));
        $this->line("   Target:  " . ($comparison['target_path'] ?? 'N/A'));
        $this->line("");
        
        if (isset($comparison['source']) && isset($comparison['target'])) {
            $this->line("🔍 Debug: Source data: " . json_encode($comparison['source']));
            $this->line("🔍 Debug: Target data: " . json_encode($comparison['target']));
            
            $this->table(
                ['Location', 'Files', 'Total Size', 'File Types'],
                [
                    [
                        'Source (New)', 
                        $comparison['source']['count'] ?? 0,
                        $this->formatBytes($comparison['source']['total_size'] ?? 0),
                        $this->formatFileTypes($comparison['source']['file_types'] ?? [])
                    ],
                    [
                        'Target (Existing)',
                        $comparison['target']['count'] ?? 0, 
                        $this->formatBytes($comparison['target']['total_size'] ?? 0),
                        $this->formatFileTypes($comparison['target']['file_types'] ?? [])
                    ]
                ]
            );
        } else {
            $this->line("❌ Missing source or target data in comparison");
        }
    }
    
    /**
     * Prompt user for action when duplicate is detected but can't be compared
     * Returns true if import should continue, false if it should be skipped
     */
    protected function promptForDuplicateAction(array $audiobook, $existingBook): bool
    {
        $this->line("");
        $this->line("🔍 Duplicate book detected:");
        $this->line("  Source: " . $audiobook['path']);
        $this->line("  Existing: '{$existingBook->title}' (ID: {$existingBook->id})");
        $this->line("  Database path: " . ($existingBook->directory_path ?? 'N/A'));
        
        $this->line("\nOptions:");
        $this->line("1. (S)kip import (keep both)");
        $this->line("2. (D)elete source directory");
        $this->line("3. (C)ontinue with import anyway");
        
        // Prepare background tasks for potential analysis
        $backgroundTasks = [
            ['type' => 'scan_directory', 'data' => ['path' => $audiobook['path']]],
            ['type' => 'duplicate_check', 'data' => $audiobook]
        ];
        
        $choice = $this->askWithBackground("Choose an option (1-3)", '1', $backgroundTasks);
        
        // Normalize choice to handle letters
        $choice = strtolower(trim($choice));
        if (in_array($choice, ['s', 'skip'])) $choice = '1';
        if (in_array($choice, ['d', 'delete'])) $choice = '2';
        if (in_array($choice, ['c', 'continue'])) $choice = '3';
        
        switch ($choice) {
            case '2':
                $this->info("🗑️ Removing source directory");
                $this->cleanupSourceDirectory($audiobook, true);
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'User chose to delete source (duplicate detected)'
                ];
                return false; // Don't continue with import
                
            case '3':
                $this->warn("⚠️ Continuing with import despite duplicate detection");
                return true; // Continue with import
                
            case '1':
            default:
                $this->info("📁 Skipping import, keeping both");
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'User chose to skip (duplicate detected)'
                ];
                return false; // Don't continue with import
        }
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
     * Format source path for display - show relative to command line input or configured directories
     */
    protected function formatSourcePathForDisplay(string $fullPath): string
    {
        // Get the paths provided on command line
        $providedPaths = $this->argument('path');
        
        if (!empty($providedPaths)) {
            // Check if this path is under any of the provided paths
            foreach ($providedPaths as $providedPath) {
                $normalizedProvided = str_replace('\ ', ' ', $providedPath);
                if (str_starts_with($fullPath, $normalizedProvided)) {
                    $relativePath = str_replace($normalizedProvided, '', $fullPath);
                    $relativePath = ltrim($relativePath, '/');
                    // If we have a relative path, show it relative to the provided path
                    if (!empty($relativePath)) {
                        return basename($normalizedProvided) . '/' . $relativePath;
                    } else {
                        return basename($normalizedProvided);
                    }
                }
            }
        }
        
        // Check against configured default directories
        $defaultDirs = [
            '/media/lyra_data1/audiobooks/unsorted',
            env('AUDIOBOOK_DOWNLOAD_PATH', '/tmp/audiobooks')
        ];
        
        foreach ($defaultDirs as $defaultDir) {
            if (str_starts_with($fullPath, $defaultDir)) {
                return str_replace($defaultDir . '/', '', $fullPath);
            }
        }
        
        // Check against current working directory
        $currentDir = getcwd();
        if (str_starts_with($fullPath, $currentDir)) {
            return str_replace($currentDir . '/', './', $fullPath);
        }
        
        // If no match, show just the last 2-3 directory levels
        $pathParts = explode('/', $fullPath);
        if (count($pathParts) > 3) {
            return '.../' . implode('/', array_slice($pathParts, -3));
        }
        
        return $fullPath;
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
                    $this->info("✅ Removed duplicate source directory (identical files already exist in library)");
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
    protected function retryApiCall(callable $apiCall, string $serviceName, string $description = '', int $maxRetries = 3): mixed
    {
        $attempt = 1;
        $spinnerMessage = $description ?: "Fetching data from {$serviceName}";
        
        // Start spinner
        $spinner = $this->output->createProgressBar();
        $spinner->setFormat(" %message%");
        $spinner->setMessage("🌐 {$spinnerMessage}...");
        $spinner->start();
        
        while ($attempt <= $maxRetries) {
            try {
                $result = $apiCall();
                
                // Stop spinner and clear line
                $spinner->finish();
                $this->output->write("\r\033[K"); // Clear the spinner line
                
                // If we get a result, return it (could be null for "no data found")
                return $result;
                
            } catch (\Exception $e) {
                if ($attempt === $maxRetries) {
                    // Stop spinner and clear line
                    $spinner->finish();
                    $this->output->write("\r\033[K");
                    
                    // Last attempt failed, log and return null
                    $this->error("❌ {$serviceName}: All {$maxRetries} attempts failed - " . $e->getMessage());
                    Log::error("{$serviceName} enrichment failed after {$maxRetries} attempts", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    return null;
                }
                
                // Update spinner for retry
                $delay = pow(2, $attempt - 1);
                $spinner->setMessage("🌐 {$spinnerMessage}... (retry {$attempt}/{$maxRetries} in {$delay}s)");
                sleep($delay);
                
                // Reset spinner message for next attempt
                $spinner->setMessage("🌐 {$spinnerMessage}...");
                $attempt++;
            }
        }
        
        return null;
    }

    /**
     * Normalize author names for directory use
     */
    protected function normalizeAuthorName(string $authorName): string
    {
        $name = trim($authorName);
        
        // Add periods after single letters (initials) if not already present
        $name = preg_replace('/\b([A-Z])\s+/', '$1. ', $name);
        
        // Handle initials at the end of names
        $name = preg_replace('/\s+([A-Z])$/', ' $1.', $name);
        
        // Combine consecutive initials (remove spaces between them)
        // "J. N. Chaney" -> "J.N. Chaney"
        // Handle multiple consecutive initials
        $name = preg_replace('/\b([A-Z]\.)\s+([A-Z]\.)/', '$1$2', $name);
        // Repeat to catch cases with 3+ initials
        $name = preg_replace('/\b([A-Z]\.)\s+([A-Z]\.)/', '$1$2', $name);
        
        return trim($name);
    }

    /**
     * Format multiple authors for directory paths
     */
    protected function formatAuthorsForDirectory(array $authors): string
    {
        // Normalize each author name
        $normalizedAuthors = array_map([$this, 'normalizeAuthorName'], $authors);
        
        // Join with & for directory paths
        return implode(' & ', $normalizedAuthors);
    }


    /**
     * Find existing directory for authors (checking different orders and subsets)
     */
    protected function findExistingAuthorDirectory(array $authors, string $seriesName = null): ?string
    {
        if (empty($authors)) {
            return null;
        }

        $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
        if (!$bookStoragePath || !File::isDirectory($bookStoragePath)) {
            return null;
        }

        $normalizedAuthors = array_map([$this, 'normalizeAuthorName'], $authors);
        
        // Generate all possible author combinations to check
        // Prioritize full combinations over single authors for multi-author books
        $authorCombinations = [];
        
        // For multi-author books, try full combinations first
        if (count($normalizedAuthors) > 1) {
            $authorCombinations[] = $normalizedAuthors;
            $authorCombinations[] = array_reverse($normalizedAuthors);
            
            // For 3+ authors, also try pairs of the most common combinations
            if (count($normalizedAuthors) >= 3) {
                $authorCombinations[] = [$normalizedAuthors[0], $normalizedAuthors[1]];
                $authorCombinations[] = [$normalizedAuthors[1], $normalizedAuthors[0]];
            }
        }
        
        // For single author books, try the single author
        // For multi-author books, don't fall back to single authors - force creation of new multi-author directory
        if (count($normalizedAuthors) === 1) {
            $authorCombinations[] = $normalizedAuthors;
        }

        // Search through existing directories
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($bookStoragePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $dir) {
                if (!$dir->isDir()) {
                    continue;
                }

                $dirPath = $dir->getPathname();
                $pathParts = explode('/', str_replace($bookStoragePath . '/', '', $dirPath));
                
                // Look for author directories (typically 2nd level: Genre/Author/Series)
                if (count($pathParts) >= 2) {
                    $authorDirName = $pathParts[1];
                    
                    // Check if this directory matches any of our author combinations
                    foreach ($authorCombinations as $combination) {
                        $expectedDirName = $this->formatAuthorsForDirectory($combination);
                        
                        if ($authorDirName === $expectedDirName) {
                            // If series name is provided, check if this author has that series
                            if ($seriesName && count($pathParts) >= 3) {
                                $seriesDirName = $pathParts[2];
                                if (stripos($seriesDirName, $seriesName) !== false) {
                                    return $authorDirName;
                                }
                            } else {
                                // Return the found author directory name
                                return $authorDirName;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error searching for existing author directories: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Get enrichment service instance
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
    protected function getCacheService(): ImportCacheService
    {
        if (!$this->cacheService) {
            $options = [
                'enabled' => $this->cacheEnabled,
                'cache_file' => $this->cacheFilePath ?? storage_path('app/import_cache.json')
            ];
            $this->cacheService = new ImportCacheService($options);
        }
        return $this->cacheService;
    }

    /**
     * Edit directory path only
     */
    protected function editDirectoryPathOnly(array $metadata, array $audiobook): array
    {
        $this->newLine();
        $this->info("📁 Edit Directory Path");
        
        // Generate current path
        $currentPath = $this->getImportService()->generateDirectoryPath($metadata);
        $this->line("Current path: {$currentPath}");
        
        // Allow user to edit the path
        $newPath = $this->ask("Enter new directory path (relative to library root)", $currentPath);
        
        if ($this->inputInterrupted) {
            return $metadata;
        }
        
        // Store the custom path in metadata
        $metadata['custom_directory_path'] = trim($newPath);
        
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
        
        // Try to determine the target path
        try {
            $metadata = [
                'author' => $book->authors->pluck('name')->toArray(),
                'genre' => $book->genres->first()?->name ?? 'Unknown',
                'series' => $book->series->first()?->name,
                'title' => $book->title
            ];
            $targetPath = $this->getImportService()->generateDirectoryPath($metadata);
            $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
            $fullTargetPath = "{$bookStoragePath}/{$targetPath}/{$book->title}";
            $this->line("   Target: {$fullTargetPath}");
        } catch (\Exception $e) {
            $this->line("   Target: [Could not determine target path]");
        }
        
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
                if (strpos($line, 'Failed to move files to library') !== false && 
                    strpos($line, basename($sourcePath)) !== false) {
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

}