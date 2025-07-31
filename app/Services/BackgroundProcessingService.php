<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class BackgroundProcessingService
{
    protected array $backgroundTasks = [];
    protected array $taskQueue = [];
    protected array $completedTasks = [];
    protected int $maxConcurrentTasks = 3;

    public function __construct()
    {
        $this->maxConcurrentTasks = min(3, max(1, (int)(shell_exec('nproc') ?? 2)));
    }

    /**
     * Schedule a background task
     */
    public function scheduleBackgroundTask(string $type, array $data): void
    {
        $taskId = $this->generateTaskId($type, $data);
        
        $this->taskQueue[] = [
            'id' => $taskId,
            'type' => $type,
            'data' => $data,
            'priority' => $data['priority'] ?? 'normal',
            'scheduled_at' => microtime(true)
        ];
        
        $this->maintainConcurrentTasks();
    }

    /**
     * Queue a background task with priority
     */
    public function queueBackgroundTask(string $type, array $data, string $priority = 'normal'): void
    {
        $data['priority'] = $priority;
        $this->scheduleBackgroundTask($type, $data);
    }

    /**
     * Process background tasks
     */
    public function processBackgroundTasks(): void
    {
        $this->maintainConcurrentTasks();
        $this->startQueuedTasks();
    }

    /**
     * Execute a background task
     */
    public function executeBackgroundTask(array $task): array
    {
        $startTime = microtime(true);
        
        try {
            $result = $this->executeBackgroundTaskInternal($task['type'], $task['data']);
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            return [
                'success' => true,
                'result' => $result,
                'duration' => $duration,
                'task_id' => $task['id'] ?? null
            ];
            
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            Log::error("Background task failed: " . $e->getMessage(), [
                'task_type' => $task['type'],
                'task_data' => $task['data'],
                'duration' => $duration,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration' => $duration,
                'task_id' => $task['id'] ?? null
            ];
        }
    }

    /**
     * Execute background task internal logic
     */
    protected function executeBackgroundTaskInternal(string $taskType, array $audiobook): array
    {
        return match ($taskType) {
            'preprocess_metadata' => $this->preprocessMetadataInBackground($audiobook),
            'scan_directory' => $this->scanDirectoryInBackground($audiobook),
            'check_duplicates' => $this->checkDuplicatesInBackground($audiobook),
            'extract_metadata' => $this->extractMetadataInBackground($audiobook),
            'analyze_audio_files' => $this->analyzeAudioFilesInBackground($audiobook),
            'prepare_cover_image' => $this->prepareCoverImageInBackground($audiobook),
            default => throw new \InvalidArgumentException("Unknown task type: {$taskType}")
        };
    }

    /**
     * Maintain the number of concurrent tasks
     */
    protected function maintainConcurrentTasks(): void
    {
        $activeTaskIds = array_keys($this->backgroundTasks);
        
        foreach ($activeTaskIds as $taskId) {
            $taskInfo = $this->backgroundTasks[$taskId];
            $process = $taskInfo['process'];
            
            if (!$process->running()) {
                $output = $process->output();
                $errorOutput = $process->errorOutput();
                $exitCode = $process->exitCode();
                
                $result = [
                    'success' => $exitCode === 0,
                    'output' => $output,
                    'error' => $errorOutput,
                    'exit_code' => $exitCode,
                    'completed_at' => microtime(true)
                ];
                
                $this->completedTasks[$taskId] = array_merge($taskInfo, $result);
                unset($this->backgroundTasks[$taskId]);
            }
        }
    }

    /**
     * Start queued tasks if there's capacity
     */
    protected function startQueuedTasks(): void
    {
        while (count($this->backgroundTasks) < $this->maxConcurrentTasks && !empty($this->taskQueue)) {
            usort($this->taskQueue, function ($a, $b) {
                $priorityOrder = ['high' => 1, 'normal' => 2, 'low' => 3];
                $aPriority = $priorityOrder[$a['priority']] ?? 2;
                $bPriority = $priorityOrder[$b['priority']] ?? 2;
                
                if ($aPriority === $bPriority) {
                    return $a['scheduled_at'] <=> $b['scheduled_at'];
                }
                
                return $aPriority <=> $bPriority;
            });
            
            $task = array_shift($this->taskQueue);
            $this->startBackgroundTask($task);
        }
    }

    /**
     * Start a background task
     */
    protected function startBackgroundTask(array $taskInfo): void
    {
        $command = $this->buildTaskCommand($taskInfo);
        
        $process = Process::start($command);
        
        $this->backgroundTasks[$taskInfo['id']] = array_merge($taskInfo, [
            'process' => $process,
            'started_at' => microtime(true)
        ]);
    }

    /**
     * Build command for task execution
     */
    protected function buildTaskCommand(array $taskInfo): string
    {
        $taskData = base64_encode(json_encode($taskInfo));
        return "php artisan import:background-task {$taskData}";
    }

    /**
     * Get background task result
     */
    public function getBackgroundResult(string $taskId): ?array
    {
        if (isset($this->completedTasks[$taskId])) {
            return $this->completedTasks[$taskId];
        }
        
        if (isset($this->backgroundTasks[$taskId])) {
            return ['status' => 'running', 'task' => $this->backgroundTasks[$taskId]];
        }
        
        return null;
    }

    /**
     * Generate unique task ID
     */
    protected function generateTaskId(string $type, array $data): string
    {
        $identifier = $data['path'] ?? $data['id'] ?? json_encode($data);
        return $type . '_' . substr(md5($identifier), 0, 8);
    }

    /**
     * Preprocess metadata in background
     */
    protected function preprocessMetadataInBackground(array $audiobook): array
    {
        $metadata = [
            'path' => $audiobook['path'],
            'size' => $audiobook['size'] ?? 0,
            'file_count' => count($audiobook['files'] ?? []),
            'processed_at' => now()->toIso8601String()
        ];

        if (!empty($audiobook['files'])) {
            $audioFiles = array_filter($audiobook['files'], function ($file) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                return in_array($ext, ['mp3', 'm4a', 'm4b', 'flac', 'wav', 'ogg']);
            });
            
            $metadata['audio_file_count'] = count($audioFiles);
            $metadata['has_audio_files'] = count($audioFiles) > 0;
        }

        return $metadata;
    }

    /**
     * Scan directory in background
     */
    protected function scanDirectoryInBackground(array $data): array
    {
        $path = $data['path'];
        
        if (!is_dir($path)) {
            throw new \InvalidArgumentException("Path is not a directory: {$path}");
        }

        $result = [
            'path' => $path,
            'files' => [],
            'directories' => [],
            'total_size' => 0
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($path . '/', '', $file->getPathname());
                $result['files'][] = $relativePath;
                $result['total_size'] += $file->getSize();
            }
        }

        $result['file_count'] = count($result['files']);
        
        return $result;
    }

    /**
     * Check duplicates in background
     */
    protected function checkDuplicatesInBackground(array $audiobook): array
    {
        return [
            'path' => $audiobook['path'],
            'is_duplicate' => false,
            'potential_duplicates' => [],
            'checked_at' => now()->toIso8601String()
        ];
    }

    /**
     * Extract metadata in background
     */
    protected function extractMetadataInBackground(array $audiobook): array
    {
        $path = $audiobook['path'];
        $baseName = basename($path);
        
        $metadata = [
            'title' => $baseName,
            'path' => $path,
            'extracted_at' => now()->toIso8601String()
        ];

        if (preg_match('/^(.+?)\s*-\s*(.+?)(?:\s*\((\d{4})\))?$/', $baseName, $matches)) {
            $metadata['author'] = trim($matches[1]);
            $metadata['title'] = trim($matches[2]);
            if (!empty($matches[3])) {
                $metadata['year'] = (int)$matches[3];
            }
        }

        return $metadata;
    }

    /**
     * Analyze audio files in background
     */
    protected function analyzeAudioFilesInBackground(array $audiobook): array
    {
        $audioFiles = $audiobook['files'] ?? [];
        $audioFiles = array_filter($audioFiles, function ($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($ext, ['mp3', 'm4a', 'm4b', 'flac', 'wav', 'ogg']);
        });

        $totalDuration = 0;
        $fileInfo = [];

        foreach (array_slice($audioFiles, 0, 5) as $file) {
            $fullPath = $audiobook['path'] . '/' . $file;
            if (file_exists($fullPath)) {
                $duration = $this->getAudioFileDuration($fullPath);
                $fileInfo[] = [
                    'file' => $file,
                    'duration' => $duration,
                    'size' => filesize($fullPath)
                ];
                $totalDuration += $duration;
            }
        }

        return [
            'audio_file_count' => count($audioFiles),
            'sample_files_analyzed' => count($fileInfo),
            'estimated_total_duration' => $totalDuration * (count($audioFiles) / max(1, count($fileInfo))),
            'file_info' => $fileInfo,
            'analyzed_at' => now()->toIso8601String()
        ];
    }

    /**
     * Prepare cover image in background
     */
    protected function prepareCoverImageInBackground(array $audiobook): array
    {
        $path = $audiobook['path'];
        $coverFiles = ['cover.jpg', 'cover.png', 'folder.jpg', 'folder.png'];
        
        foreach ($coverFiles as $coverFile) {
            $fullPath = $path . '/' . $coverFile;
            if (file_exists($fullPath)) {
                return [
                    'has_cover' => true,
                    'cover_file' => $coverFile,
                    'cover_path' => $fullPath,
                    'cover_size' => filesize($fullPath)
                ];
            }
        }

        return [
            'has_cover' => false,
            'checked_at' => now()->toIso8601String()
        ];
    }

    /**
     * Get audio file duration (simplified version)
     */
    protected function getAudioFileDuration(string $filePath): int
    {
        try {
            $output = shell_exec("ffprobe -v quiet -show_entries format=duration -of csv=p=0 " . escapeshellarg($filePath));
            return (int)floatval(trim($output));
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get current task statistics
     */
    public function getTaskStatistics(): array
    {
        return [
            'active_tasks' => count($this->backgroundTasks),
            'queued_tasks' => count($this->taskQueue),
            'completed_tasks' => count($this->completedTasks),
            'max_concurrent' => $this->maxConcurrentTasks
        ];
    }

    /**
     * Clear completed tasks to free memory
     */
    public function clearCompletedTasks(): void
    {
        $this->completedTasks = [];
    }
}