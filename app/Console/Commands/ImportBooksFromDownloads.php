<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Narrator;
use App\Models\Series;
use App\Services\AIBookProcessor;
use App\Services\AudioFileAnalyzer;
use App\Services\AudibleService;
use App\Services\BookEnrichmentService;
use App\Services\BookImportService;
use App\Services\CoverImageAnalysisService;
use App\Services\ExternalCoverService;
use App\Services\GoogleBooksApiService;
use App\Services\GoogleImageSearchService;
use App\Contracts\ImportUIInterface;
use App\Services\ImportCacheService;
use App\Services\ImportUIService;
use App\Services\PromptsUIService;
use App\Traits\GenreMapping;
use App\Traits\BookImportTrait;
use Illuminate\Console\Command;
use App\Traits\IsolatesErrorHandlers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class ImportBooksFromDownloads extends Command
{
    use IsolatesErrorHandlers;
    use GenreMapping;
    use BookImportTrait;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'book:import
                            {path?* : Specific files or folders to process (default: scan directories)}
                            {--directory=* : Custom directories to scan (ignored if paths are provided)}
                            {--model=gemini-2.5-flash-lite : AI model to use for processing}
                            {--min-confidence=80 : Minimum AI confidence for auto-import}
                            {--auto : Fully automated mode - no manual review}
                            {--dry-run : Show what would be imported without making changes}
                            {--limit=0 : Maximum number of books to process per run (0 = no limit)}
                            {--force : Skip confirmation prompts}
                            {--force-include : Force inclusion of files that would otherwise be skipped (e.g., too small)}
                            {--skip-enrichment : Skip external data enrichment (Audible, Google Books)}
                            {--copy-files : Copy files after successful import instead of moving (default is move)}
                            {--no-backup : Skip automatic database backup}
                            {--no-cache : Disable background processing cache}
                            {--clear-cache : Clear background processing cache before starting}
                            {--force-audio : Force audio transcription even when AI confidence is high}
                            {--include-narrator : Include narrator in generated directory paths}
                             {--include-old : Include OpenAudible books_old directory when scanning}
                            {--collection= : Add all imported items to this collection}
                            {--genre= : Set a default genre for all imported items}
                            {--pattern= : Alternate storage pattern, e.g., "[genre]/VA/[series]/[title] ([author])"}
                            {--ui=hybrid : UI layer (prompts|ncurses|plain|hybrid)}';

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
    protected ?ImportUIInterface $uiService = null;

    // New services
    protected ?BookEnrichmentService $enrichmentService = null;
    protected ?BookImportService $importService = null;
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
    protected ?string $cacheFilePath = null;
    protected array $backgroundCache = [];
    protected int $cacheVersion = 2; // Increment when cache structure changes
    protected bool $cacheEnabled = true;

    // User interruption handling
    protected bool $userRequestedQuit = false;
    protected array $processedBooks = [];
    protected array $failedBooks = [];
    protected array $skippedBooks = [];
    // Unified chronological history of all processed books (imported + skipped), used by fix-previous feature
    protected array $bookHistory = [];
    // Tracks how far back the user has navigated with "fix previous import" (0 = most recent)
    protected int $fixHistoryIndex = 0;
    protected int $totalFound = 0;

    protected function getFileOperation(): string
    {
        return $this->getImportService()->getFileOperation(fn () => $this->option('copy-files'));
    }

    protected function extractMetadataFromFileTags(array $fileTags): array
    {
        return $this->getImportService()->extractMetadataFromFileTags($fileTags);
    }

    protected function mergeMetadataFillMissing(array $base, array $fill): array
    {
        return $this->getImportService()->mergeMetadataFillMissing($base, $fill);
    }

    protected function detectMultiBookPattern(string $title): ?array
    {
        $patterns = [
            '/^(.+?)\s*\[(\d+)\s*-\s*(\d+)\]$/i',
            '/^(.+?)\s*-\s*\[(\d+)\s*-\s*(\d+)\]$/i',
            '/^(.+?)\s*\((\d+)\s*-\s*(\d+)\)$/i',
            '/^(.+?)\s*-\s*\((\d+)\s*-\s*(\d+)\)$/i',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $title, $matches)) {
                continue;
            }

            $seriesName = trim($matches[1]);
            $start = (int) $matches[2];
            $end = (int) $matches[3];

            if ($end <= $start || ($end - $start) > 200) {
                continue;
            }

            return [
                'series_name' => $seriesName,
                'start_number' => $start,
                'end_number' => $end,
                'numbers' => range($start, $end),
            ];
        }

        return null;
    }

    protected function analyzeMultiBookFiles(array $audiobook, array $multiBookInfo): array
    {
        $files = $audiobook['files'] ?? [];
        $numbers = $multiBookInfo['numbers'] ?? [];
        $seriesName = $multiBookInfo['series_name'] ?? '';
        $splitGroups = [];

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $assigned = false;

            foreach ($numbers as $number) {
                $numberPattern = sprintf('/\b0*%d\b/', $number);

                if (preg_match($numberPattern, $filename)) {
                    $title = $this->extractBookTitleFromFilename($filename, $seriesName, $number);
                    $splitGroups[$number][] = [
                        'file' => $filePath,
                        'title' => $title,
                    ];
                    $assigned = true;
                    break;
                }
            }

            if (!$assigned) {
                $splitGroups['unmatched'][] = [
                    'file' => $filePath,
                    'title' => $this->extractBookTitleFromFilename($filename, $seriesName, 0),
                ];
            }
        }

        return $splitGroups;
    }

    protected function cleanSeriesName(string $seriesName, array $authors): string
    {
        $cleaned = trim($seriesName);

        foreach ($authors as $author) {
            $author = trim((string) $author);
            if ($author === '') {
                continue;
            }

            $patterns = [
                '/^' . preg_quote($author, '/') . '\s*[-:]\s*/i',
                '/\s*[-:]\s*' . preg_quote($author, '/') . '$/i',
                '/^' . preg_quote($author, '/') . '\s+/i',
                '/\s+' . preg_quote($author, '/') . '$/i',
            ];

            foreach ($patterns as $pattern) {
                $cleaned = preg_replace($pattern, '', $cleaned);
            }
        }

        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned, ' -_');

        return $cleaned !== '' ? $cleaned : $seriesName;
    }

    protected function extractBookTitleFromFilename(string $filename, string $seriesName, int $bookNumber): string
    {
        $name = preg_replace('/\.[^.]+$/', '', $filename);
        $patterns = [
            '/^Book\s*\d+\s*[-_:]\s*/i',
            '/^Part\s*\d+\s*[-_:]\s*/i',
            '/^Track\s*\d+\s*[-_:]\s*/i',
            '/^Disc\s*\d+\s*[-_:]\s*/i',
            '/^\d+\s*[-_:]\s*/',
        ];

        foreach ($patterns as $pattern) {
            $name = preg_replace($pattern, '', $name);
        }

        if ($seriesName !== '') {
            $name = preg_replace('/^' . preg_quote($seriesName, '/') . '\s*[-_:]\s*/i', '', $name);
        }

        if ($bookNumber > 0) {
            $numberPattern = sprintf('/\b0*%d\b/', $bookNumber);
            $name = preg_replace($numberPattern, '', $name);
        }

        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name, ' -_');

        if ($name === '') {
            $suffix = $bookNumber > 0 ? " Book {$bookNumber}" : '';
            return trim($seriesName . $suffix);
        }

        return $name;
    }

    public function __construct(?ImportUIInterface $uiService = null)
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
        $tagMetadata = $this->getImportService()->extractTagMetadataFromAudiobook($audiobook, $this->aiProcessor);

        if (!empty($tagMetadata)) {
            $aiMetadata = $this->getImportService()->mergeMetadataFillMissing($aiMetadata ?? [], $tagMetadata);
        }

        $hasCriticalTagMetadata = $this->getImportService()->hasCriticalTagMetadata($tagMetadata);

        return $this->getImportService()->handleLowConfidenceMetadata($audiobook, $aiMetadata, $minConfidence, $hasCriticalTagMetadata);
    }


    protected function buildUiMetadata(array $metadata): array
    {
        return $this->getImportService()->buildUiMetadata(
            $metadata,
            fn ($coverData) => $this->getEmbeddedCoverTempPath($coverData),
            fn ($metadata, $options) => $this->getImportService()->generateDirectoryPath($metadata, array_merge($options, [
                'include_narrator' => (bool) $this->option('include-narrator'),
            ]))
        );
    }

    protected function logUiMessage(mixed $message, mixed $data = null): void
    {
        if (!$this->uiService) {
            $text = is_string($message) ? $message : (is_array($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : (string) $message);

            if ($data !== null) {
                $suffix = is_string($data) ? $data : (is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : (string) $data);
                $text .= ' ' . $suffix;
            }

            parent::line($text);
            return;
        }

        if ($message === 'setCurrentBook' && is_array($data)) {
            $this->uiService->setCurrentBook($data);
            return;
        }

        $text = is_string($message) ? $message : (is_array($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : (string) $message);

        $this->uiService->logMessage($text);
    }

    public function line($string, $style = null, $verbosity = null)
    {
        if ($this->uiService) {
            $this->logUiMessage((string) $string);
            return;
        }

        parent::line($string, $style, $verbosity);
    }

    public function info($string, $verbosity = null)
    {
        if ($this->uiService) {
            $this->logUiMessage((string) $string);
            return;
        }

        parent::info($string, $verbosity);
    }

    public function warn($string, $verbosity = null)
    {
        if ($this->uiService) {
            $this->logUiMessage((string) $string);
            return;
        }

        parent::warn($string, $verbosity);
    }

    public function error($string, $verbosity = null)
    {
        if ($this->uiService) {
            $this->logUiMessage((string) $string);
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
            $allRows = array_merge([$headers], $rows);
            $colWidths = [];

            // Calculate max width for each column
            foreach ($allRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach (array_values($row) as $i => $value) {
                    $len = strlen((string) $value);
                    $colWidths[$i] = max($colWidths[$i] ?? 0, $len);
                }
            }

            // Print each row with padding
            foreach ($allRows as $index => $row) {
                if (is_array($row)) {
                    $paddedCells = [];
                    foreach (array_values($row) as $i => $value) {
                        $paddedCells[] = str_pad((string) $value, $colWidths[$i]);
                    }
                    $this->logUiMessage(implode(' | ', $paddedCells));

                    // Add a separator after header
                    if ($index === 0) {
                        $separatorParts = [];
                        foreach ($colWidths as $width) {
                            $separatorParts[] = str_repeat('-', $width);
                        }
                        $this->logUiMessage(implode('-|-', $separatorParts));
                    }
                } else {
                    $this->logUiMessage((string) $row);
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
        $uiMode = (string) ($this->option('ui') ?? 'hybrid');

        if (!$this->uiService) {
            $this->uiService = match ($uiMode) {
                'prompts' => app(PromptsUIService::class),
                'ncurses' => app(ImportUIService::class),
                'hybrid' => app(\App\Services\HybridUIService::class),
                'plain' => $this->createPlainUiService(),
                default => app(\App\Services\HybridUIService::class),
            };
        }

        if ($uiMode === 'plain') {
            $this->uiService->setPlainMode(true);
        }

        [$width, $height] = $this->getTerminalDimensions();
        $this->uiService->initialize($width, $height);

        // Initialize import service config
        $this->getImportService()->setConfig([
            'include_narrator' => (bool) $this->option('include-narrator'),
            'collection' => $this->option('collection'),
            'genre' => $this->option('genre'),
            'directory_pattern' => $this->option('pattern'),
        ]);

        try {
            $this->uiService->drawInitialLayout();

            // Set up signal handlers for graceful interruption
            $this->setupSignalHandlers();

            // Initialize persistent cache system
            $this->initializeCache();

            // Create a database backup unless --no-backup is specified
            if (!$this->option('no-backup')) {
                $this->uiService->logMessage('Creating a database backup before importing books...');

                // Capture backup command output and redirect to TUI log
                try {
                    $this->callSilent('backup:database', ['--suffix' => 'import-books']);
                    $this->uiService->logMessage('✓ Database backup created successfully.');
                } catch (\Exception $e) {
                    $this->uiService->logMessage('⚠️  Database backup failed: ' . $e->getMessage());
                }
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
                $specificPaths = (array) $specificPaths;
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
            if ($limit && $limit > 0 && $totalFound > (int) $limit) {
                $audiobooks = array_slice($audiobooks, 0, (int) $limit);
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
                $this->processBackgroundTasks();
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
            $this->saveCacheBeforeExit();
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
        $customDirs = $this->option('directory');
        $includeOld = $this->option('include-old');

        return $this->getImportService()->getDirectoriesToScan(
            $customDirs,
            fn ($message) => $this->warn($message),
            $includeOld
        );
    }

    /**
     * Process specific files or folders provided as arguments
     */
    protected function processSpecificPaths(array $paths): array
    {
        return $this->getImportService()->processSpecificPaths(
            $paths,
            fn ($path) => $this->processSingleAudioFile($path),
            fn ($path) => $this->processAudiobookDirectory($path),
            fn ($msg) => $this->warn($msg),
            (bool) $this->option('force-include')
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
        return $this->getImportService()->scanForAudiobooks(
            $directories,
            fn ($path) => $this->isAlreadyImported($path),
            fn ($message) => $this->info($message)
        );
    }

    /**
     * Start background processing tasks while waiting for user input (enhanced)
     */
    protected function startBackgroundProcessing(array $audiobooks, int $currentIndex = 0): void
    {
        if (!$this->backgroundProcessingEnabled) {
            return;
        }

        $this->getImportService()->startBackgroundProcessing(
            $audiobooks,
            $currentIndex,
            fn ($type, $data, $priority) => $this->queueBackgroundTask($type, $data, $priority),
            fn () => $this->processBackgroundTasks(),
            fn () => $this->showEnhancedBackgroundStatus()
        );
    }

    /**
     * Execute a specific background task (with caching)
     */
    protected function executeBackgroundTask(array $task): array
    {
        return $this->getImportService()->executeBackgroundTask(
            $task,
            fn ($audiobook, $taskType) => $this->getCachedResult($audiobook, $taskType),
            fn ($taskType, $audiobook) => $this->executeBackgroundTaskInternal($taskType, $audiobook),
            fn ($audiobook, $taskType, $result) => $this->setCachedResult($audiobook, $taskType, $result)
        );
    }

    /**
     * Internal task execution without caching
     */
    protected function executeBackgroundTaskInternal(string $taskType, array $audiobook): array
    {
        return $this->getImportService()->executeBackgroundTaskInternal(
            $taskType,
            $audiobook,
            fn ($data) => $this->preprocessMetadataInBackground($data),
            fn ($data) => $this->scanDirectoryInBackground($data),
            fn ($data) => $this->checkDuplicatesInBackground($data),
            fn ($data) => $this->extractMetadataInBackground($data),
            fn ($data) => $this->analyzeAudioFilesInBackground($data),
            fn ($data) => $this->prepareCoverImageInBackground($data)
        );
    }

    /**
     * Process pending background tasks (enhanced with concurrent task management)
     */
    protected function processBackgroundTasks(): void
    {
        $this->getImportService()->processBackgroundTasks(
            $this->backgroundTasks,
            fn () => $this->maintainConcurrentTasks(),
            fn ($task) => $this->executeBackgroundTask($task),
            $this->runningTaskCount
        );
    }

    /**
     * Maintain at least 3 concurrent background tasks
     */
    protected function maintainConcurrentTasks(): int
    {
        $this->runningTaskCount = $this->getImportService()->maintainConcurrentTasks(
            $this->backgroundTasks,
            $this->taskQueue,
            $this->maxConcurrentTasks,
            fn ($task) => $this->startBackgroundTask($task)
        );

        return $this->runningTaskCount;
    }

    /**
     * Start a background task immediately
     */
    protected function startBackgroundTask(array $taskInfo): void
    {
        $this->getImportService()->startBackgroundTask($taskInfo, $this->backgroundTasks);
        $this->runningTaskCount++;
    }

    /**
     * Queue a background task with priority
     */
    protected function queueBackgroundTask(string $type, array $data, string $priority = 'normal'): void
    {
        $this->getImportService()->queueBackgroundTask($type, $data, $this->taskQueue, $priority);
    }

    /**
     * Get result from background task if available
     */
    protected function getBackgroundResult(string $taskId): ?array
    {
        return $this->getImportService()->getBackgroundResult($this->backgroundTasks, $taskId);
    }

    /**
     * Preprocess metadata in background (enhanced)
     */
    protected function preprocessMetadataInBackground(array $audiobook): array
    {
        return $this->getImportService()->preprocessMetadataInBackground(
            $audiobook,
            fn ($data) => $this->getImportService()->extractBasicMetadata($data),
            fn ($path) => $this->getImportService()->hasCdDirectories($path),
            fn ($files) => $this->getImportService()->analyzeFileTypes($files),
            fn ($name) => $this->getImportService()->analyzeDirectoryName($name),
            fn ($path) => $this->getImportService()->isMultiBookDirectory($path),
            fn ($path) => $this->findCoverImageCandidate($path)[0]
        );
    }

    /**
     * Scan directory structure in background
     */
    protected function scanDirectoryInBackground(array $data): array
    {
        return $this->getImportService()->scanDirectoryInBackground($data);
    }

    /**
     * Check for duplicates in background
     */
    protected function checkDuplicatesInBackground(array $audiobook): array
    {
        return $this->getImportService()->checkDuplicatesInBackground(
            $audiobook,
            fn ($data) => $this->getImportService()->findSimilarBooks($data)
        );
    }

    /**
     * Extract detailed metadata in background
     */
    protected function extractMetadataInBackground(array $audiobook): array
    {
        return $this->getImportService()->extractMetadataInBackground(
            $audiobook,
            fn ($data) => $this->getImportService()->extractTagMetadataFromAudiobook($data, $this->aiProcessor),
            fn ($path) => $this->extractNfoData($path)
        );
    }

    /**
     * Analyze audio files in background
     */
    protected function analyzeAudioFilesInBackground(array $audiobook): array
    {
        return $this->getImportService()->analyzeAudioFilesInBackground($audiobook);
    }

    /**
     * Prepare cover image in background
     */
    protected function prepareCoverImageInBackground(array $audiobook): array
    {
        return $this->getImportService()->prepareCoverImageInBackground(
            $audiobook,
            fn ($path) => $this->findCoverImageCandidate($path)[0]
        );
    }

    protected function getCachedResult(array $audiobook, string $taskType): ?array
    {
        return $this->getImportService()->getCachedResult($this->backgroundCache, $audiobook, $taskType, $this->cacheEnabled);
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
     * Initialize persistent cache system
     */
    protected function initializeCache(): void
    {
        $cacheFilePath = storage_path('app/audiobook-cache/background-processing-cache.json');
        $this->backgroundCache = $this->getImportService()->initializeCache(
            $this->option('no-cache'),
            $this->option('clear-cache'),
            fn ($message) => $this->info($message),
            fn () => $this->getImportService()->loadCache(
                $this->cacheEnabled,
                $cacheFilePath,
                $this->cacheVersion,
                fn ($message) => $this->info($message),
                fn ($message) => $this->warn($message)
            ),
            fn (&$cache) => $this->getImportService()->cleanupCache(
                $cache,
                fn ($path) => $this->getDirectoryModificationTime($path),
                fn ($message) => $this->info($message)
            )
        );
    }

    /**
     * Store result in cache
     */
    protected function setCachedResult(array $audiobook, string $taskType, array $result): void
    {
        $this->getImportService()->setCachedResult(
            $this->backgroundCache,
            $audiobook,
            $taskType,
            $result,
            $this->cacheEnabled,
            fn ($data) => $this->getImportService()->getCacheKey($data),
            fn ($path) => $this->getDirectoryModificationTime($path)
        );
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
        $this->getImportService()->displayCacheStatistics(
            $this->backgroundCache,
            $this->backgroundTasks,
            fn ($message) => $this->info($message),
            fn ($message) => $this->line($message),
            fn ($bytes) => $this->formatBytes($bytes)
        );
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
            $cacheFilePath = storage_path('app/audiobook-cache/background-processing-cache.json');
            $this->getImportService()->saveCache($this->backgroundCache, true, $cacheFilePath, $this->cacheVersion);
        }
    }

    /**
     * Enhanced ask method with background processing and quit handling
     */
    protected function askWithBackground(string $question, string $default = null, array $backgroundData = []): string
    {
        return $this->getImportService()->askWithBackground(
            $question,
            $default,
            $backgroundData,
            fn ($type, $data, $priority) => $this->queueBackgroundTask($type, $data, $priority),
            fn () => $this->startContinuousBackgroundProcessing(),
            fn ($question, $default) => $this->askWithImmediateInterrupt($question, $default),
            fn () => $this->handleUserQuit()
        );
    }

    /**
     * Start continuous background processing to maintain at least 3 running tasks
     */
    protected function startContinuousBackgroundProcessing(): void
    {
        $this->getImportService()->startContinuousBackgroundProcessing(
            fn () => $this->processBackgroundTasks()
        );
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
        $this->getImportService()->displayPartialSummary(
            $this->totalFound,
            $this->processedBooks,
            $this->failedBooks,
            $this->skippedBooks,
            fn () => $this->newLine(),
            fn ($message) => $this->info($message),
            fn ($message) => $this->warn($message),
            fn ($message) => $this->line($message)
        );
    }

    /**
     * Set up signal handlers for graceful interruption
     */
    protected function setupSignalHandlers(): void
    {
        if (extension_loaded('pcntl') && !app()->runningUnitTests()) {
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
        $this->inputInterrupted = true;

        // Set interrupted flag on UI service to break out of input loops
        if ($this->uiService) {
            $this->uiService->requestInterrupt();
        }

        // Exit after setting flags - this should work with the interrupt checks in loops
        exit(130);
    }

    protected function getEmbeddedCoverTempPath(string $coverData): ?string
    {
        // Always create a new temp file for each call to avoid caching issues
        // The BookImportService already handles unique naming with content hash
        return $this->getImportService()->getEmbeddedCoverTempPath($coverData);
    }

    /**
     * Ask for input with immediate interruption capability
     */
    protected function askWithImmediateInterrupt(string $question, ?string $default = null): string
    {
        $response = $this->uiService->ask($question, $default ?? '');

        /** @var string $response */
        return $response;
    }

    protected function selectWithImmediateInterrupt(string $question, array $options, string $default = ''): string
    {
        $response = $this->uiService->select($question, $options, $default);

        /** @var string $response */
        if (strtolower(trim($response)) === 'q') {
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

    /**
     * Group CD directories under their parent directory to treat multi-disc books as single audiobooks
     */
    protected function groupCdDirectories(array $potentialBooks): array
    {
        return $this->getImportService()->groupCdDirectories(
            $potentialBooks,
            fn ($message) => $this->line($message)
        );
    }

    /**
     * Check if audiobook is already imported
     */
    protected function isAlreadyImported(string $path, array $metadata = []): bool
    {
        return $this->getImportService()->isAlreadyImported($path, $metadata);
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
            fn ($metadata) => $this->getImportService()->buildUiMetadata($metadata),
            fn ($message, $data = null) => $this->logUiMessage($message, $data),
            fn ($message) => $this->info($message),
            fn ($message) => $this->line($message),
            fn () => $this->newLine(),
            fn ($message) => $this->warn($message),
            fn (&$metadata) => $this->displayEnrichedMetadata($metadata),
            fn (&$metadata, $audiobook) => $this->reviewAndApprove($metadata, $audiobook),
            fn ($metadata) => $this->getImportService()->hasEnrichmentData($metadata),
            fn () => $this->getImportService()->getFileOperation(fn () => $this->option('copy-files')),
            fn ($metadata) => $this->getEnrichmentService()->enrichWithExternalData($metadata),
            fn () => $this->getEnrichmentService(),
            fn ($path, $metadata) => $this->getImportService()->findExistingBook($path, $metadata),
            fn ($dir1, $dir2) => $this->getImportService()->compareDirectories($dir1, $dir2),
            fn ($comparison) => $this->displayDirectoryComparison($comparison),
            fn ($audiobook, $existingBook) => $this->promptForDuplicateAction($audiobook, $existingBook),
            fn ($audiobook, $force) => $this->cleanupSourceDirectory($audiobook, $force),
            fn ($bytes) => $this->getImportService()->formatBytes($bytes),
            fn (&$metadata) => $this->getImportService()->extractSeriesNumberFromTitle($metadata),
            fn ($title) => $this->getImportService()->detectMultiBookPattern($title),
            fn ($audiobook, $multiBookInfo) => $this->getImportService()->analyzeMultiBookFiles($audiobook, $multiBookInfo),
            fn ($audiobook, $multiBookInfo, $splitGroups, $aiMetadata) => $this->getImportService()->processMultiBookSplit(
                $audiobook,
                $multiBookInfo,
                $splitGroups,
                $aiMetadata,
                fn ($message) => $this->info($message),
                fn ($virtualAudiobook, $bookMetadata) => $this->processSingleBook($virtualAudiobook, $bookMetadata)
            ),
            fn ($audiobook, $aiMetadata) => $this->handleLowConfidenceMetadata($audiobook, $aiMetadata),
            fn ($audiobook) => $this->getImportService()->processWithAI($audiobook, $this->aiProcessor),
            $skippedBooks,
            $processedBooks,
            (bool) $this->option('auto'),
            (bool) $this->option('dry-run'),
            (bool) $this->option('skip-enrichment'),
            $this->uiService ?? null,
            function (string $type, array $audiobook, array $aiMetadata, ?int $bookId = null, ?string $bookTitle = null): void {
                if ($type === 'imported') {
                    $this->bookHistory[] = [
                        'type' => 'imported',
                        'title' => $bookTitle ?? '',
                        'book_id' => $bookId,
                        'path' => $audiobook['path'] ?? '',
                    ];
                } else {
                    $this->bookHistory[] = [
                        'type' => 'skipped',
                        'title' => $aiMetadata['title'] ?? ($audiobook['name'] ?? ''),
                        'path' => $audiobook['path'] ?? '',
                        'audiobook' => $audiobook,
                        'aiMetadata' => $aiMetadata,
                    ];
                }
            }
        );
    }

    /**
     * Display enriched metadata (AI + external data) for review
     */
    protected function displayEnrichedMetadata(array &$metadata): void
    {
        if ($this->uiService) {
            $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
            // Offer cover selection when there are multiple sources to choose from
            $coverSources = $metadata['cover_sources'] ?? [];
            if (count($coverSources) > 1) {
                $this->handleCoverSelectionForUiService($metadata);
                $this->uiService->setCurrentBook($this->buildUiMetadata($metadata));
            }
            return;
        }

        $this->getImportService()->displayEnrichedMetadata(
            $metadata,
            fn ($headers, $data) => $this->table($headers, $data),
            fn ($metadata) => $this->getImportService()->handleCoverSelection(
                $metadata,
                fn ($path) => $this->isTextOnWhiteCover($path),
                fn ($metadata, $limit) => $this->searchAlternativeCovers($metadata, $limit),
                fn ($message) => $this->warn($message),
                fn ($message) => $this->line($message),
                fn ($message) => $this->info($message),
                fn ($message) => $this->comment($message),
                fn ($coverOptions, $metadata) => $this->displayCoverOptions($coverOptions, $metadata),
                fn ($coverOptions) => $this->promptForCoverSelection($coverOptions),
                !$this->option('auto'),
                fn ($coverData) => $this->getEmbeddedCoverTempPath($coverData)
            ),
            fn ($coverUrl) => $this->displayCoverImage($coverUrl)
        );
    }

    /**
     * Run cover source selection using the UIService inline preview.
     */
    protected function handleCoverSelectionForUiService(array &$metadata): void
    {
        $this->getImportService()->handleCoverSelection(
            $metadata,
            fn ($path) => $this->isTextOnWhiteCover($path),
            fn ($metadata, $limit) => $this->searchAlternativeCovers($metadata, $limit),
            fn ($message) => $this->warn($message),
            fn ($message) => $this->line($message),
            fn ($message) => $this->info($message),
            fn ($message) => $this->comment($message),
            fn ($coverOptions, $metadata) => null,
            fn ($coverOptions) => $this->promptForCoverSelectionWithPreview($coverOptions),
            true,
            fn ($coverData) => $this->getEmbeddedCoverTempPath($coverData)
        );
    }

    /**
     * Prompt the user to select a cover source, showing an inline preview of each option
     * as they navigate using the UIService's selectCoverWithPreview.
     */
    protected function promptForCoverSelectionWithPreview(array $coverOptions): ?string
    {
        if (empty($coverOptions)) {
            return null;
        }

        if ($this->uiService instanceof \App\Services\ImportUIService) {
            $choices = [];
            $previewPaths = [];
            foreach ($coverOptions as $index => $option) {
                $key = (string) ($index + 1);
                $choices[$key] = (string) ($option['label'] ?? '');
                $previewPaths[$key] = ($option['url'] ?? '') !== '' ? (string) $option['url'] : null;
            }
            $choices['0'] = 'None - skip cover';

            $result = $this->uiService->selectCoverWithPreview(
                'Select cover source',
                $choices,
                '1',
                $previewPaths
            );

            if ($result === '0') {
                return '';
            }

            $index = (int) $result - 1;
            return $coverOptions[$index]['url'] ?? null;
        }

        return $this->promptForCoverSelection($coverOptions);
    }

    /**
     * Ask for input with prompt on the same line
     */
    protected function askInline(string $question, ?string $default = ''): string
    {
        return $this->askWithImmediateInterrupt($question, $default ?? '');
    }

    protected function getFirstNonEmptyMetadataValue(array $metadata, array $keys): mixed
    {
        return $this->getImportService()->getFirstNonEmptyMetadataValue($metadata, $keys);
    }

    /**
     * Prompt for cover URL
     * CLI-specific implementation
     */
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
            fn ($message, $data = null) => $this->logUiMessage($message, $data),
            fn ($question, $options, $default) => $this->selectWithImmediateInterrupt($question, $options, $default),
            fn ($question, $default) => $this->askInline($question, $default ?? ''),
            fn ($currentCoverUrl, $currentGenre, $currentDirectoryPath, $isFinalConfirmation, $fileCount = 0, $previousImport = null) => $this->buildReviewOptions($currentCoverUrl, $currentGenre, $currentDirectoryPath, $isFinalConfirmation, !empty($audiobook['is_multi_book_part']), $fileCount, $previousImport),
            fn ($metadata, $sequential = false) => $this->getImportService()->editMetadataFields(
                $metadata,
                $audiobook,
                fn ($question, $default) => $this->askInline($question, $default ?? ''),
                fn ($question, $options, $default) => $this->selectWithImmediateInterrupt($question, $options, $default),
                fn ($metadata, $keys) => $this->getImportService()->getFirstNonEmptyMetadataValue($metadata, $keys),
                fn (&$metadata) => $this->getImportService()->extractSeriesNumberFromTitle($metadata),
                fn () => $this->getValidGenres(),
                fn ($message, $data = null) => $this->logUiMessage($message, $data),
                fn ($metadata) => $this->buildUiMetadata($metadata),
                $sequential,
                fn ($metadata, $ab, $enrichmentService) => $this->getImportService()->manualEnrichmentWithComparison(
                    $metadata,
                    $ab,
                    $enrichmentService,
                    fn ($headers, $rows) => $this->table($headers, $rows)
                ),
                fn () => $this->getEnrichmentService(),
                fn ($metadata, $options) => $this->getImportService()->generateDirectoryPath($metadata, array_merge($options, [
                    'include_narrator' => (bool) $this->option('include-narrator'),
                ]))
            ),
            fn ($metadata, $audiobook, $enrichmentService) => $this->getImportService()->manualEnrichmentWithComparison(
                $metadata,
                $audiobook,
                $enrichmentService,
                fn ($headers, $rows) => $this->table($headers, $rows)
            ),
            fn () => $this->getEnrichmentService(),
            fn () => $this->getValidGenres(),
            fn ($metadata) => $this->getImportService()->hasEnrichmentData($metadata),
            fn ($metadata, $options) => $this->getImportService()->generateDirectoryPath($metadata, array_merge($options, [
                'include_narrator' => (bool) $this->option('include-narrator'),
            ])),
            $this->inputInterrupted,
            fn ($previousImport) => $this->fixPreviousImport($previousImport),
            $this->getLastHistoryEntry()
        );
    }

    protected function buildReviewOptions(
        string $currentCoverUrl,
        string $currentGenre,
        string $currentDirectoryPath,
        bool $isFinalConfirmation,
        bool $isMultiBookPart = false,
        int $fileCount = 0,
        ?array $previousImport = null
    ): array {
        return $this->getImportService()->buildReviewOptions(
            $currentCoverUrl,
            $currentGenre,
            $currentDirectoryPath,
            $isFinalConfirmation,
            fn () => $this->getValidGenres(),
            $isMultiBookPart,
            $fileCount,
            $previousImport
        );
    }

    /**
     * Return the history entry to fix based on current navigation index.
     * Index 0 = most recent, 1 = one before that, etc.
     * Includes both imported and skipped books.
     */
    protected function getLastHistoryEntry(): ?array
    {
        $count = count($this->bookHistory);
        if ($count === 0) {
            return null;
        }
        $this->fixHistoryIndex = min($this->fixHistoryIndex, $count - 1);
        $targetIndex = $count - 1 - $this->fixHistoryIndex;
        return $this->bookHistory[$targetIndex] ?? null;
    }

    /**
     * Re-open a previously processed book (imported or skipped) for editing or retry.
     * For imported books: opens the edit/review loop to update saved metadata.
     * For skipped books: re-runs the full import flow from the review step.
     */
    protected function fixPreviousImport(array $previousImport): void
    {
        $this->fixHistoryIndex++;

        if (($previousImport['type'] ?? 'imported') === 'skipped') {
            $this->retrySkippedBook($previousImport);
            $this->fixHistoryIndex = 0;
            return;
        }

        $bookId = $previousImport['book_id'] ?? null;
        if (!$bookId) {
            $this->warn("Cannot fix previous import: no book ID available.");
            $this->fixHistoryIndex = 0;
            return;
        }

        $book = \App\Models\Book::find($bookId);
        if (!$book) {
            $this->warn("Cannot fix previous import: book ID {$bookId} not found.");
            $this->fixHistoryIndex = 0;
            return;
        }

        $this->info("📝 Editing previously imported book: {$book->title} (ID: {$bookId})");

        $metadata = $this->getImportService()->buildMetadataFromBook($book);

        $this->getImportService()->displayEnrichedMetadata(
            $metadata,
            fn ($headers, $rows) => $this->table($headers, $rows),
            null,
            null,
            fn () => false
        );

        $localInterrupted = false;
        $fakeAudiobook = ['path' => $book->directory_path ?? '', 'files' => [], 'is_multi_book_part' => false];
        $approved = $this->getImportService()->reviewAndApprove(
            $metadata,
            $fakeAudiobook,
            fn ($metadata) => $this->buildUiMetadata($metadata),
            fn ($message, $data = null) => $this->logUiMessage($message, $data),
            fn ($question, $options, $default) => $this->selectWithImmediateInterrupt($question, $options, $default),
            fn ($question, $default) => $this->askInline($question, $default ?? ''),
            fn ($currentCoverUrl, $currentGenre, $currentDirectoryPath, $isFinalConfirmation, $fileCount = 0, $prev = null) => $this->buildReviewOptions($currentCoverUrl, $currentGenre, $currentDirectoryPath, $isFinalConfirmation, false, $fileCount, $prev),
            fn ($metadata, $sequential = false) => $this->getImportService()->editMetadataFields(
                $metadata,
                $fakeAudiobook,
                fn ($question, $default) => $this->askInline($question, $default ?? ''),
                fn ($question, $options, $default) => $this->selectWithImmediateInterrupt($question, $options, $default),
                fn ($metadata, $keys) => $this->getImportService()->getFirstNonEmptyMetadataValue($metadata, $keys),
                fn (&$metadata) => $this->getImportService()->extractSeriesNumberFromTitle($metadata),
                fn () => $this->getValidGenres(),
                fn ($message, $data = null) => $this->logUiMessage($message, $data),
                fn ($metadata) => $this->buildUiMetadata($metadata),
                $sequential,
                fn ($metadata, $ab, $enrichmentService) => $this->getImportService()->manualEnrichmentWithComparison(
                    $metadata,
                    $ab,
                    $enrichmentService,
                    fn ($headers, $rows) => $this->table($headers, $rows)
                ),
                fn () => $this->getEnrichmentService(),
                fn ($metadata, $options) => $this->getImportService()->generateDirectoryPath($metadata, array_merge($options, [
                    'include_narrator' => (bool) $this->option('include-narrator'),
                ]))
            ),
            fn ($metadata, $audiobook, $enrichmentService) => $this->getImportService()->manualEnrichmentWithComparison(
                $metadata,
                $audiobook,
                $enrichmentService,
                fn ($headers, $rows) => $this->table($headers, $rows)
            ),
            fn () => $this->getEnrichmentService(),
            fn () => $this->getValidGenres(),
            fn ($metadata) => $this->getImportService()->hasEnrichmentData($metadata),
            fn ($metadata, $options) => $this->getImportService()->generateDirectoryPath($metadata, array_merge($options, [
                'include_narrator' => (bool) $this->option('include-narrator'),
            ])),
            $localInterrupted,
            fn ($prev) => $this->fixPreviousImport($prev),
            $this->getLastHistoryEntry()
        );

        if ($approved) {
            $this->getImportService()->updateBookFromMetadata($book, $metadata, $fakeAudiobook);
            $freshTitle = $book->fresh()->title ?? $book->title;
            $this->info("✅ Updated: {$freshTitle}");

            // Sync updated title back into the history log entry
            $count = count($this->bookHistory);
            $targetIndex = $count - $this->fixHistoryIndex;
            if (isset($this->bookHistory[$targetIndex]) && ($this->bookHistory[$targetIndex]['book_id'] ?? null) === $bookId) {
                $this->bookHistory[$targetIndex]['title'] = $freshTitle;
            }
        } else {
            $this->info("↩ No changes saved for: {$book->title}");
        }

        $this->fixHistoryIndex = 0;
    }

    /**
     * Re-run the import review+create flow for a previously skipped book using its saved metadata.
     */
    protected function retrySkippedBook(array $historyEntry): void
    {
        $title = $historyEntry['title'] ?? 'Unknown';
        $audiobook = $historyEntry['audiobook'] ?? [];
        $aiMetadata = $historyEntry['aiMetadata'] ?? [];

        $this->info("🔄 Retrying skipped book: {$title}");

        $this->processSingleBook($audiobook, $aiMetadata);
    }

    /**
     * Edit metadata fields interactively
     */
    protected function editMetadataFields(array $metadata, array $audiobook, bool $sequential = false): array
    {
        return $this->getImportService()->editMetadataFields(
            $metadata,
            $audiobook,
            fn ($question, $default) => $this->askInline($question, $default ?? ''),
            fn ($question, $options, $default) => $this->selectWithImmediateInterrupt($question, $options, $default),
            fn ($metadata, $keys) => $this->getFirstNonEmptyMetadataValue($metadata, $keys),
            fn (&$metadata) => $this->extractSeriesNumberFromTitle($metadata),
            fn () => $this->getValidGenres(),
            fn ($message, $data = null) => $this->logUiMessage($message, $data),
            fn ($metadata) => $this->buildUiMetadata($metadata),
            $sequential,
            fn ($metadata, $ab, $enrichmentService) => $this->getImportService()->manualEnrichmentWithComparison(
                $metadata,
                $ab,
                $enrichmentService,
                fn ($headers, $rows) => $this->table($headers, $rows)
            ),
            fn () => $this->getEnrichmentService(),
            fn ($metadata, $options) => $this->getImportService()->generateDirectoryPath($metadata, array_merge($options, [
                'include_narrator' => (bool) $this->option('include-narrator'),
            ]))
        );
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
        $this->getImportService()->processMultiBookSplit(
            $audiobook,
            $multiBookInfo,
            $splitGroups,
            $aiMetadata,
            fn ($message) => $this->info($message),
            fn ($virtualAudiobook, $bookMetadata) => $this->processSingleBook($virtualAudiobook, $bookMetadata)
        );
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
            fn ($audiobook, $book, $options) => $this->getImportService()->moveFilesToLibrary(
                $audiobook,
                $book,
                $options,
                null,
                null,
                fn ($audiobook, $targetDir, $book) => $this->handleDirectoryConflict($audiobook, $targetDir),
                fn ($sourcePath, $targetPath, $type) => $this->handleFileConflict($sourcePath, $targetPath, $type)
            ),
            fn () => $this->getFileOperation(),
            fn ($message) => $this->info($message),
            fn (&$metadata) => $this->displayEnrichedMetadata($metadata),
            function (&$metadata, $audiobook = []) {
                return $this->reviewAndApprove($metadata, $audiobook);
            },
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
     * Search for alternative book covers using Google Image Search
     */
    protected function searchAlternativeCovers(array $metadata, int $limit = 3): array
    {
        return $this->getImportService()->searchAlternativeCovers($metadata, $this->googleImageService, $limit);
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
     * CLI-specific implementation
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

        foreach ($coverOptions as $index => $option) {
            if ($option['label'] === $selection || (string) ($index + 1) === $selection) {
                return $option['url'];
            }
        }

        return null;
    }

    /**
     * Format file types for display
     */
    protected function formatFileTypes(array $fileTypes): string
    {
        return $this->getImportService()->formatFileTypes($fileTypes);
    }

    protected function isTextOnWhiteCover(string $imagePath): bool
    {
        return $this->getImportService()->isTextOnWhiteCover($imagePath, $this->coverAnalysisService);
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
            fn () => $this->aiProcessor ? $this->aiProcessor->getTotalCost() : 0.0,
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
     * Handle a file-level conflict where an individual file already exists at the destination.
     * Returns 'keep', 'replace', or 'rename:<newname>'.
     */
    protected function handleFileConflict(string $sourcePath, string $targetPath, string $type): string
    {
        return $this->getImportService()->handleFileConflict(
            $sourcePath,
            $targetPath,
            $type,
            fn ($message) => $this->line($message),
            fn ($question, $options, $default) => $this->uiService->select($question, $options, $default),
            fn ($question, $default) => $this->uiService->ask($question, $default),
            fn ($imagePath) => $this->displayLocalImage($imagePath)
        );
    }

    /**
     * Display a local image file using the terminal image protocol.
     */
    protected function displayLocalImage(string $imagePath): void
    {
        if (!file_exists($imagePath)) {
            $this->line("    (file not found: {$imagePath})");
            return;
        }
        $imageData = file_get_contents($imagePath);
        if ($imageData) {
            $this->displayKittyImage($imageData);
        } else {
            $this->line("    (could not read image: {$imagePath})");
        }
    }

    /**
     * Handle directory conflicts when target already exists
     */
    protected function handleDirectoryConflict(array $audiobook, string $targetDir): string
    {
        return $this->getImportService()->handleDirectoryConflict(
            $audiobook,
            $targetDir,
            fn ($sourcePath, $targetPath) => $this->compareDirectories($sourcePath, $targetPath),
            fn ($comparison) => $this->displayDirectoryComparison($comparison),
            fn ($message) => $this->uiService->logMessage($message),
            fn ($question, $options, $default) => $this->uiService->select($question, $options, $default),
            fn ($option) => $this->option($option)
        );
    }

    /**
     * Rename both directories by narrator format: "title (narrator)"
     */
    protected function renameBothDirectoriesByNarrator(array $audiobook, string $targetDir, Book $book): void
    {
        $bookStoragePath = config('filesystems.disks.books.root') ?? config('app.book_root');
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
        // Check if it's a file by looking at the path structure (has audio extension)
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $extension = strtolower(pathinfo($audiobook['path'] ?? '', PATHINFO_EXTENSION));
        $isFile = in_array($extension, $audioExtensions);
        $sourceType = $isFile ? 'file' : 'directory';

        $options = [
            '1' => 'Skip import (keep both)',
            '2' => "Delete source {$sourceType}",
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
     * Clean up source directory after successful operations
     */
    protected function cleanupSourceDirectory(array $audiobook, bool $filesAlreadyExist = false): void
    {
        $isCopyOperation = (bool) $this->option('copy-files');
        $this->getImportService()->cleanupSourceDirectory(
            $audiobook,
            $filesAlreadyExist,
            $isCopyOperation,
            fn ($message) => $this->info($message),
            fn ($path) => File::isDirectory($path),
            fn ($path) => File::files($path)
        );
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

    /**
     * Create a plain mode UI service instance
     */
    protected function createPlainUiService(): ImportUIInterface
    {
        $service = app(PromptsUIService::class);
        $service->setPlainMode(true);
        return $service;
    }
}
