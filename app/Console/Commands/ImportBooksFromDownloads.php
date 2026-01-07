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
use App\Services\CoverImageAnalysisService;
use App\Services\ExternalCoverService;
use App\Services\GoogleBooksApiService;
use App\Services\GoogleImageSearchService;
use App\Services\ImportCacheService;
use App\Services\ImportUIService;
use App\Traits\GenreMapping;
use App\Traits\BookImportTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
        if (!is_array($aiMetadata)) {
            $aiMetadata = [];
        }

        $tagMetadata = $this->extractTagMetadataFromAudiobook($audiobook);
        if (!empty($tagMetadata)) {
            $aiMetadata = $this->mergeMetadataFillMissing($aiMetadata, $tagMetadata);
        }

        $aiMetadata['confidence'] = (int) ($aiMetadata['confidence'] ?? 0);
        $aiMetadata['title'] = $aiMetadata['title'] ?? ($audiobook['name'] ?? '');
        $aiMetadata['source_path'] = $aiMetadata['source_path'] ?? ($audiobook['path'] ?? '');

        $minConfidence = (int) $this->option('min-confidence');
        $shouldTryAudio = $aiMetadata['confidence'] < $minConfidence
            || (bool) $this->option('force-audio');

        if ($shouldTryAudio && $this->hasCriticalTagMetadata($tagMetadata)) {
            $shouldTryAudio = false;
        }

        if (!$shouldTryAudio) {
            return false;
        }

        if ($this->option('force-audio')) {
            $message = '🎵 Forcing audio analysis (--force-audio flag used)';
            if ($this->uiService) {
                $this->uiService->logMessage($message);
            } else {
                $this->info($message);
            }
        } else {
            $warningMessage = "⚠️  AI confidence too low ({$aiMetadata['confidence']}%) " .
                '- trying audio analysis fallback';
            if ($this->uiService) {
                $this->uiService->logMessage($warningMessage);
            } else {
                $this->warn($warningMessage);
            }
        }

        $audioMetadata = $this->processWithAudioAnalysis($audiobook);
        if ($audioMetadata && (int) ($audioMetadata['confidence'] ?? 0) >= $minConfidence) {
            $this->info("✅ Audio analysis successful with {$audioMetadata['confidence']}% confidence");
            $aiMetadata = $audioMetadata;
            $aiMetadata['confidence'] = (int) ($aiMetadata['confidence'] ?? 0);
            $aiMetadata['source_path'] = $aiMetadata['source_path'] ?? ($audiobook['path'] ?? '');
            return false;
        }

        if ($this->option('force-audio')) {
            $this->warn('⚠️  Audio analysis failed but continuing due to --force-audio flag');
            return false;
        }

        if (!$this->option('auto')) {
            $warning = '⚠️  Audio analysis also failed - continuing in interactive mode with low-confidence metadata';
            if ($this->uiService) {
                $this->uiService->logMessage($warning);
            } else {
                $this->warn($warning);
            }

            return false;
        }

        $this->warn('⚠️  Audio analysis also failed - skipping (auto mode)');
        $currentProvider = config('services.ai.default_provider', 'gemini');
        if ($currentProvider === 'gemini' && empty(config('services.gemini.api_key'))) {
            $this->warn('   💡 Tip: Add GEMINI_API_KEY to your .env file to enable audio transcription');
        } elseif ($currentProvider === 'claude' && empty(config('services.openai.api_key'))) {
            $this->warn('   💡 Tip: Claude doesn\'t support audio transcription. Add OPENAI_API_KEY for fallback');
        }

        $this->skippedBooks[] = [
            'path' => $audiobook['path'],
            'reason' => 'Low AI confidence (tried audio analysis)',
        ];

        return true;
    }

    protected function extractTagMetadataFromAudiobook(array $audiobook): array
    {
        if (empty($audiobook['files']) || !$this->aiProcessor) {
            return [];
        }

        $fileTags = [];
        foreach (array_slice($audiobook['files'], 0, 3) as $filePath) {
            $ext = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
            if ($ext !== 'm4b' && $ext !== 'mp3') {
                continue;
            }

            $tags = $this->aiProcessor->extractFileTags($filePath);
            if (!empty($tags)) {
                $fileTags[basename((string) $filePath)] = $tags;
                // do not break; we want embedded covers even if first file lacks it
            }
        }

        return $this->getImportService()->extractMetadataFromFileTags($fileTags);
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
        $uiMetadata = $metadata;

        $coverSource = '';

        if (!empty($uiMetadata['cover_data'])) {
            $tempPath = $this->getEmbeddedCoverTempPath($uiMetadata['cover_data']);
            if ($tempPath) {
                $uiMetadata['cover_url'] = $tempPath;
                $uiMetadata['cover_is_local_file'] = true;
                $coverSource = 'Embedded';
            }
        } elseif (!empty($uiMetadata['cover_url'])) {
            if (isset($uiMetadata['audible_raw'])) {
                $coverSource = 'Audible';
            } elseif (isset($uiMetadata['google_books_raw'])) {
                $coverSource = 'Google Books';
            } else {
                $coverSource = 'Unknown';
            }
        }

        $uiMetadata['directory_path'] = $this->getImportService()->generateDirectoryPath($uiMetadata, [
            'include_title' => true,
        ]);
        $uiMetadata['cover_source'] = $coverSource;

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

                // Directory - scan it for audiobooks
                $this->info("🔍 Processing directory: {$path}");
                $audiobook = $this->processAudiobookDirectory($path);
                if ($audiobook) {
                    $audiobooks[] = $audiobook;
                    $processedDirectories[] = $path;
                }
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
            'total_size' => $fileSize,
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
                'total_size' => $totalSize,
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
                                'total_size' => 0,
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
                            'reason' => 'Parent scan directory - contains subdirectories',
                        ];
                        continue;
                    }

                    // Check if already imported
                    if (!$this->isAlreadyImported($bookData['path'])) {
                        $audiobooks[] = $bookData;
                    } else {
                        $this->skippedBooks[] = [
                            'path' => $bookData['path'],
                            'reason' => 'Already imported',
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
        $data = [];

        if (file_exists($nfoPath)) {
            $content = file_get_contents($nfoPath);

            // Try to parse as XML first
            if (strpos($content, '<?xml') !== false) {
                try {
                    $xml = simplexml_load_string($content);
                    if ($xml) {
                        $data['title'] = (string) $xml->title ?? null;
                        $data['plot'] = (string) $xml->plot ?? null;
                        $data['year'] = (string) $xml->year ?? null;
                        $data['genre'] = (string) $xml->genre ?? null;
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
            'length' => strlen($directoryName),
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

        $binary = base64_decode($coverData, true);
        if (!is_string($binary)) {
            $binary = $coverData;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'embedded_cover_');
        if ($tempFile === false) {
            return null;
        }

        file_put_contents($tempFile, $binary);
        $this->embeddedCoverTempFile = $tempFile;

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
                'similarity' => similar_text($baseName, basename($book->directory_path)),
            ];
        }

        return $results;
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
        if ($this->uiService) {
            $this->uiService->setCurrentBook($this->buildUiMetadata([
                'title' => $audiobook['name'] ?? '',
                'source_path' => $audiobook['path'] ?? '',
                'author' => [],
                'genre' => [],
                'confidence' => 0,
            ]));
        }

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
            return;
        }

        // Skip directories with no audio files
        if (empty($audiobook['files']) || count($audiobook['files']) === 0) {
            $this->warn("⚠️  Skipping {$audiobook['name']} - no audio files found");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'No audio files found',
            ];
            return;
        }

        if ($this->uiService) {
            $this->uiService->logMessage('📖 Processing: ' . $audiobook['name']);
            $this->uiService->logMessage('📁 Path: ' . $audiobook['path']);
            $this->uiService->logMessage(
                '📄 Files: ' . count($audiobook['files']) . ' (' . $this->formatBytes($audiobook['total_size']) . ')'
            );
            $this->uiService->logMessage('🤖 Analyzing metadata with AI...');
        } else {
            $this->newLine();
            $this->info("📖 Processing: " . $audiobook['name']);
            $this->line("📁 Path: " . $audiobook['path']);
            $this->line(
                "📄 Files: " . count($audiobook['files']) . " (" . $this->formatBytes($audiobook['total_size']) . ")"
            );
        }

        $aiMetadata = $this->processWithAI($audiobook);

        if ($this->handleLowConfidenceMetadata($audiobook, $aiMetadata)) {
            return;
        }

        $successMessage = "✅ AI processing successful (confidence: {$aiMetadata['confidence']}%)";
        if ($this->uiService) {
            $this->uiService->logMessage($successMessage);
        } else {
            $this->info($successMessage);
        }

        // Check for multi-book directory pattern first
        $multiBookInfo = $this->detectMultiBookPattern($audiobook['name']);
        if ($multiBookInfo) {
            // Clean series name by removing author names
            $authors = is_array($aiMetadata['author']) ? $aiMetadata['author'] : [$aiMetadata['author']];
            $cleanedSeriesName = $this->getImportService()->cleanSeriesName($multiBookInfo['series_name'], $authors);
            $multiBookInfo['series_name'] = $cleanedSeriesName;

            $this->info(
                "📚 Detected multi-book directory: {$cleanedSeriesName} " .
                "[{$multiBookInfo['start_number']}-{$multiBookInfo['end_number']}]"
            );

            // Analyze files to see if they can be split
            $splitGroups = $this->analyzeMultiBookFiles($audiobook, $multiBookInfo);

            if (count($splitGroups) >= 2) {
                $this->info("🔍 Found individual book files - will split during import");
                $this->processMultiBookSplit($audiobook, $multiBookInfo, $splitGroups, $aiMetadata);
                return;
            } else {
                $this->info(
                    '📖 No individual book files found - will create combined entry with multiple series numbers'
                );
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
                            'reason' => 'Duplicate - source cleaned up (identical to existing)',
                        ];
                        return;
                    } else {
                        $this->warn("📁 Directories differ - manual decision needed");
                        $comparisonExists = is_array($comparison) ? 'YES' : 'NO';
                        $this->line('🔍 Debug: Comparison data structure exists: ' . $comparisonExists);
                        if (is_array($comparison)) {
                            $this->line("🔍 Debug: Keys present: " . implode(', ', array_keys($comparison)));
                        }
                        $this->displayDirectoryComparison($comparison);

                        $options = [
                            '1' => 'Skip import completely',
                            '2' => 'Replace existing with source',
                            '3' => 'Delete source (keep existing)',
                            '4' => 'Import anyway with new name',
                        ];

                        $choice = $this->uiService->select("Directories differ - choose action", $options, '1');

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
                                    'reason' => 'User chose to keep existing over source',
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
                                    'reason' => 'User chose to skip import (directory conflict)',
                                ];
                                return;
                        }
                    }
                } else {
                    $this->warn("📁 Cannot compare directories (existing directory not found)");
                    $this->line("  Existing path: {$existingDir}");
                    $shouldContinue = $this->promptForDuplicateAction($audiobook, $existingBook);
                    if (!$shouldContinue) {
                        return;
                    }
                }
            } else {
                $this->warn("📁 Cannot compare directories (storage path or directory path missing)");
                $shouldContinue = $this->promptForDuplicateAction($audiobook, $existingBook);
                if (!$shouldContinue) {
                    return;
                }
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
        $this->displayEnrichedMetadata($aiMetadata);
        $this->newLine();

        // Show expected directory path
        $aiMetadata['source_path'] = $audiobook['path']; // Add source path for GraphicAudio detection
        $expectedPath = $this->getImportService()->generateDirectoryPath($aiMetadata);
        $this->info("📁 Expected directory path: {$expectedPath}");

        // Step 3: Manual review (unless in auto mode)
        if (!$this->option('auto') && !$this->option('dry-run')) {
            if (!$this->reviewAndApprove($aiMetadata, $audiobook)) {
                $this->warn("❌ Import rejected by user");
                $this->skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'Rejected by user',
                ];
                return;
            }
        } elseif ($this->option('auto') && !$this->hasEnrichmentData($aiMetadata)) {
            // In auto mode, skip books with no enrichment data as the detected fields might be wrong
            $this->warn("⚠️  No enrichment data found in auto mode - skipping (detected fields might be incorrect)");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'No enrichment data in auto mode',
            ];
            return;
        }

        // Step 4: Import to database
        if (!$this->option('dry-run')) {
            if ($this->uiService) {
                $this->uiService->logMessage('💾 Creating database record...');
            }

            $book = $this->getImportService()->createBookFromMetadata($aiMetadata, $audiobook);

            if ($book) {
                $this->info("✅ Book imported successfully: {$book->title} (ID: {$book->id})");

                // Step 5: Move/copy files
                $this->getImportService()->moveFilesToLibrary($audiobook, $book, [
                    'operation' => $this->getFileOperation(),
                ]);

                $this->processedBooks[] = [
                    'path' => $audiobook['path'],
                    'book_id' => $book->id,
                    'title' => $book->title,
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

            // Debug: Show what we're passing to the AI
            $this->line("🔍 AI Input Debug:");
            $this->line("  Directory: " . $audiobook['path']);
            $filesPreview = implode(', ', array_slice($fileNames, 0, 5));
            if (count($fileNames) > 5) {
                $filesPreview .= '...';
            }
            $this->line("  Files (" . count($fileNames) . "): " . $filesPreview);
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
                $authorValue = $aiResult['author'] ?? 'N/A';
                if (is_array($authorValue)) {
                    $authorValue = implode(', ', $authorValue);
                }
                $this->line('  Author: ' . $authorValue);

                // Post-process AI result to fix common issues with numbered series books
                $aiResult = $this->postProcessAIResult($aiResult, $audiobook);

                $tagMetadata = $this->getImportService()->extractMetadataFromFileTags($fileTags);
                $aiResult = $this->mergeMetadataFillMissing($aiResult, $tagMetadata);
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

        $this->handleCoverSelection($metadata);

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

        return trim($newCoverUrl) !== '' ? $newCoverUrl : $currentCoverUrl;
    }

    /**
     * Display cover image if terminal supports it (like Ghostty with Kitty protocol)
     */
    protected function displayCoverImage(string $imageUrl): void
    {
        if ($this->uiService) {
            return;
        }

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
            if (!$imageInfo) {
                $this->line("  (Could not read image dimensions)");
                return;
            }
            $width = $imageInfo[0];
            $height = $imageInfo[1];

            // Calculate thumbnail size (max 200px wide, maintain aspect ratio)
            $maxWidth = 200;
            $scale = min($maxWidth / $width, $maxWidth / $height);
            $thumbWidth = (int) ($width * $scale);
            $thumbHeight = (int) ($height * $scale);

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
        if (!$imageInfo) {
            return null;
        }

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

        if (!$source) {
            return null;
        }

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
            $thumb,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $imageInfo[0],
            $imageInfo[1]
        );

        imagedestroy($source);
        return $thumb;
    }

    /**
     * Manual review and approval
     */
    protected function reviewAndApprove(array &$metadata, array $audiobook = []): bool
    {
        $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));

        $currentDirectoryPath = (string) ($metadata['custom_directory_path'] ?? '');
        if ($currentDirectoryPath === '') {
            $currentDirectoryPath = $this->getImportService()->generateDirectoryPath($metadata, [
                'include_title' => true,
            ]);
        }

        $currentGenre = $metadata['genre'] ?? 'Other';
        if (is_array($currentGenre)) {
            $currentGenre = $currentGenre[0] ?? 'Other';
        }

        $currentCoverUrl = (string) ($metadata['cover_url'] ?? '');

        $validGenres = $this->getValidGenres();
        $normalizedGenre = is_string($currentGenre) ? trim($currentGenre) : '';
        $isGenreValid = in_array($normalizedGenre, $validGenres, true);

        // If no enrichment data found, assume detected fields are wrong and skip auto-approval
        if (!$this->hasEnrichmentData($metadata)) {
            $this->uiService->logMessage("⚠️  No external enrichment data found - detected fields may be incorrect");
        } else {
            // Ask if user wants to accept all fields as shown
            $confidence = $metadata['confidence'] ?? 0;
            $defaultChoice = $confidence > 80 ? '1' : '2';
            if (!$isGenreValid) {
                $defaultChoice = '2';
            }

            while (true) {
                $options = $this->buildReviewOptions($currentCoverUrl, $currentGenre, $currentDirectoryPath, false);
                $choice = $this->selectWithImmediateInterrupt('Choose an option', $options, $defaultChoice);

                // Normalize choice to handle letters
                $choice = strtolower(trim($choice));
                if (in_array($choice, ['1', 'a', 'accept'], true)) {
                    if (!$isGenreValid) {
                        $this->uiService->logMessage('⚠️  Cannot accept: genre is invalid - please update genre first');
                        continue;
                    }
                    return true;
                }
                if (in_array($choice, ['3', 's', 'skip'], true)) {
                    return false;
                }

                if ($choice === '4') {
                    $metadata['cover_url'] = $this->promptForCoverUrl($currentCoverUrl);
                    $currentCoverUrl = (string) ($metadata['cover_url'] ?? '');
                    $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
                    continue;
                }

                if ($choice === '5') {
                    $validGenres = $this->getValidGenres();
                    $genreOptions = [];
                    foreach ($validGenres as $idx => $g) {
                        $genreOptions[(string) ($idx + 1)] = $g;
                    }

                    $currentGenreIdx = array_search($currentGenre, $validGenres, true);
                    if ($currentGenreIdx !== false) {
                        $defaultGenreIdx = (string) ($currentGenreIdx + 1);
                    } else {
                        $defaultGenreIdx = (string) count($validGenres);
                    }

                    $selectedGenreIdx = $this->selectWithImmediateInterrupt('Genre', $genreOptions, $defaultGenreIdx);
                    $metadata['genre'] = $genreOptions[$selectedGenreIdx] ?? $currentGenre;
                    $currentGenre = $metadata['genre'] ?? $currentGenre;
                    $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
                    continue;
                }

                if ($choice === '6') {
                    $metadata['custom_directory_path'] = $this->askInline('Directory', $currentDirectoryPath);
                    $currentDirectoryPath = (string) ($metadata['custom_directory_path'] ?? $currentDirectoryPath);
                    $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
                    continue;
                }

                if ($choice === '7') {
                    $metadata = $this->manualEnrichmentWithComparison($metadata, $audiobook);
                    $currentCoverUrl = (string) ($metadata['cover_url'] ?? '');
                    $currentGenre = $metadata['genre'] ?? 'Other';
                    if (is_array($currentGenre)) {
                        $currentGenre = $currentGenre[0] ?? 'Other';
                    }
                    $normalizedGenre = is_string($currentGenre) ? trim($currentGenre) : '';
                    $isGenreValid = in_array($normalizedGenre, $validGenres, true);
                    $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
                    continue;
                }

                // Case '2' (Edit) continues below
                break;
            }
        }

        // Offer individual field editing
        $this->uiService->logMessage("📝 Editing individual fields...");
        $metadata = $this->editMetadataFields($metadata, $audiobook);
        if ($this->inputInterrupted) {
            return false;
        }

        // Show updated metadata
        $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));

        // Final confirmation loop
        while (true) {
            $options = $this->buildReviewOptions($currentCoverUrl, $currentGenre, $currentDirectoryPath, true);

            $finalDefaultChoice = $isGenreValid ? '1' : '2';
            $choice = $this->selectWithImmediateInterrupt("Final confirmation", $options, $finalDefaultChoice);

            $choice = strtolower(trim($choice));
            if ($choice === '1' || $choice === 'a' || $choice === 'accept') {
                if (!$isGenreValid) {
                    $this->uiService->logMessage('⚠️  Cannot accept: genre is invalid - please update genre first');
                    continue;
                }
                return true;
            }
            if ($choice === '2' || $choice === 'e' || $choice === 'edit') {
                $metadata = $this->editMetadataFields($metadata, $audiobook);
                $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
                continue;
            }
            if ($choice === '3' || $choice === 's' || $choice === 'skip') {
                return false;
            }

            if ($choice === '4') {
                $metadata['cover_url'] = $this->promptForCoverUrl($currentCoverUrl);
                $currentCoverUrl = (string) ($metadata['cover_url'] ?? '');
                $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
                continue;
            }

            if ($choice === '5') {
                $validGenres = $this->getValidGenres();
                $genreOptions = [];
                foreach ($validGenres as $idx => $g) {
                    $genreOptions[(string) ($idx + 1)] = $g;
                }

                $currentGenreIdx = array_search($currentGenre, $validGenres, true);
                if ($currentGenreIdx !== false) {
                    $defaultGenreIdx = (string) ($currentGenreIdx + 1);
                } else {
                    $defaultGenreIdx = (string) count($validGenres);
                }

                $selectedGenreIdx = $this->selectWithImmediateInterrupt('Genre', $genreOptions, $defaultGenreIdx);
                $metadata['genre'] = $genreOptions[$selectedGenreIdx] ?? $currentGenre;
                $currentGenre = $metadata['genre'] ?? $currentGenre;
                $normalizedGenre = is_string($currentGenre) ? trim($currentGenre) : '';
                $isGenreValid = in_array($normalizedGenre, $validGenres, true);
                $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
                continue;
            }

            if ($choice === '6') {
                $metadata['custom_directory_path'] = $this->askInline('Directory', $currentDirectoryPath);
                $currentDirectoryPath = (string) ($metadata['custom_directory_path'] ?? $currentDirectoryPath);
                $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
                continue;
            }

            if ($choice === '7') {
                $metadata = $this->manualEnrichmentWithComparison($metadata, $audiobook);
                $currentCoverUrl = (string) ($metadata['cover_url'] ?? '');
                $currentGenre = $metadata['genre'] ?? 'Other';
                if (is_array($currentGenre)) {
                    $currentGenre = $currentGenre[0] ?? 'Other';
                }
                $normalizedGenre = is_string($currentGenre) ? trim($currentGenre) : '';
                $isGenreValid = in_array($normalizedGenre, $validGenres, true);
                $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
                continue;
            }
        }
    }

    protected function buildReviewOptions(
        string $currentCoverUrl,
        string $currentGenre,
        string $currentDirectoryPath,
        bool $isFinalConfirmation
    ): array {
        $validGenres = $this->getValidGenres();
        $normalizedGenre = trim($currentGenre);
        $isGenreValid = in_array($normalizedGenre, $validGenres, true);

        $displayGenre = $normalizedGenre;
        if (strlen($displayGenre) > 16) {
            $displayGenre = substr($displayGenre, 0, 15) . '…';
        }

        $acceptLabel = $isFinalConfirmation ? 'Accept all' : 'Accept all metadata';
        if (!$isGenreValid) {
            $acceptLabel = "\e[9m{$acceptLabel}\e[0m";
        }

        $options = [
            '1' => $acceptLabel,
            '2' => $isFinalConfirmation ? 'Edit again' : 'Edit individual fields',
            '3' => $isFinalConfirmation ? 'Skip' : 'Skip this book',
            '4' => 'Update cover' . ($currentCoverUrl !== '' ? ' (has URL)' : ''),
            '5' => 'Update genre (' . $displayGenre . ')',
            '6' => 'Update directory',
            '7' => 'Request enrichment (Audible/Google Books)',
        ];

        return $options;
    }

    /**
     * Edit metadata fields interactively
     */
    protected function editMetadataFields(array $metadata, array $audiobook): array
    {
        // Edit title
        $currentTitle = $this->getFirstNonEmptyMetadataValue($metadata, ['title', 'book_title', 'name']);
        $metadata['title'] = $this->askInline('Title', is_string($currentTitle) ? $currentTitle : (string) ($metadata['title'] ?? ''));
        if ($this->inputInterrupted) {
            return $metadata;
        }

        // Edit author
        $currentAuthor = $this->getFirstNonEmptyMetadataValue($metadata, ['author', 'authors', 'authorName', 'author_name']);
        if (is_array($currentAuthor)) {
            $currentAuthor = implode(', ', $currentAuthor);
        }
        $newAuthor = $this->askInline("Author(s) (comma-separated)", $currentAuthor);
        if ($this->inputInterrupted) {
            return $metadata;
        }
        $metadata['author'] = array_map('trim', explode(',', $newAuthor));

        // Edit narrator
        $currentNarrator = $this->getFirstNonEmptyMetadataValue($metadata, ['narrator', 'narrators', 'narratorName', 'narrator_name']);
        if (is_array($currentNarrator)) {
            $currentNarrator = implode(', ', $currentNarrator);
        }
        $newNarrator = $this->askInline('Narrator(s) (comma-separated)', is_string($currentNarrator) ? $currentNarrator : '');
        if ($this->inputInterrupted) {
            return $metadata;
        }
        $metadata['narrator'] = array_map('trim', explode(',', $newNarrator));

        // Edit genre with dropdown
        $validGenres = $this->getValidGenres();
        $genreOptions = [];
        foreach ($validGenres as $idx => $g) {
            $genreOptions[$idx + 1] = $g;
        }

        $currentGenre = $this->getFirstNonEmptyMetadataValue($metadata, ['genre', 'genres', 'genreName', 'genre_name']) ?? 'Other';
        if (is_array($currentGenre)) {
            $currentGenre = $currentGenre[0] ?? 'Other';
        }
        $currentGenreIdx = array_search($currentGenre, $validGenres);
        // Default to last (Other) if not found
        $defaultGenreIdx = ($currentGenreIdx !== false) ? $currentGenreIdx + 1 : count($validGenres);

        $selectedGenreIdx = $this->selectWithImmediateInterrupt("Genre", $genreOptions, (string) $defaultGenreIdx);
        if ($this->inputInterrupted) {
            return $metadata;
        }
        $metadata['genre'] = $genreOptions[$selectedGenreIdx] ?? $currentGenre;

        // Edit series
        $currentSeries = $this->getFirstNonEmptyMetadataValue($metadata, ['series', 'seriesName', 'series_name']);
        $metadata['series'] = $this->askInline('Series', is_string($currentSeries) ? $currentSeries : (string) ($metadata['series'] ?? ''));
        if ($this->inputInterrupted) {
            return $metadata;
        }

        // Edit series number
        $currentSeriesNumber = $this->getFirstNonEmptyMetadataValue($metadata, ['series_number', 'seriesNumber', 'series_num', 'seriesNum']);
        $metadata['series_number'] = $this->askInline(
            'Series Number',
            is_scalar($currentSeriesNumber) ? (string) $currentSeriesNumber : (string) ($metadata['series_number'] ?? '')
        );
        if ($this->inputInterrupted) {
            return $metadata;
        }

        // Edit year
        $currentYear = $this->getFirstNonEmptyMetadataValue($metadata, ['year', 'publishedYear', 'published_year', 'published_date']);
        if (is_string($currentYear) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $currentYear)) {
            $currentYear = substr($currentYear, 0, 4);
        }
        $metadata['year'] = $this->askInline('Year', is_scalar($currentYear) ? (string) $currentYear : (string) ($metadata['year'] ?? ''));
        if ($this->inputInterrupted) {
            return $metadata;
        }

        $currentDirectory = (string) ($metadata['custom_directory_path'] ?? '');
        if ($currentDirectory === '') {
            $currentDirectory = $this->getImportService()->generateDirectoryPath($metadata, [
                'include_title' => true,
            ]);
        }

        $metadata['custom_directory_path'] = $this->askInline('Directory', $currentDirectory);
        if ($this->inputInterrupted) {
            return $metadata;
        }

        // Extract series number from edited title if present
        $this->extractSeriesNumberFromTitle($metadata);

        return $metadata;
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
                    'reason' => 'Rejected by user',
                ];
                return;
            }
        } elseif ($this->option('auto') && !$this->hasEnrichmentData($metadata)) {
            // In auto mode, skip books with no enrichment data
            $this->warn("⚠️  No enrichment data found in auto mode - skipping (detected fields might be incorrect)");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'No enrichment data in auto mode',
            ];
            return;
        }

        // Import to database
        if (!$this->option('dry-run')) {
            if ($this->uiService) {
                $this->uiService->logMessage('💾 Creating database record...');
            }

            $book = $this->getImportService()->createBookFromMetadata($metadata, $audiobook);

            if ($book) {
                $this->info("✅ Book imported successfully: {$book->title} (ID: {$book->id})");

                // Move/copy files
                $this->getImportService()->moveFilesToLibrary($audiobook, $book, [
                    'operation' => $this->getFileOperation(),
                ]);

                $this->processedBooks[] = [
                    'path' => $audiobook['path'],
                    'book_id' => $book->id,
                    'title' => $book->title,
                ];
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
        $this->getImportService()->extractSeriesNumberFromTitle($metadata);
        if (isset($metadata['series_number'])) {
            $this->info("📚 Extracted series number {$metadata['series_number']} from title");
        }
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
     * Analyze if a cover image is a low-quality text-on-white cover
     */
    protected function isTextOnWhiteCover(string $imagePath): bool
    {
        if (!$this->coverAnalysisService) {
            $this->coverAnalysisService = app(CoverImageAnalysisService::class);
        }

        return $this->coverAnalysisService->isTextOnWhiteCover($imagePath);
    }

    /**
     * Search for alternative book covers using Google Image Search
     */
    protected function searchAlternativeCovers(array $metadata, int $limit = 3): array
    {
        if (!$this->googleImageService) {
            $this->googleImageService = app(GoogleImageSearchService::class);
        }

        $author = is_array($metadata['author']) ? implode(' ', $metadata['author']) : ($metadata['author'] ?? '');
        $title = $metadata['title'] ?? '';

        if (empty($author) || empty($title)) {
            return ['success' => false, 'images' => [], 'error' => 'Missing author or title'];
        }

        return $this->googleImageService->searchBookCovers($author, $title, $limit);
    }

    /**
     * Handle cover selection - analyze current cover and offer alternatives if needed
     */
    protected function handleCoverSelection(array &$metadata): void
    {
        if (!empty($metadata['cover_data'])) {
            // Embedded cover present; keep it and skip external searches
            return;
        }

        $currentCoverUrl = $metadata['cover_url'] ?? '';
        $isInteractive = !$this->option('auto');
        $coverOptions = [];

        // Check if current cover exists and analyze it
        $hasValidCover = false;
        if (!empty($currentCoverUrl)) {
            // Try to download and analyze the current cover
            $tempCoverPath = null;
            try {
                $tempCoverPath = tempnam(sys_get_temp_dir(), 'cover_') . '.jpg';
                $imageData = @file_get_contents($currentCoverUrl);
                if ($imageData) {
                    file_put_contents($tempCoverPath, $imageData);

                    $isTextOnWhite = $this->isTextOnWhiteCover($tempCoverPath);
                    if ($isTextOnWhite) {
                        $this->warn('⚠️  Current cover appears to be text-only on white background (low quality)');
                        // Add it as an option but mark it as low quality
                        $coverOptions[] = [
                            'url' => $currentCoverUrl,
                            'label' => 'Current cover (text-only - low quality)',
                            'isCurrentLowQuality' => true,
                        ];
                    } else {
                        $hasValidCover = true;
                        $coverOptions[] = [
                            'url' => $currentCoverUrl,
                            'label' => 'Current cover',
                            'isCurrent' => true,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Error analyzing current cover', ['error' => $e->getMessage()]);
            } finally {
                if ($tempCoverPath && file_exists($tempCoverPath)) {
                    @unlink($tempCoverPath);
                }
            }
        }

        // If no valid cover, search for alternatives
        if (!$hasValidCover) {
            $this->line('🔍 Searching for alternative book covers...');
            $searchResults = $this->searchAlternativeCovers($metadata, 3);

            if ($searchResults['success'] && !empty($searchResults['images'])) {
                $this->info('Found ' . count($searchResults['images']) . ' alternative cover(s)');
                foreach ($searchResults['images'] as $index => $image) {
                    $coverOptions[] = [
                        'url' => $image['url'],
                        'label' => 'Google Image ' . ($index + 1),
                        'isGoogle' => true,
                    ];
                }
            } else {
                if (isset($searchResults['error'])) {
                    $this->comment('Could not search for alternative covers: ' . $searchResults['error']);
                }
            }
        }

        // Handle cover selection based on mode
        if (count($coverOptions) === 0) {
            // No covers available at all
            if (empty($currentCoverUrl)) {
                $this->comment('No cover image found');
            }
            return;
        }

        if ($isInteractive && count($coverOptions) > 1) {
            // Interactive mode with multiple options - let user choose
            $this->displayCoverOptions($coverOptions, $metadata);
            $selectedUrl = $this->promptForCoverSelection($coverOptions);
            if ($selectedUrl) {
                $metadata['cover_url'] = $selectedUrl;
            }
        } elseif (!$isInteractive && !$hasValidCover) {
            // Non-interactive mode - use first Google image if current cover is invalid
            $googleOption = collect($coverOptions)->first(function ($opt) {
                return $opt['isGoogle'] ?? false;
            });

            if ($googleOption) {
                $this->info('🤖 Auto-selecting first Google Image cover');
                $metadata['cover_url'] = $googleOption['url'];
            }
        }
    }

    /**
     * Display available cover options
     */
    protected function displayCoverOptions(array $coverOptions, array $metadata): void
    {
        $this->newLine();
        $this->line('📚 Available Cover Options:');
        $this->newLine();

        foreach ($coverOptions as $index => $option) {
            $label = ($index + 1) . '. ' . $option['label'];
            $this->line($label);

            // Display the cover image if supported
            $this->displayCoverImage($option['url']);
            $this->newLine();
        }
    }

    /**
     * Prompt user to select a cover from available options
     */
    protected function promptForCoverSelection(array $coverOptions): ?string
    {
        if (empty($coverOptions)) {
            return null;
        }

        $choices = [];
        foreach ($coverOptions as $index => $option) {
            $choices[(string) ($index + 1)] = $option['label'];
        }
        $choices['0'] = 'None - skip cover';
        $choices['u'] = 'Enter custom URL';

        $selection = $this->choice('Select a cover image', $choices, '1');

        if ($selection === '0' || $selection === 'None - skip cover') {
            return '';
        }

        if ($selection === 'u' || $selection === 'Enter custom URL') {
            $customUrl = $this->ask('Enter cover URL');
            return $customUrl ? trim($customUrl) : null;
        }

        // Find the selected option by matching the label
        foreach ($coverOptions as $index => $option) {
            if ($option['label'] === $selection || (string) ($index + 1) === $selection) {
                return $option['url'];
            }
        }

        return null;
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
        try {
            return DB::transaction(function () use ($metadata, $audiobook) {
                // Create book
                $book = new Book();
                $book->title = $metadata['title'] ?? basename($audiobook['path']);
                $book->description = $metadata['description'] ?? null;
                $metadata['source_path'] = $audiobook['path']; // Add source path for GraphicAudio detection
                $book->directory_path = $this->getImportService()->generateDirectoryPath($metadata);
                $book->language = $metadata['language'] ?? 'en';
                $book->isbn = $metadata['isbn'] ?? null;

                // Handle publisher (may be array from external services)
                if (!empty($metadata['publisher'])) {
                    $publisherName = $metadata['publisher'];
                    if (is_array($publisherName)) {
                        $publisherName = implode(', ', array_filter($publisherName));
                    }

                    $publisher = \App\Models\Publisher::firstOrCreate(['name' => trim($publisherName)]);
                    $book->publisher_id = $publisher->id;
                } else {
                    $book->publisher_id = null;
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
                    $book->release_date = \Illuminate\Support\Carbon::createFromFormat(
                        'Y-m-d',
                        $metadata['year'] . '-01-01'
                    );
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
                    $authorGenre = $this->getImportService()->getAuthorPreferredGenre($metadata['author']);
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
                    $cleanedSeriesName = $this->getImportService()->cleanSeriesName($metadata['series'], $authors);

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
                            ],
                        ]);

                        $this->info("📚 Multi-book entry: Books {$firstNumber}-{$lastNumber} combined");
                    } else {
                        $seriesNumber = $metadata['series_number'] ?? 1;
                        $book->series()->sync([
                            $series->id => ['series_number' => $seriesNumber],
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
        $costEstimate = $this->aiProcessor->estimateBatchCost($bookCount);

        if ($costEstimate['total_cost'] > 0) {
            $this->warn(
                "💰 Estimated AI processing cost: \${$costEstimate['total_cost']} " .
                "(\${$costEstimate['cost_per_book']} per book)"
            );

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
        $nfoData = $this->getImportService()->extractNfoData($directoryPath);
        if (!empty($nfoData)) {
            $this->info("📄 Found .nfo file with metadata");
        }
        return $nfoData;
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
                        $this->formatFileTypes($comparison['source']['file_types'] ?? []),
                    ],
                    [
                        'Target (Existing)',
                        $comparison['target']['count'] ?? 0,
                        $this->formatBytes($comparison['target']['total_size'] ?? 0),
                        $this->formatFileTypes($comparison['target']['file_types'] ?? []),
                    ],
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
        $this->uiService->logMessage("🔍 Duplicate book detected:");
        $this->uiService->logMessage("  Existing: '{$existingBook->title}' (ID: {$existingBook->id})");

        $options = [
            '1' => 'Skip import (keep both)',
            '2' => 'Delete source directory',
            '3' => 'Continue with import anyway',
        ];

        $choice = $this->uiService->select("Duplicate detected - choose action", $options, '1');

        // Normalize choice to handle letters
        $choice = strtolower(trim($choice));
        if (in_array($choice, ['1', 's', 'skip'])) {
            $this->uiService->logMessage("📁 Skipping import, keeping both");
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'User chose to skip (duplicate detected)',
            ];
            return false;
        }

        if (in_array($choice, ['2', 'd', 'delete'])) {
            $this->uiService->logMessage("🗑️ Removing source directory");
            $this->cleanupSourceDirectory($audiobook, true);
            $this->skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'User chose to delete source (duplicate detected)',
            ];
            return false;
        }

        if (in_array($choice, ['3', 'c', 'continue'])) {
            $this->uiService->logMessage("⚠️ Continuing with import despite duplicate detection");
            return true;
        }

        return false;
    }

    /**
     * Get data source based on AI model used
     */
    protected function getDataSource(): string
    {
        $model = $this->option('model');
        return $this->getImportService()->getDataSource($model);
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
     * Check if metadata contains enrichment data from external sources
     */
    protected function hasEnrichmentData(array $metadata): bool
    {
        $enrichmentFields = [
            'audible_raw',
            'google_books_raw',
            'audiobook_bay_raw',
            'cover_url',
        ];

        foreach ($enrichmentFields as $field) {
            if (!empty($metadata[$field])) {
                return true;
            }
        }

        if (!empty($metadata['description']) && strlen($metadata['description']) > 100) {
            return true;
        }

        return false;
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
