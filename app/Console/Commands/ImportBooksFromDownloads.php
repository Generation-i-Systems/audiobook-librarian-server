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
use App\Services\FileSystemService;
use App\Services\MetadataProcessingService;
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
    protected ?MetadataProcessingService $metadataService = null;
    protected ?FileSystemService $fileSystemService = null;
    
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
            'cd_directories' => $this->getFileSystemService()->hasCdDirectories($audiobook['path']),
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
            'has_cd_directories' => $this->getFileSystemService()->hasCdDirectories($path),
            'directory_size' => $this->getFileSystemService()->getDirectorySize($path),
            'potential_duplicates' => $this->getFileSystemService()->findPotentialDuplicates($path)
        ];
    }
    
    /**
     * Check for duplicates in background
     */
    protected function checkDuplicatesInBackground(array $audiobook): array
    {
        return [
            'existing_books' => $this->findSimilarBooks($audiobook),
            'duplicate_paths' => $this->getFileSystemService()->findDuplicatePaths($audiobook['path'])
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
        // Validate audiobook files before processing
        if (!$this->validateAudiobookFiles($audiobook)) {
            return; // Skip if validation fails
        }
        
        // Process metadata with AI
        $aiMetadata = $this->processAudiobookMetadata($audiobook);
        if (!$aiMetadata) {
            return; // Skip if metadata processing failed
        }

        $this->info("✅ AI processing successful (confidence: {$aiMetadata['confidence']}%)");
        
        // Extract series number from title and clean metadata
        $this->getEnrichmentService()->extractSeriesNumberFromTitle($aiMetadata);
        
        // Handle multi-book patterns (simplified)
        $multiBookInfo = $this->getMetadataService()->detectMultiBookPattern($audiobook['name']);
        if ($multiBookInfo) {
            $this->info("📚 Detected multi-book directory: {$multiBookInfo['series_name']} [{$multiBookInfo['start_number']}-{$multiBookInfo['end_number']}]");
            $aiMetadata['series'] = $multiBookInfo['series_name'];
            $aiMetadata['multi_book_numbers'] = range($multiBookInfo['start_number'], $multiBookInfo['end_number']);
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
            $enrichedData = $this->getEnrichmentService()->enrichWithExternalData($aiMetadata);
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
        } elseif ($this->option('auto') && !$this->getEnrichmentService()->hasEnrichmentData($aiMetadata)) {
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
            $cleanedSeriesName = $this->getEnrichmentService()->cleanSeriesName($metadata['series'], $authors);
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
        if (!$this->getEnrichmentService()->hasEnrichmentData($metadata)) {
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
        if (!$this->getEnrichmentService()->hasEnrichmentData($metadata) && !$this->option('skip-enrichment')) {
            $this->info("🔍 Attempting to enrich with edited metadata...");
            $enrichedData = $this->getEnrichmentService()->enrichWithExternalData($metadata);
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
                        $enrichedData = $this->getEnrichmentService()->enrichWithExternalData($metadata);
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
        $this->getEnrichmentService()->extractSeriesNumberFromTitle($metadata);
        
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
                'reason' => 'Source path no longer exists'
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
                'reason' => 'Some audio files no longer exist'
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
                'reason' => 'No audio files found'
            ];
            return null;
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
        
        $aiMetadata = $this->getMetadataService()->processWithAI($audiobook);
        
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
            $audioMetadata = $this->getMetadataService()->processWithAudioAnalysis($audiobook);
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
                    return null;
                } else {
                    $this->warn("⚠️  Audio analysis failed but continuing due to --force-audio flag");
                    // Continue with original metadata if forced
                }
            }
        }
        
        $this->info("✅ AI processing successful (confidence: {$aiMetadata['confidence']}%)");
        return $aiMetadata;
    }
}