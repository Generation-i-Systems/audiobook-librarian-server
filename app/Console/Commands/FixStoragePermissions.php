<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\SafeLoggingService;

class FixStoragePermissions extends Command
{
    protected $signature = 'storage:fix-permissions 
                          {--dry-run : Show what would be changed without making changes}';

    protected $description = 'Fix storage directory permissions to recommended Laravel settings';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $verbose = $this->option('verbose') || $this->getOutput()->getVerbosity() >= 128; // VERBOSITY_VERBOSE

        Log::debug('FixStoragePermissions command started.', [
            'user' => get_current_user(),
            'euid' => posix_geteuid(),
            'egid' => posix_getegid(),
            'dry_run' => $dryRun,
            'verbose' => $verbose,
        ]);

        if ($dryRun) {
            $this->warn('🔍 Running in DRY-RUN mode. No changes will be made.');
        }

        $this->info('🔐 Fixing storage directory permissions...');

        $storagePath = storage_path();
        $errors = [];
        $changes = [];

        // Define permission settings
        $permissions = [
            // General storage directories - 755 (rwxr-xr-x)
            $storagePath => 0755,
            $storagePath . '/app' => 0755,
            $storagePath . '/app/public' => 0755,
            $storagePath . '/framework' => 0755,
            $storagePath . '/framework/cache' => 0755,
            $storagePath . '/framework/sessions' => 0755,
            $storagePath . '/framework/views' => 0755,

            // Logs directory - 777 (rwxrwxrwx) for world-writable access
            $storagePath . '/logs' => 0777,
        ];

        // Set directory permissions
        foreach ($permissions as $path => $permission) {
            if (is_dir($path)) {
                $currentPerms = fileperms($path) & 0777;

                if ($currentPerms !== $permission) {
                    $changes[] = [
                        'path' => $path,
                        'old' => sprintf('%o', $currentPerms),
                        'new' => sprintf('%o', $permission),
                        'type' => 'directory'
                    ];

                    if (!$dryRun) {
                        if (!chmod($path, $permission)) {
                            $errors[] = "Failed to set permissions on directory: {$path}";
                        }
                    }
                }

                if ($verbose) {
                    $this->line("📁 {$path}: " . sprintf('%o', $permission));
                }
            } else {
                if ($verbose) {
                    $this->warn("⚠️  Directory does not exist: {$path}");
                }
            }
        }

        // Fix log files permissions (world writable)
        $this->fixLogFilePermissions($storagePath . '/logs', $dryRun, $verbose, $changes, $errors);

        // Recursively fix permissions for cache and other subdirectories
        $this->fixRecursivePermissions($storagePath . '/framework/cache', 0755, 0644, $dryRun, $verbose, $changes, $errors);
        $this->fixRecursivePermissions($storagePath . '/framework/sessions', 0755, 0666, $dryRun, $verbose, $changes, $errors); // Session files need to be writable
        $this->fixRecursivePermissions($storagePath . '/framework/views', 0755, 0644, $dryRun, $verbose, $changes, $errors);

        // Display results
        if (!empty($changes)) {
            $this->info("\n📋 Changes " . ($dryRun ? 'needed' : 'made') . ":");
            foreach ($changes as $change) {
                $icon = $change['type'] === 'directory' ? '📁' : '📄';
                $this->line("   {$icon} {$change['path']}: {$change['old']} → {$change['new']}");
            }
        } else {
            $this->info("✅ All permissions are already correct!");
        }

        if (!empty($errors)) {
            $this->error("\n❌ Errors encountered:");
            foreach ($errors as $error) {
                $this->error("   {$error}");
            }
        }

        // Log the operation
        SafeLoggingService::safeLog('info', 'Storage permissions fix completed', [
            'dry_run' => $dryRun,
            'changes_count' => count($changes),
            'errors_count' => count($errors),
            'changes' => $changes,
            'errors' => $errors
        ]);

        $this->info("\n🎉 Storage permissions fix completed!");

        if ($dryRun && !empty($changes)) {
            $this->warn("💡 Run without --dry-run to apply these changes.");
        }

        return empty($errors) ? 0 : 1;
    }

    protected function fixLogFilePermissions(string $logsPath, bool $dryRun, bool $verbose, array &$changes, array &$errors): void
    {
        if (!is_dir($logsPath)) {
            Log::debug("fixLogFilePermissions: Logs path does not exist: {$logsPath}");
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($logsPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'log') {
                $filePath = $file->getPathname();
                $currentPerms = fileperms($filePath) & 0777;
                $targetPerms = 0666; // rw-rw-rw- (world writable)

                Log::debug("fixLogFilePermissions: Processing file: {$filePath}, current: " . sprintf('%o', $currentPerms) . ", target: " . sprintf('%o', $targetPerms));

                if ($currentPerms !== $targetPerms) {
                    $changes[] = [
                        'path' => $filePath,
                        'old' => sprintf('%o', $currentPerms),
                        'new' => sprintf('%o', $targetPerms),
                        'type' => 'logfile'
                    ];

                    if (!$dryRun) {
                        if (!chmod($filePath, $targetPerms)) {
                            $errors[] = "Failed to set permissions on log file: {$filePath}";
                            Log::error("Failed to set permissions on log file: {$filePath}");
                        }
                    }
                }

                if ($verbose) {
                    $this->line("📄 {$filePath}: " . sprintf('%o', $targetPerms));
                }
            }
        }
    }

    protected function fixRecursivePermissions(string $path, int $dirPerms, int $filePerms, bool $dryRun, bool $verbose, array &$changes, array &$errors): void
    {
        if (!is_dir($path)) {
            Log::debug("fixRecursivePermissions: Path does not exist: {$path}");
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            $currentPerms = fileperms($itemPath) & 0777;
            $targetPerms = $item->isDir() ? $dirPerms : $filePerms;

            Log::debug("fixRecursivePermissions: Processing item: {$itemPath}, current: " . sprintf('%o', $currentPerms) . ", target: " . sprintf('%o', $targetPerms));

            if ($currentPerms !== $targetPerms) {
                $changes[] = [
                    'path' => $itemPath,
                    'old' => sprintf('%o', $currentPerms),
                    'new' => sprintf('%o', $targetPerms),
                    'type' => $item->isDir() ? 'directory' : 'file'
                ];

                if (!$dryRun) {
                    if (!chmod($itemPath, $targetPerms)) {
                        $errors[] = "Failed to set permissions on " . ($item->isDir() ? 'directory' : 'file') . ": {$itemPath}";
                        Log::error("Failed to set permissions on " . ($item->isDir() ? 'directory' : 'file') . ": {$itemPath}");
                    }
                }
            }

            if ($verbose) {
                $icon = $item->isDir() ? '📁' : '📄';
                $this->line("{$icon} {$itemPath}: " . sprintf('%o', $targetPerms));
            }
        }
    }
}
