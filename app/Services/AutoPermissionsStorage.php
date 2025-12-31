<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Storage wrapper that automatically queues files for permission fixes
 *
 * This class wraps Laravel's Storage facade and automatically adds created/modified
 * files to the permissions queue so they'll be fixed by the cron job.
 *
 * Usage:
 *   Instead of: Storage::disk('books')->put($path, $contents);
 *   Use: app(AutoPermissionsStorage::class)->put($path, $contents);
 *
 * Or inject it as a dependency:
 *   public function __construct(protected AutoPermissionsStorage $storage) {}
 */
class AutoPermissionsStorage
{
    protected FilesystemAdapter $disk;
    protected PermissionsQueueService $queue;

    public function __construct(PermissionsQueueService $queue, ?string $diskName = null)
    {
        $this->queue = $queue;
        $this->disk = Storage::disk($diskName ?? 'books');
    }

    /**
     * Get the underlying disk instance
     */
    public function disk(?string $name = null): FilesystemAdapter
    {
        if ($name) {
            return Storage::disk($name);
        }
        return $this->disk;
    }

    /**
     * Queue a path for permissions fix
     */
    protected function queuePath(string $path): void
    {
        $fullPath = $this->disk->path($path);
        $this->queue->addPath($fullPath);
    }

    /**
     * Queue directory and optionally its parent directories
     */
    protected function queueDirectory(string $path, bool $includeParents = false): void
    {
        $fullPath = $this->disk->path($path);

        if ($includeParents) {
            // Queue all parent directories up to the root
            $parts = explode('/', trim($path, '/'));
            $currentPath = '';
            foreach ($parts as $part) {
                $currentPath .= '/' . $part;
                $this->queue->addPath($this->disk->path(trim($currentPath, '/')));
            }
        } else {
            $this->queue->addPath($fullPath);
        }
    }

    // ==================== File Operations ====================

    /**
     * Store the contents of a file
     */
    public function put(string $path, $contents, $options = []): string|false
    {
        $result = $this->disk->put($path, $contents, $options);

        if ($result !== false) {
            $this->queuePath($path);
        }

        return $result;
    }

    /**
     * Store a file
     */
    public function putFile(string $path, UploadedFile|string $file, $options = []): string|false
    {
        $result = $this->disk->putFile($path, $file, $options);

        if ($result !== false) {
            $this->queuePath($result);
            // Also queue the directory
            $this->queueDirectory(dirname($result));
        }

        return $result;
    }

    /**
     * Store a file with a specific name
     */
    public function putFileAs(string $path, UploadedFile|string $file, string $name, $options = []): string|false
    {
        $result = $this->disk->putFileAs($path, $file, $name, $options);

        if ($result !== false) {
            $this->queuePath($result);
            $this->queueDirectory($path);
        }

        return $result;
    }

    /**
     * Copy a file
     */
    public function copy(string $from, string $to): bool
    {
        $result = $this->disk->copy($from, $to);

        if ($result) {
            $this->queuePath($to);
            $this->queueDirectory(dirname($to));
        }

        return $result;
    }

    /**
     * Move a file
     */
    public function move(string $from, string $to): bool
    {
        $result = $this->disk->move($from, $to);

        if ($result) {
            $this->queuePath($to);
            $this->queueDirectory(dirname($to));
        }

        return $result;
    }

    /**
     * Write the contents of a file (alias for put)
     */
    public function write(string $path, $contents, $options = []): string|false
    {
        return $this->put($path, $contents, $options);
    }

    // ==================== Directory Operations ====================

    /**
     * Create a directory
     */
    public function makeDirectory(string $path): bool
    {
        $result = $this->disk->makeDirectory($path);

        if ($result) {
            $this->queueDirectory($path, true); // Include parents
        }

        return $result;
    }

    /**
     * Copy a directory
     */
    public function copyDirectory(string $from, string $to): bool
    {
        $result = $this->disk->copyDirectory($from, $to);

        if ($result) {
            $this->queueDirectory($to); // Queue will process recursively
        }

        return $result;
    }

    /**
     * Move a directory
     */
    public function moveDirectory(string $from, string $to): bool
    {
        $result = $this->disk->moveDirectory($from, $to);

        if ($result) {
            $this->queueDirectory($to); // Queue will process recursively
        }

        return $result;
    }

    // ==================== Read-Only Operations (passthrough) ====================

    public function exists(string $path): bool
    {
        return $this->disk->exists($path);
    }

    public function missing(string $path): bool
    {
        return $this->disk->missing($path);
    }

    public function get(string $path): ?string
    {
        return $this->disk->get($path);
    }

    public function read(string $path): ?string
    {
        return $this->disk->read($path);
    }

    public function size(string $path): int
    {
        return $this->disk->size($path);
    }

    public function lastModified(string $path): int
    {
        return $this->disk->lastModified($path);
    }

    public function files(?string $directory = null, bool $recursive = false): array
    {
        return $this->disk->files($directory, $recursive);
    }

    public function allFiles(?string $directory = null): array
    {
        return $this->disk->allFiles($directory);
    }

    public function directories(?string $directory = null, bool $recursive = false): array
    {
        return $this->disk->directories($directory, $recursive);
    }

    public function allDirectories(?string $directory = null): array
    {
        return $this->disk->allDirectories($directory);
    }

    public function delete(string|array $paths): bool
    {
        return $this->disk->delete($paths);
    }

    public function deleteDirectory(string $directory): bool
    {
        return $this->disk->deleteDirectory($directory);
    }

    public function path(string $path): string
    {
        return $this->disk->path($path);
    }

    public function url(string $path): string
    {
        return $this->disk->url($path);
    }

    /**
     * Pass through any other method calls to the underlying disk
     */
    public function __call(string $method, array $parameters)
    {
        $result = $this->disk->$method(...$parameters);

        // Log unknown method calls for debugging
        Log::debug('AutoPermissionsStorage: passthrough method called', [
            'method' => $method,
            'parameters_count' => count($parameters),
        ]);

        return $result;
    }
}
