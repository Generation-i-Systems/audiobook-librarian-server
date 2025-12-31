<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PermissionsQueueService
{
    protected string $queueFile;

    public function __construct()
    {
        $this->queueFile = storage_path('app/permissions-queue.txt');
    }

    /**
     * Add a path to the permissions queue
     *
     * @param string $path Absolute path to file or directory
     * @return bool True if added successfully
     */
    public function addPath(string $path): bool
    {
        return $this->addPaths([$path]);
    }

    /**
     * Add multiple paths to the permissions queue
     *
     * @param array $paths Array of absolute paths
     * @return bool True if all added successfully
     */
    public function addPaths(array $paths): bool
    {
        try {
            // Ensure queue file exists
            if (!file_exists($this->queueFile)) {
                Log::warning('PermissionsQueueService: Queue file not found', [
                    'queue_file' => $this->queueFile,
                ]);
                return false;
            }

            // Read existing paths
            $existingPaths = $this->readQueue();

            // Add new paths (avoiding duplicates)
            $newPaths = [];
            foreach ($paths as $path) {
                $path = trim($path);
                if (empty($path)) {
                    continue;
                }

                // Skip if already in queue
                if (in_array($path, $existingPaths)) {
                    Log::debug('PermissionsQueueService: Path already in queue', ['path' => $path]);
                    continue;
                }

                $newPaths[] = $path;
            }

            if (empty($newPaths)) {
                return true; // Nothing to add
            }

            // Append new paths to queue file
            $content = implode("\n", $newPaths) . "\n";
            file_put_contents($this->queueFile, $content, FILE_APPEND | LOCK_EX);

            Log::info('PermissionsQueueService: Added paths to queue', [
                'count' => count($newPaths),
                'paths' => $newPaths,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('PermissionsQueueService: Failed to add paths to queue', [
                'error' => $e->getMessage(),
                'paths' => $paths,
            ]);
            return false;
        }
    }

    /**
     * Read all paths from the queue
     *
     * @return array Array of paths (excluding comments and empty lines)
     */
    protected function readQueue(): array
    {
        if (!file_exists($this->queueFile)) {
            return [];
        }

        $lines = file($this->queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $paths = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments and empty lines
            if ($line && !str_starts_with($line, '#')) {
                $paths[] = $line;
            }
        }

        return $paths;
    }

    /**
     * Get the number of paths in the queue
     *
     * @return int Number of paths waiting to be fixed
     */
    public function getQueueSize(): int
    {
        return count($this->readQueue());
    }

    /**
     * Check if a path is in the queue
     *
     * @param string $path Path to check
     * @return bool True if path is in queue
     */
    public function isInQueue(string $path): bool
    {
        return in_array($path, $this->readQueue());
    }
}
