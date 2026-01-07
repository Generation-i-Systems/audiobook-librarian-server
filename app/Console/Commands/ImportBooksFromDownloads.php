<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Narrator;
use App\Models\Series;
use App\Services\AIBookProcessor;
use App\Services\AudioFileAnalyzer;
use App\Services\AudibleService;
use App\Services\BackgroundProcessingService;
use App\Services\BookEnrichmentService;
use App\Services\BookImportService;
use App\Services\CoverImageAnalysisService;
use App\Services\ExternalCoverService;
use App\Services\GoogleBooksApiService;
use App\Services\GoogleImageSearchService;
use App\Services\ImportCacheService;
use App\Services\ImportUIService;
use App\Traits\GenreMapping;
use App\Traits\BookImportTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Traits\IsolatesErrorHandlers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class ImportBooksFromDownloads extends Command
{
    use IsolatesErrorHandlers;
    use GenreMapping;
    use BookImportTrait;
    use ManualEnrichmentTrait;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'books:import-downloads
                            {path?* : Specific files or folders to process (default: scan directories)}
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
                            {--force-audio : Force audio transcription even when AI confidence is high}
                            {--ui=ncurses : UI layer (ncurses|plain)}';

    /**
     * The console command description.
     */
    protected $description = 'Import audiobooks from downloads using AI + enrichment ' .
        '(creates a database backup by default)';

    protected ?AIBookProcessor $aiProcessor = null;
    protected ?AudioFileAnalyzer $audioAnalyzer = null;
    protected ?AudibleService $audibleService = null;
    protected ?CoverImageAnalysisService $coverAnalysisService = null;
    protected ?ExternalCoverService $coverService = null;
    protected ?GoogleBooksApiService $googleBooksService = null;
    protected ?GoogleImageSearchService $googleImageService = null;
    protected ?ImportUIService $uiService = null;

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
    protected $audioProcessor;

    protected bool $inputInterrupted = false;

    protected ?string $embeddedCoverTempFile = null;

    // Persistent cache
    protected string $cacheDirectory = 'path/to/cache/directory';
    protected string $cacheFilePath = 'path/to/cache/file';
    protected array $backgroundCache = [];
    protected int $cacheVersion = 2; // Increment when cache structure changes
    protected bool $cacheEnabled = true;

    // User interruption handling
    protected bool $userRequestedQuit = false;
    protected array $processedBooks = [];
    protected array $failedBooks = [];
    protected array $skippedBooks = [];
    protected int $totalFound = 0;

    protected function getFileOperation(): string
    {
        return (bool) $this->option('copy-files') ? 'copy' : 'move';
    }

    public function __construct(?ImportUIService $uiService = null)
    {
        parent::__construct();
        $this->uiService = $uiService;
    }

    public function __destruct()
    {
        if ($this->embeddedCoverTempFile && file_exists($this->embeddedCoverTempFile)) {
            @unlink($this->embeddedCoverTempFile);
        }
    }

    protected function handleLowConfidenceMetadata(array $audiobook, ?array &$aiMetadata): bool
    {
        $minConfidence = (int) $this->option('min-confidence');
        $hasCriticalTagMetadata = $this->hasCriticalTagMetadata($this->extractTagMetadataFromAudiobook($audiobook));

        return $this->getImportService()->handleLowConfidenceMetadata($audiobook, $aiMetadata, $minConfidence, $hasCriticalTagMetadata);
    }

    protected function extractTagMetadataFromAudiobook(array $audiobook): array
    {
        return $this->getImportService()->extractTagMetadataFromAudiobook($audiobook, $this->aiProcessor);
    }

    protected function hasCriticalTagMetadata(array $tagMetadata): bool
    {
        return $this->getImportService()->hasCriticalTagMetadata($tagMetadata);
    }

    protected function hasCover(array $metadata): bool
    {
        return $this->getImportService()->hasCover($metadata);
    }

    protected function hasCriticalMetadata(array $metadata): bool
    {
        return $this->getImportService()->hasCriticalMetadata($metadata);
    }


    protected function buildUiMetadata(array $metadata): array
    {
        $uiMetadata = $this->getImportService()->buildUiMetadata($metadata);

        if (!empty($uiMetadata['cover_data'])) {
            $tempPath = $this->getEmbeddedCoverTempPath($uiMetadata['cover_data']);
            if ($tempPath) {
                $uiMetadata['cover_url'] = $tempPath;
                $uiMetadata['cover_is_local_file'] = true;
            }
        }

        $uiMetadata['directory_path'] = $this->getImportService()->generateDirectoryPath($uiMetadata, [
            'include_title' => true,
        ]);

        return $uiMetadata;
    }

    public function line($string, $style = null, $verbosity = null)
    {
        if ($this->uiService) {
            $this->uiService->logMessage((string) $string);
            return;
        }

        parent::line($string, $style, $verbosity);
    }

    public function info($string, $verbosity = null)
    {
        if ($this->uiService) {
            $this->uiService->logMessage((string) $string);
            return;
        }

        parent::info($string, $verbosity);
    }

    public function warn($string, $verbosity = null)
    {
        if ($this->uiService) {
            $this->uiService->logMessage((string) $string);
            return;
        }

        parent::warn($string, $verbosity);
    }

    public function error($string, $verbosity = null)
    {
        if ($this->uiService) {
            $this->uiService->logMessage((string) $string);
            return;
        }

        parent::error($string, $verbosity);
    }

    public function newLine($count = 1)
    {
        if ($this->uiService) {
            return $this;
        }

        parent::newLine($count);

        return $this;
    }

    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
    {
        if ($this->uiService) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $this->uiService->logMessage(implode(' | ', array_map('strval', $row)));
                } else {
                    $this->uiService->logMessage((string) $row);
                }
            }
            return;
        }

        parent::table($headers, $rows, $tableStyle, $columnStyles);
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->uiService) {
            $this->uiService = app(ImportUIService::class);
        }

        $uiMode = (string) ($this->option('ui') ?? 'ncurses');
        if ($uiMode === 'plain') {
            $this->uiService->setPlainMode(true);
        }

        [$width, $height] = $this->getTerminalDimensions();
        $this->uiService->initialize($width, $height);

        try {
            $this->uiService->drawInitialLayout();

            // Set up signal handlers for graceful interruption
            $this->setupSignalHandlers();

            // Initialize persistent cache system
            $this->initializeCache();

            // Create a database backup unless --no-backup is specified
            if (!$this->option('no-backup')) {
                $this->uiService->logMessage('Creating a database backup before importing books...');
                $this->call('backup:database', ['--suffix' => 'import-books']);
                $this->uiService->logMessage('Database backup created.');
            }

            $this->uiService->logMessage("🚀 Starting automated audiobook import from download directories...");

            // Check for readline extension and warn if not available (unless in auto mode)
            if (!$this->option('auto') && !extension_loaded('readline')) {
                $this->uiService->logMessage(
                    '❌ PHP readline extension is not enabled. Advanced line editing will not be available.'
                );
            }

            // Initialize AI processor
            $model = $this->option('model');
            try {
                $this->aiProcessor = new AIBookProcessor($model, true);
                $this->uiService->logMessage("✅ AI processor initialized with model: {$model}");
            } catch (\Exception $e) {
                $this->uiService->logMessage("❌ Failed to initialize AI processor: " . $e->getMessage());
                return Command::FAILURE;
            }

            // Check for specific paths first (files or folders)
            $specificPaths = $this->argument('path');
            if (!empty($specificPaths)) {
                // Ensure it's an array (Laravel may return string for single argument)
                $specificPaths = is_array($specificPaths) ? $specificPaths : [$specificPaths];
                $this->uiService->logMessage("📁 Processing specific paths: " . implode(', ', $specificPaths));
                $audiobooks = $this->processSpecificPaths($specificPaths);
            } else {
                // Get directories to scan
                $directories = $this->getDirectoriesToScan();
                if (empty($directories)) {
                    $this->uiService->logMessage("❌ No valid directories found to scan");
                    return Command::FAILURE;
                }

                $this->uiService->logMessage("📁 Scanning directories: " . implode(', ', $directories));

                // Scan for audiobooks
                $audiobooks = $this->scanForAudiobooks($directories);
            }
            $this->totalFound = count($audiobooks);

            if (empty($audiobooks)) {
                $this->uiService->logMessage("ℹ️  No audiobooks found to process.");
                return Command::SUCCESS;
            }

            $totalFound = count($audiobooks);
            $this->uiService->logMessage("📚 Found {$totalFound} potential audiobooks to import");

            // Apply limit
            $limit = $this->option('limit');
            if ($limit && $limit > 0 && $totalFound > $limit) {
                $audiobooks = array_slice($audiobooks, 0, $limit);
                $this->uiService->logMessage(
                    "⚠️  Processing limited to {$limit}/{$totalFound} books (--limit=0 for no limit)"
                );
            } else {
                $this->uiService->logMessage("📋 Will process all {$totalFound} books");
            }

            // Show cost estimate for AI processing
            $this->showCostEstimate(count($audiobooks));

            // Process each audiobook
            foreach ($audiobooks as $index => $audiobook) {
                $this->uiService->updateProgress($index + 1, count($audiobooks));
                $this->uiService->logMessage('Processing: ' . $audiobook['name']);
                try {
                    // Start background processing for upcoming books
                    $this->startBackgroundProcessing($audiobooks, $index);

                    $this->uiService->logMessage("Debug: Calling processAudiobook for: " . $audiobook['name']);
                    $this->processAudiobook($audiobook);
                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();

                    // Handle user interruption specially
                    if (str_contains($errorMessage, '[Request interrupted by user]')) {
                        $this->skippedBooks[] = [
                            'path' => $audiobook['path'],
                            'reason' => 'User interruption - skipped current book',
                        ];
                        $skippedPath = basename($audiobook['path']);
                        $this->uiService->logMessage('⏭️  Skipped due to user interruption: ' . $skippedPath);
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
                            $this->uiService->logMessage("Full error trace: " . $fullError);
                        }
                    }
                }
            }

            // Show summary
            $this->displaySummary();

            // Save cache before exit and show cache statistics
        } finally {
            if ($this->uiService) {
                $this->uiService->clear();
            }
            $this->outputFinalSummary();
        }

        return Command::SUCCESS;
    }

    private function outputFinalSummary(): void
    {
        // Show summary
        $this->displaySummary();

        // Save cache before exit and show cache statistics
        if ($this->cacheEnabled) {
            $this->saveCache();
            $this->displayCacheStatistics();
        }
    }

    private function getTerminalDimensions(): array
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return [120, 40]; // Default for Windows
        }

        try {
            $size = @shell_exec('stty size 2>/dev/null');
            if ($size) {
                $dimensions = explode(' ', trim($size));
                if (count($dimensions) === 2) {
                    return [intval($dimensions[1]), intval($dimensions[0])];
                }
            }
        } catch (\Exception $e) {
            // stty might not be available
        }

        return [120, 40]; // Default
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
        return $this->getImportService()->processSpecificPaths(
            $paths,
            fn ($path) => $this->processSingleAudioFile($path),
            fn ($path) => $this->processAudiobookDirectory($path)
        );
    }

    /**
     * Process a single audio file as an individual audiobook
     */
    protected function processSingleAudioFile(string $filePath): ?array
    {
        return $this->getImportService()->processSingleAudioFile($filePath);
    }

    /**
     * Process a single directory as an audiobook
     */
    protected function processAudiobookDirectory(string $directory): ?array
    {
        return $this->getImportService()->processAudiobookDirectory($directory);
    }

    /**
     * Scan directories for audiobook folders/files
     */
    protected function scanForAudiobooks(array $directories): array
    {
        $audiobooks = $this->getImportService()->scanForAudiobooks($directories, fn ($path) => $this->isAlreadyImported($path));

        foreach ($directories as $directory) {
            $this->info("🔍 Scanning: {$directory}");
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
                'result' => null,
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
            'priority' => $priority,
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
        $metadata = $this->getImportService()->extractBasicMetadata($audiobook);

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
            'timestamp' => time(),
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
            'potential_duplicates' => $this->findPotentialDuplicates($path),
        ];
    }

    /**
     * Check for duplicates in background
     */
    protected function checkDuplicatesInBackground(array $audiobook): array
    {
        return [
            'existing_books' => $this->findSimilarBooks($audiobook),
            'duplicate_paths' => $this->findDuplicatePaths($audiobook['path']),
        ];
    }

    /**
     * Extract detailed metadata in background
     */
    protected function extractMetadataInBackground(array $audiobook): array
    {
        $metadata = [];

        // Get first audio file for metadata extraction
        $audioFiles = array_filter($audiobook['files'], function ($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac']);
        });

        if (!empty($audioFiles)) {
            $audioFileValues = array_values($audioFiles);
            $firstAudioFile = $audioFileValues[0];

            // Extract basic file metadata
            if (function_exists('getid3_analyze')) {
                try {
                    $fileInfo = $this->withHandlerIsolation(fn () => (new \getID3())->analyze($firstAudioFile));

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
            'smallest_file' => null,
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
                if (is_null($analysis['largest_file'])) {
                    $analysis['largest_file'] = ['path' => $file, 'size' => $fileSize];
                } else {
                    $largestSize = (int) ($analysis['largest_file']['size'] ?? 0);
                    if ($fileSize > $largestSize) {
                        $analysis['largest_file'] = ['path' => $file, 'size' => $fileSize];
                    }
                }

                if (is_null($analysis['smallest_file'])) {
                    $analysis['smallest_file'] = ['path' => $file, 'size' => $fileSize];
                } else {
                    $smallestSize = (int) ($analysis['smallest_file']['size'] ?? PHP_INT_MAX);
                    if ($fileSize < $smallestSize) {
                        $analysis['smallest_file'] = ['path' => $file, 'size' => $fileSize];
                    }
                }
            }
        }

        $analysis['total_size'] = array_sum($fileSizes);
        $analysis['average_file_size'] = 0;
        if ($analysis['audio_files'] > 0) {
            $analysis['average_file_size'] = $analysis['total_size'] / $analysis['audio_files'];
        }

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
            'thumbnail_ready' => false,
        ];

        $coverPath = $this->findCoverImage($audiobook['path']);

        if ($coverPath && file_exists($coverPath)) {
            $result['has_cover'] = true;
            $result['cover_path'] = $coverPath;
            $result['cover_size'] = filesize($coverPath);

            // Pre-create thumbnail if using Kitty protocol
            if ($this->isGhosttyTerminal()) {
                try {
                    $thumbnailPath = $this->createThumbnail($coverPath, 200, 200);
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
        return $this->getImportService()->extractNfoDataInBackground($nfoPath);
    }

    /**
     * Show background processing status
     */
    protected function showBackgroundProcessingStatus(): void
    {
        $this->getImportService()->showBackgroundProcessingStatus(
            $this->backgroundTasks,
            fn ($message) => $this->line($message)
        );
    }

    /**
     * Show enhanced background processing status
     */
    protected function showEnhancedBackgroundStatus(): void
    {
        $this->getImportService()->showEnhancedBackgroundStatus(
            $this->backgroundTasks,
            count($this->taskQueue),
            fn ($message) => $this->line($message)
        );
    }

    /**
     * Add helper methods for enhanced background processing
     */
    protected function analyzeFileTypes(array $files): array
    {
        return $this->getImportService()->analyzeFileTypes($files);
    }

    protected function analyzeDirectoryName(string $directoryName): array
    {
        return $this->getImportService()->analyzeDirectoryName($directoryName);
    }

    protected function isMultiBookDirectory(string $path): bool
    {
        return $this->getImportService()->isMultiBookDirectory($path);
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
            File::makeDirectory($this->cacheDirectory, 0755, true);
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
                'data' => $this->backgroundCache,
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
            'directory_mtime' => $this->getDirectoryModificationTime($audiobook['path']),
        ];
    }

    /**
     * Get directory modification time
     */
    protected function getDirectoryModificationTime(string $path): int
    {
        return $this->getImportService()->getDirectoryModificationTime($path);
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
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        return $this->getImportService()->formatBytes($bytes, $precision);
    }

    /**
     * Save cache before application exit
     */
    protected function saveCacheBeforeExit(): void
    {
        if ($this->cacheEnabled && $this->cacheService) {
            $this->cacheService->save();
        }
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
        $response = $this->askWithImmediateInterrupt($question, $default);

        return $response;
    }

    /**
     * Handle user quit request
     */
    protected function handleUserQuit(): void
    {
        $this->userRequestedQuit = true;

        if ($this->uiService) {
            $this->uiService->clear();
        }

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
     * Handle user interruption (Ctrl+C)
     */
    protected function handleUserInterruption(): void
    {
        if ($this->uiService) {
            if (method_exists($this->uiService, 'requestInterrupt')) {
                $this->uiService->requestInterrupt();
            }
            $this->uiService->restoreTerminalState();
        }

        $this->inputInterrupted = true;
        $this->newLine();
        $this->warn("⚠️  [Request interrupted by user] - Ctrl+C detected");
        $this->info('🛑 Quitting import process gracefully...');

        exit(130);
    }

    protected function getEmbeddedCoverTempPath(string $coverData): ?string
    {
        if ($this->embeddedCoverTempFile && file_exists($this->embeddedCoverTempFile)) {
            return $this->embeddedCoverTempFile;
        }

        $tempFile = $this->getImportService()->getEmbeddedCoverTempPath($coverData);
        if ($tempFile) {
            $this->embeddedCoverTempFile = $tempFile;
        }

        return $tempFile;
    }

    /**
     * Ask for input with immediate interruption capability
     */
    protected function askWithImmediateInterrupt(string $question, string $default = null): string
    {
        $response = $this->uiService->ask($question, $default ?? '');

        if (!is_string($response)) {
            $this->inputInterrupted = true;
            return '';
        }

        if (strtolower(trim($response)) === 'q') {
            $this->handleUserQuit();
        }

        return $response;
    }

    protected function selectWithImmediateInterrupt(string $question, array $options, string $default = ''): string
    {
        $response = $this->uiService->select($question, $options, $default);

        if (is_string($response) && strtolower(trim($response)) === 'q') {
            $this->handleUserQuit();
        }

        return $response;
    }

    /**
     * Helper methods for background processing
     */
    protected function hasCdDirectories(string $path): bool
    {
        return $this->getImportService()->hasCdDirectories($path);
    }

    protected function getDirectorySize(string $path): int
    {
        return $this->getImportService()->getDirectorySize($path);
    }

    protected function findPotentialDuplicates(string $path): array
    {
        return $this->getImportService()->findPotentialDuplicates($path);
    }

    protected function findSimilarBooks(array $audiobook): array
    {
        return $this->getImportService()->findSimilarBooks($audiobook);
    }

    protected function findDuplicatePaths(string $path): array
    {
        return $this->getImportService()->findDuplicatePaths($path);
    }

    /**
     * Group CD directories under their parent directory to treat multi-disc books as single audiobooks
     */
    protected function groupCdDirectories(array $potentialBooks): array
    {
        $grouped = $this->getImportService()->groupCdDirectories($potentialBooks);

        foreach ($grouped as $path => $data) {
            if (isset($data['cd_count']) && $data['cd_count'] > 1) {
                $this->line(
                    "📀 Detected multi-disc audiobook: " . basename($path) . " ({$data['cd_count']} discs)"
                );
            }
        }

        return $grouped;
    }

    /**
     * Check if audiobook is already imported
     */
    protected function isAlreadyImported(string $path, array $metadata = []): bool
    {
        return $this->getImportService()->isAlreadyImported($path, $metadata);
    }

    /**
     * Find existing book in database (returns Book model instead of boolean)
     */
    protected function findExistingBook(string $path, array $metadata = []): ?Book
    {
        return $this->getImportService()->findExistingBook($path, $metadata);
    }

    /**
     * Process a single audiobook with AI and external enrichment
     */
    protected function processAudiobook(array $audiobook): void
    {
        $skippedBooks = &$this->skippedBooks;
        $processedBooks = &$this->processedBooks;

        $this->getImportService()->processAudiobook(
            $audiobook,
            $this->aiProcessor,
            fn ($metadata) => $this->buildUiMetadata($metadata),
            fn ($message) => $this->uiService->logMessage($message),
            fn ($message) => $this->info($message),
            fn ($message) => $this->line($message),
            fn () => $this->newLine(),
            fn ($message) => $this->warn($message),
            fn ($metadata) => $this->displayEnrichedMetadata($metadata),
            fn (&$metadata, $audiobook) => $this->reviewAndApprove($metadata, $audiobook),
            fn ($metadata) => $this->hasEnrichmentData($metadata),
            fn () => $this->getFileOperation(),
            fn ($metadata) => $this->enrichWithExternalData($metadata),
            fn () => $this->getEnrichmentService(),
            fn ($path, $metadata) => $this->findExistingBook($path, $metadata),
            fn ($dir1, $dir2) => $this->compareDirectories($dir1, $dir2),
            fn ($comparison) => $this->displayDirectoryComparison($comparison),
            fn ($audiobook, $existingBook) => $this->promptForDuplicateAction($audiobook, $existingBook),
            fn ($audiobook, $force) => $this->cleanupSourceDirectory($audiobook, $force),
            fn ($bytes) => $this->formatBytes($bytes),
            fn (&$metadata) => $this->extractSeriesNumberFromTitle($metadata),
            fn ($title) => $this->detectMultiBookPattern($title),
            fn ($audiobook, $multiBookInfo) => $this->analyzeMultiBookFiles($audiobook, $multiBookInfo),
            fn ($audiobook, $multiBookInfo, $splitGroups, $aiMetadata) => $this->processMultiBookSplit($audiobook, $multiBookInfo, $splitGroups, $aiMetadata),
            fn ($audiobook, $aiMetadata) => $this->handleLowConfidenceMetadata($audiobook, $aiMetadata),
            fn ($audiobook) => $this->processWithAI($audiobook),
            $skippedBooks,
            $processedBooks,
            (bool) $this->option('auto'),
            (bool) $this->option('dry-run'),
            (bool) $this->option('skip-enrichment'),
            $this->uiService ?? null
        );
    }

    /**
     * Process audiobook with AI
     */
    protected function processWithAI(array $audiobook): ?array
    {
        return $this->getImportService()->processWithAI($audiobook, $this->aiProcessor);
    }

    /**
     * Process audiobook using audio analysis fallback when metadata extraction fails
     */
    protected function processWithAudioAnalysis(array $audiobook): ?array
    {
        return $this->getImportService()->processWithAudioAnalysis($audiobook, $this->aiProcessor);
    }

    protected function mergeMetadataFillMissing(array $base, array $fill): array
    {
        return $this->getImportService()->mergeMetadataFillMissing($base, $fill);
    }


    /**
     * Post-process AI result to fix common issues with numbered series books
     */
    protected function postProcessAIResult(array $aiResult, array $audiobook): array
    {
        return $this->getImportService()->postProcessAIResult($aiResult, $audiobook);
    }

    /**
     * Display enriched metadata (AI + external data) for review
     */
    protected function displayEnrichedMetadata(array $metadata): void
    {
        if ($this->uiService) {
            $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
            return;
        }

        $tableData = $this->getImportService()->displayEnrichedMetadata($metadata);
        $this->table(['Field', 'Value'], $tableData);

        $this->getImportService()->handleCoverSelection(
            $metadata,
            fn ($path) => $this->isTextOnWhiteCover($path),
            fn ($metadata, $limit) => $this->searchAlternativeCovers($metadata, $limit),
            fn ($message) => $this->warn($message),
            fn ($message) => $this->line($message),
            fn ($message) => $this->info($message),
            fn ($message) => $this->comment($message),
            fn ($coverOptions, $metadata) => $this->displayCoverOptions($coverOptions, $metadata),
            fn ($coverOptions) => $this->promptForCoverSelection($coverOptions),
            !$this->option('auto')
        );

        if (!empty($metadata['cover_url'])) {
            $this->displayCoverImage($metadata['cover_url']);
        }
    }

    /**
     * Ask for input with prompt on the same line
     */
    protected function askInline(string $question, string $default = ''): string
    {
        return $this->askWithImmediateInterrupt($question, $default);
    }

    protected function getFirstNonEmptyMetadataValue(array $metadata, array $keys): mixed
    {
        return $this->getImportService()->getFirstNonEmptyMetadataValue($metadata, $keys);
    }

    protected function promptForCoverUrl(string $currentCoverUrl): string
    {
        $newCoverUrl = $this->askInline('Cover URL', $currentCoverUrl);

        if ($this->inputInterrupted) {
            return $currentCoverUrl;
        }

        return $newCoverUrl ?: $currentCoverUrl;
    }

    /**
     * Display cover image if terminal supports it (like Ghostty with Kitty protocol)
     */
    protected function displayCoverImage(string $imageUrl): void
    {
        if ($this->uiService) {
            return;
        }

        $this->getImportService()->displayCoverImage(
            $imageUrl,
            fn ($message) => $this->line($message),
            fn ($imageData) => $this->displayKittyImage($imageData)
        );
    }

    /**
     * Display image using Kitty graphics protocol or kitten icat
     */
    protected function displayKittyImage(string $imageData): void
    {
        $this->getImportService()->displayKittyImage(
            $imageData,
            fn ($message) => $this->line($message),
            fn ($command) => system($command)
        );
    }

    /**
     * Create thumbnail image
     */
    protected function createThumbnail(string $imagePath, int $width, int $height)
    {
        return $this->getImportService()->createThumbnail($imagePath, $width, $height);
    }

    /**
     * Manual review and approval
     */
    protected function reviewAndApprove(array &$metadata, array $audiobook = []): bool
    {
        return $this->getImportService()->reviewAndApprove(
            $metadata,
            $audiobook,
            fn ($metadata) => $this->buildUiMetadata($metadata),
            fn ($action, $data) => $this->uiService->logMessage($data),
            fn ($question, $options, $default) => $this->selectWithImmediateInterrupt($question, $options, $default),
            fn ($question, $default) => $this->askInline($question, $default),
            fn ($currentCoverUrl, $currentGenre, $currentDirectoryPath, $isFinalConfirmation) => $this->buildReviewOptions($currentCoverUrl, $currentGenre, $currentDirectoryPath, $isFinalConfirmation),
            fn ($metadata, $audiobook) => $this->editMetadataFields($metadata, $audiobook),
            fn ($metadata, $audiobook) => $this->getImportService()->manualEnrichmentWithComparison($metadata, $audiobook, $this->getEnrichmentService()),
            fn () => $this->getEnrichmentService(),
            fn () => $this->getValidGenres(),
            fn ($metadata) => $this->hasEnrichmentData($metadata),
            fn ($metadata, $options) => $this->getImportService()->generateDirectoryPath($metadata, $options),
            $this->inputInterrupted
        );
    }

    protected function buildReviewOptions(
        string $currentCoverUrl,
        string $currentGenre,
        string $currentDirectoryPath,
        bool $isFinalConfirmation
    ): array {
        return $this->getImportService()->buildReviewOptions(
            $currentCoverUrl,
            $currentGenre,
            $currentDirectoryPath,
            $isFinalConfirmation,
            fn () => $this->getValidGenres()
        );
    }

    /**
     * Edit metadata fields interactively
     */
    protected function editMetadataFields(array $metadata, array $audiobook): array
    {
        return $this->getImportService()->editMetadataFields(
            $metadata,
            $audiobook,
            fn ($question, $default) => $this->askInline($question, $default),
            fn ($question, $options, $default) => $this->selectWithImmediateInterrupt($question, $options, $default),
            fn ($metadata, $keys) => $this->getFirstNonEmptyMetadataValue($metadata, $keys),
            fn (&$metadata) => $this->extractSeriesNumberFromTitle($metadata),
            fn () => $this->getValidGenres()
        );
    }

    protected function detectMultiBookPattern(string $title): ?array
    {
        return $this->getImportService()->detectMultiBookPattern($title);
    }

    protected function analyzeMultiBookFiles(array $audiobook, array $multiBookInfo): array
    {
        return $this->getImportService()->analyzeMultiBookFiles($audiobook, $multiBookInfo);
    }

    protected function extractBookTitleFromFilename(string $filename, string $seriesName, int $bookNumber): string
    {
        return $this->getImportService()->extractBookTitleFromFilename($filename, $seriesName, $bookNumber);
    }

    protected function extractBookNumberFromFilename(string $filename): ?int
    {
        return $this->getImportService()->extractBookNumberFromFilename($filename);
    }

    protected function formatFileTypes(array $fileTypes): string
    {
        return $this->getImportService()->formatFileTypes($fileTypes);
    }

    /**
     * Process multi-book directory by splitting into individual books
     */
    protected function processMultiBookSplit(
        array $audiobook,
        array $multiBookInfo,
        array $splitGroups,
        array $aiMetadata
    ): void {
        $this->info("🔄 Processing {$multiBookInfo['series_name']} as split books...");

        $books = $this->getImportService()->processMultiBookSplit($audiobook, $multiBookInfo, $splitGroups, $aiMetadata);

        foreach ($books as $bookData) {
            $this->info("📖 Processing Book {$bookData['metadata']['series_number']} with " . count($bookData['audiobook']['files']) . " files");
            $this->info("📚 Book title: {$bookData['metadata']['title']}");
            $this->processSingleBook($bookData['audiobook'], $bookData['metadata']);
        }
    }

    /**
     * Process a single book (used for both regular books and split multi-books)
     */
    protected function processSingleBook(array $audiobook, array $metadata): void
    {
        $this->getImportService()->processSingleBook(
            $audiobook,
            $metadata,
            fn ($metadata) => $this->enrichWithExternalData($metadata),
            fn ($metadata, $enrichedData) => $this->getEnrichmentService()->isValidEnrichment($metadata, $enrichedData),
            fn ($metadata) => $this->getImportService()->generateDirectoryPath($metadata),
            fn ($metadata, $audiobook) => $this->getImportService()->createBookFromMetadata($metadata, $audiobook),
            fn ($audiobook, $book, $options) => $this->getImportService()->moveFilesToLibrary($audiobook, $book, $options),
            fn () => $this->getFileOperation(),
            fn ($message) => $this->info($message),
            fn ($metadata) => $this->displayEnrichedMetadata($metadata),
            fn ($metadata) => $this->reviewAndApprove($metadata),
            fn ($metadata) => $this->hasEnrichmentData($metadata),
            (bool) $this->option('skip-enrichment'),
            (bool) $this->option('auto'),
            (bool) $this->option('dry-run'),
            $this->skippedBooks,
            $this->processedBooks
        );
    }

    /**
     * Extract series number from title and clean the title
     */
    protected function extractSeriesNumberFromTitle(array &$metadata): void
    {
        $this->getImportService()->extractSeriesNumberFromTitle($metadata);
        if (isset($metadata['series_number'])) {
            return;
        }
    }

    protected function getValidGenres(): array
    {
        return $this->getImportService()->getValidGenres();
    }

    /**
     * Enrich metadata with external data sources
     */
    protected function enrichWithExternalData(array $metadata, array $options = []): array
    {
        return $this->getEnrichmentService()->enrichWithExternalData($metadata, $options);
    }

    /**
     * Download cover image to book directory using ExternalCoverService
     */
    protected function downloadCoverImage(string $imageUrl, string $directoryPath, string $source = 'unknown'): ?string
    {
        return $this->getImportService()->downloadCoverImage($imageUrl, $directoryPath, $source, $this->coverService);
    }

    /**
     * Analyze if a cover image is a low-quality text-on-white cover
     */
    protected function isTextOnWhiteCover(string $imagePath): bool
    {
        return $this->getImportService()->isTextOnWhiteCover($imagePath, $this->coverAnalysisService);
    }

    /**
     * Search for alternative book covers using Google Image Search
     */
    protected function searchAlternativeCovers(array $metadata, int $limit = 3): array
    {
        return $this->getImportService()->searchAlternativeCovers($metadata, $limit, $this->googleImageService);
    }

    /**
     * Handle cover selection - analyze current cover and offer alternatives if needed
     */
    protected function handleCoverSelection(array &$metadata): void
    {
        $isInteractive = !$this->option('auto');

        $this->getImportService()->handleCoverSelection(
            $metadata,
            fn ($path) => $this->isTextOnWhiteCover($path),
            fn ($metadata, $limit) => $this->searchAlternativeCovers($metadata, $limit),
            fn ($message) => $this->warn($message),
            fn ($message) => $this->line($message),
            fn ($message) => $this->info($message),
            fn ($message) => $this->comment($message),
            fn ($coverOptions, $metadata) => $this->displayCoverOptions($coverOptions, $metadata),
            fn ($coverOptions) => $this->promptForCoverSelection($coverOptions),
            $isInteractive
        );
    }

    /**
     * Display available cover options
     */
    protected function displayCoverOptions(array $coverOptions, array $metadata): void
    {
        $this->getImportService()->displayCoverOptions(
            $coverOptions,
            fn ($url) => $this->displayCoverImage($url),
            fn () => $this->newLine(),
            fn ($message) => $this->line($message)
        );
    }

    /**
     * Prompt user to select a cover from available options
     */
    protected function promptForCoverSelection(array $coverOptions): ?string
    {
        return $this->getImportService()->promptForCoverSelection(
            $coverOptions,
            fn ($question, $choices, $default) => $this->choice($question, $choices, $default),
            fn ($question) => $this->ask($question)
        );
    }

    /**
     * Clean description text (remove HTML, limit length, etc.)
     */
    protected function cleanDescription(string $description): string
    {
        return $this->getImportService()->cleanDescription($description);
    }

    /**
     * Create book record in database
     */
    protected function createBookFromMetadata(array $metadata, array $audiobook): ?Book
    {
        return $this->getImportService()->createBookFromMetadata(
            $metadata,
            $audiobook,
            [
                'download_cover' => true,
                'cover_source' => isset($metadata['audible_raw']) ? 'audible' : 'googlebooks',
            ]
        );
    }

    /**
     * Generate directory path for book storage
     */
    protected function generateDirectoryPath(array $metadata): string
    {
        return $this->getImportService()->generateDirectoryPath($metadata);
    }

    /**
     * Flatten CD subdirectories by moving all files to the main directory
     */
    protected function flattenCdDirectories(string $sourcePath): void
    {
        $this->getImportService()->flattenCdDirectories($sourcePath);
    }

    /**
     * Get all files from a directory recursively
     */
    protected function getAllFilesFromDirectory(string $path): array
    {
        return $this->getImportService()->getAllFilesFromDirectory($path);
    }

    /**
     * Check if two files are identical by comparing size and hash
     */
    protected function areFilesIdentical(string $file1, string $file2): bool
    {
        return $this->getImportService()->areFilesIdentical($file1, $file2);
    }

    /**
     * Check if a filename indicates a torrent/piracy tracking file
     */
    protected function isTorrentTrackingFile(string $filename): bool
    {
        return $this->getImportService()->isTorrentTrackingFile($filename);
    }

    /**
     * Extract track number from filename
     */
    protected function extractTrackNumber(string $filename): ?int
    {
        return $this->getImportService()->extractTrackNumber($filename);
    }

    protected function isDirectoryEmpty(string $path): bool
    {
        return $this->getImportService()->isDirectoryEmpty($path);
    }

    /**
     * Move files to library after successful import
     */
    protected function moveFilesToLibrary(array $audiobook, Book $book): bool
    {
        $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
        if (!$bookStoragePath) {
            $this->warn("⚠️  Book storage path not configured - files not moved");
            return false;
        }

        $copyFiles = $this->option('copy-files');
        $options = [
            'storage_path' => $bookStoragePath,
            'operation' => $copyFiles ? 'copy' : 'move',
        ];

        return $this->getImportService()->moveFilesToLibrary($audiobook, $book, $options);
    }

    /**
     * Show cost estimate for AI processing
     */
    protected function showCostEstimate(int $bookCount): void
    {
        $this->getImportService()->showCostEstimate(
            $bookCount,
            fn ($count) => $this->aiProcessor->estimateBatchCost($count),
            fn ($message) => $this->warn($message),
            fn ($message) => $this->error($message),
            fn ($message) => $this->info($message),
            fn ($option) => $this->option($option)
        );
    }

    /**
     * Display processing summary
     */
    protected function displaySummary(): void
    {
        $this->getImportService()->displaySummary(
            $this->totalFound,
            $this->processedBooks,
            $this->failedBooks,
            $this->skippedBooks,
            fn ($message) => $this->info($message),
            fn ($message) => $this->warn($message),
            fn ($message) => $this->line($message),
            fn () => $this->aiProcessor->getTotalCost(),
            fn ($headers, $rows) => $this->table($headers, $rows)
        );
    }


    /**
     * Extract metadata from .nfo files if present
     */
    protected function extractNfoData(string $directoryPath): ?array
    {
        return $this->getImportService()->extractNfoData(
            $directoryPath,
            fn ($message) => $this->info($message)
        );
    }

    protected function parseXmlNfo(string $content): array
    {
        return $this->getImportService()->parseXmlNfo($content);
    }

    /**
     * Parse plain text NFO files
     */
    protected function parsePlainTextNfo(string $content): array
    {
        return $this->getImportService()->parsePlainTextNfo($content);
    }

    /**
     * Handle directory conflicts when target already exists
     */
    protected function handleDirectoryConflict(array $audiobook, string $targetDir): string
    {
        $this->uiService->logMessage("⚠️  Target directory already exists: " . basename($targetDir));

        // Compare directories
        $comparison = $this->compareDirectories($audiobook['path'], $targetDir);

        // Display comparison
        $this->displayDirectoryComparison($comparison);

        // If directories are identical, automatically clean up source
        if ($comparison['identical']) {
            $this->uiService->logMessage("🔍 Directories are identical - source will be automatically deleted");
            return 'skip';
        }

        // If in auto mode, default to replace
        if ($this->option('auto')) {
            $this->uiService->logMessage("🤖 Auto mode: Replacing existing directory");
            return 'replace';
        }

        // Prompt user for action
        $options = [
            '1' => 'Replace existing directory with new files',
            '2' => 'Rename existing directory (backup)',
            '3' => 'Rename new import',
            '4' => 'Rename both directories by narrator',
            '5' => 'Cancel import',
        ];

        $choice = $this->uiService->select("Target directory conflict - choose action", $options, '1');

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
        $this->getImportService()->renameBothDirectoriesByNarrator($audiobook, $targetDir, $book, $bookStoragePath);
        $this->info("📁 Imported new files to: " . basename($targetDir));
    }

    /**
     * Create directory name in format "title (narrator)"
     */
    protected function createNarratorDirectoryName(string $title, string $narrator): string
    {
        return $this->getImportService()->createNarratorDirectoryName($title, $narrator);
    }

    /**
     * Remove series names from title when they contain colons
     */
    protected function removeSeriesFromTitle(string $title): string
    {
        return $this->getImportService()->removeSeriesFromTitle($title);
    }

    /**
     * Get narrator information from audiobook metadata
     */
    protected function getNarratorFromMetadata(array $audiobook): string
    {
        return $this->getImportService()->getNarratorFromMetadata($audiobook);
    }

    /**
     * Get narrator information from existing directory/book
     */
    protected function getNarratorFromDirectory(string $targetDir, ?Book $existingBook): string
    {
        return $this->getImportService()->getNarratorFromDirectory($targetDir, $existingBook);
    }

    /**
     * Move files to narrator-named directory
     */
    protected function moveFilesToNarratorDirectory(array $audiobook, string $targetDir, Book $book): void
    {
        $copyFiles = $this->option('copy-files');
        $this->getImportService()->moveFilesToNarratorDirectory($audiobook, $targetDir, $copyFiles);
    }

    /**
     * Compare two directories for content differences
     */
    protected function compareDirectories(string $sourcePath, string $targetPath): array
    {
        return $this->getImportService()->compareDirectories($sourcePath, $targetPath);
    }

    /**
     * Get detailed information about files in a directory
     */
    protected function getDirectoryInfo(string $path): array
    {
        return $this->getImportService()->getDirectoryInfo($path);
    }

    /**
     * Check if two directories have identical content
     */
    protected function areDirectoriesIdentical(array $sourceFiles, array $targetFiles): bool
    {
        return $this->getImportService()->areDirectoriesIdentical($sourceFiles, $targetFiles);
    }

    /**
     * Display directory comparison information
     */
    protected function displayDirectoryComparison(array $comparison): void
    {
        $this->getImportService()->displayDirectoryComparison(
            $comparison,
            fn ($bytes) => $this->formatBytes($bytes),
            fn ($fileTypes) => $this->formatFileTypes($fileTypes),
            fn ($message) => $this->line($message),
            fn ($headers, $rows) => $this->table($headers, $rows)
        );
    }

    /**
     * Prompt user for action when duplicate is detected but can't be compared
     * Returns true if import should continue, false if it should be skipped
     */
    protected function promptForDuplicateAction(array $audiobook, $existingBook): bool
    {
        $options = [
            '1' => 'Skip import (keep both)',
            '2' => 'Delete source directory',
            '3' => 'Continue with import anyway',
        ];

        $action = $this->getImportService()->promptForDuplicateAction(
            $options,
            fn ($question, $options, $default) => $this->uiService->select($question, $options, $default),
            fn ($message) => $this->uiService->logMessage($message),
            fn ($audiobook, $force) => $this->cleanupSourceDirectory($audiobook, $force),
            $audiobook,
            $this->skippedBooks,
            $existingBook
        );

        return $action === 'continue';
    }

    /**
     * Get data source based on AI model used
     */
    protected function getDataSource(): string
    {
        return $this->getImportService()->getDataSource($this->option('model') ?? 'gpt-4');
    }

    protected function calculateAudioInfo(array $audioFiles): array
    {
        return $this->getImportService()->calculateAudioInfo($audioFiles);
    }

    protected function parseDurationString(string $duration): int
    {
        return $this->getImportService()->parseDurationString($duration);
    }

    /**
     * Get audio file duration directly from file metadata
     */
    protected function getAudioFileDuration(string $filePath): int
    {
        return $this->getImportService()->getAudioFileDuration($filePath);
    }

    /**
     * Clean up source directory after successful operations
     */
    protected function cleanupSourceDirectory(array $audiobook, bool $filesAlreadyExist = false): void
    {
        $isCopyOperation = (bool) $this->option('copy-files');
        $this->getImportService()->cleanupSourceDirectory($audiobook, $filesAlreadyExist, $isCopyOperation);

        if (!$isCopyOperation && File::isDirectory($audiobook['path'])) {
            if ($filesAlreadyExist) {
                $this->info("✅ Removed duplicate source directory (identical files already exist in library)");
            } else {
                $remainingFiles = File::files($audiobook['path']);
                if (empty($remainingFiles)) {
                    $this->info("🗑️  Removed empty source directory");
                }
            }
        }
    }

    /**
     * Check if metadata contains enrichment data from external sources
     */
    protected function hasEnrichmentData(array $metadata): bool
    {
        return $this->getImportService()->hasEnrichmentData($metadata);
    }

    protected function mapToValidGenre(string $genre): string
    {
        return $this->getImportService()->mapToValidGenre($genre);
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
                'cache_file' => $this->cacheFilePath ?? storage_path('app/import_cache.json'),
                'max_age' => 86400,
                'max_size_mb' => 100,
            ];
            $this->cacheService = new ImportCacheService(app(\Illuminate\Filesystem\Filesystem::class), $options);
        }
        return $this->cacheService;
    }
}
