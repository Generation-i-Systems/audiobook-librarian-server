#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\DatabaseBackupPruner;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$directory = (string) config('app.database_backup_path');
$connection = (string) config('database.default');
$database = basename((string) config('database.connections.' . $connection . '.database'));
$dryRun = false;
$verbosity = 0;

for ($index = 1; $index < count($argv); $index++) {
    $argument = $argv[$index];
    if ($argument === '-n' || $argument === '--dry-run') {
        $dryRun = true;
    } elseif ($argument === '--apply') {
        $dryRun = false;
    } elseif (str_starts_with($argument, '--dir=')) {
        $directory = substr($argument, 6);
    } elseif (str_starts_with($argument, '--database=')) {
        $database = substr($argument, 11);
    } elseif ($argument === '-h' || $argument === '--help') {
        echo "Usage: php scripts/prune-db-backups.php [--dir=PATH] [--database=NAME] [-n|--dry-run] [-v|-vv|--verbose[=LEVEL]]\n";
        echo "Deletes old completed backups by default from DATABASE_BACKUP_PATH for the configured database.\n";
        exit(0);
    } elseif (preg_match('/^-v+$/D', $argument)) {
        $verbosity += strlen($argument) - 1;
    } elseif ($argument === '--verbose') {
        if (isset($argv[$index + 1]) && ctype_digit($argv[$index + 1])) {
            $verbosity = (int) $argv[++$index];
        } else {
            $verbosity++;
        }
    } elseif (preg_match('/^--verbose=(\d+)$/D', $argument, $matches)) {
        $verbosity = (int) $matches[1];
    } else {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        exit(2);
    }
}

$identifier = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $database);
if ($directory === '' || !is_dir($directory) || $identifier === '') {
    fwrite(STDERR, "Backup directory or database name is invalid.\n");
    exit(2);
}

$plan = (new DatabaseBackupPruner())->plan($directory, $identifier, new DateTimeImmutable('now'));
echo 'Keeping ' . count($plan['keep']) . ' completed backup(s); '
    . ($dryRun ? 'would delete ' : 'deleting ') . count($plan['delete']) . ".\n";
if ($verbosity > 0) {
    echo "Verbosity level {$verbosity}\n";
    foreach ($plan['keep'] as $file) {
        echo 'KEEP ' . $file . ($verbosity > 1 ? ' (' . filesize($file) . ' bytes)' : '') . "\n";
    }
}

foreach ($plan['delete'] as $file) {
    if (!$dryRun && (!is_file($file) || is_link($file) || !unlink($file))) {
        fwrite(STDERR, "Could not delete backup: {$file}\n");
        exit(1);
    }
    echo ($dryRun ? 'WOULD DELETE ' : 'DELETE ') . $file . "\n";
}
