<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DatabaseBackupPruner;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DatabaseBackupPrunerTest extends TestCase
{
    #[Test]
    public function keepsNewestBackupForEachDailyWeeklyAndMonthlyPeriod(): void
    {
        $directory = sys_get_temp_dir() . '/backup-prune-' . bin2hex(random_bytes(8));
        mkdir($directory);

        try {
            $names = [
                'backup_library_20261029_020000.sql.gz',
                'backup_library_20261029_030000.sql.gz',
                'backup_library_20261025_020000.sql.gz',
                'backup_library_20261024_020000.sql.gz',
                'backup_library_20261018_020000.sql.gz',
                'backup_library_20261011_020000.sql.gz',
                'backup_library_20260929_020000.sql.gz',
                'backup_library_20260928_020000.sql.gz',
                'backup_library_20260831_020000.sql.gz',
            ];
            foreach ($names as $name) {
                file_put_contents($directory . '/' . $name, 'backup');
            }

            $plan = (new DatabaseBackupPruner())->plan($directory, 'library', new DateTimeImmutable('2026-10-29 12:00:00'));

            $this->assertSame([
                $directory . '/backup_library_20261029_030000.sql.gz',
                $directory . '/backup_library_20261025_020000.sql.gz',
                $directory . '/backup_library_20261018_020000.sql.gz',
                $directory . '/backup_library_20261011_020000.sql.gz',
                $directory . '/backup_library_20260929_020000.sql.gz',
                $directory . '/backup_library_20260831_020000.sql.gz',
            ], $plan['keep']);
            $this->assertSame([
                $directory . '/backup_library_20261029_020000.sql.gz',
                $directory . '/backup_library_20261024_020000.sql.gz',
                $directory . '/backup_library_20260928_020000.sql.gz',
            ], $plan['delete']);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    #[Test]
    public function includesNamedBackupsButIgnoresPartialUnrelatedAndFutureBackups(): void
    {
        $directory = sys_get_temp_dir() . '/backup-prune-' . bin2hex(random_bytes(8));
        mkdir($directory);

        try {
            foreach ([
                'backup_library_20261028_230000.sqlite',
                'backup_library_20261029_020000.sqlite',
                'backup_library_pre-repair_20261028_020000.sql.gz',
                'backup_other_20261028_020000.sql.gz',
                'backup_library_20261028_020000.sql.gz.part',
                'backup_library_20261030_020000.sql.gz',
                'backup_library_20261399_020000.sql.gz',
            ] as $name) {
                file_put_contents($directory . '/' . $name, 'backup');
            }

            $plan = (new DatabaseBackupPruner())->plan($directory, 'library', new DateTimeImmutable('2026-10-29 12:00:00'));

            $this->assertSame([
                $directory . '/backup_library_20261029_020000.sqlite',
                $directory . '/backup_library_pre-repair_20261028_020000.sql.gz',
            ], $plan['keep']);
            $this->assertSame([$directory . '/backup_library_20261028_230000.sqlite'], $plan['delete']);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    #[Test]
    public function choosesNewestTimestampAcrossNamedAndScheduledBackups(): void
    {
        $directory = sys_get_temp_dir() . '/backup-prune-' . bin2hex(random_bytes(8));
        mkdir($directory);

        try {
            $scheduled = $directory . '/backup_library_20261029_020000.sql.gz';
            $named = $directory . '/backup_library_import-books_20261029_030000.sql.gz';
            file_put_contents($scheduled, 'backup');
            file_put_contents($named, 'backup');

            $plan = (new DatabaseBackupPruner())->plan($directory, 'library', new DateTimeImmutable('2026-10-29 12:00:00'));

            $this->assertSame([$named], $plan['keep']);
            $this->assertSame([$scheduled], $plan['delete']);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    #[Test]
    public function scriptPreviewsThenAppliesThePlanInAnIsolatedDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/backup-prune-' . bin2hex(random_bytes(8));
        mkdir($directory);

        try {
            $now = new DateTimeImmutable('now');
            $old = $directory . '/backup_library_' . $now->modify('-2 hours')->format('Ymd_His') . '.sql.gz';
            $new = $directory . '/backup_library_' . $now->modify('-1 hour')->format('Ymd_His') . '.sql.gz';
            $named = $directory . '/backup_library_pre-repair_' . $now->modify('-3 hours')->format('Ymd_His') . '.sql.gz';
            foreach ([$old, $new, $named] as $file) {
                file_put_contents($file, 'backup');
            }

            $script = dirname(__DIR__, 3) . '/scripts/prune-db-backups.php';
            $options = ['--dir=' . $directory, '--database=library'];
            $preview = new Process([PHP_BINARY, $script, ...$options, '-n', '-vv']);
            $preview->run();

            $this->assertSame(0, $preview->getExitCode(), $preview->getErrorOutput());
            $this->assertStringContainsString('WOULD DELETE ' . $old, $preview->getOutput());
            $this->assertStringContainsString('KEEP ' . $new, $preview->getOutput());
            $this->assertStringContainsString('Verbosity level 2', $preview->getOutput());
            $this->assertFileExists($old);

            $apply = new Process([PHP_BINARY, $script, ...$options]);
            $apply->run();

            $this->assertSame(0, $apply->getExitCode(), $apply->getErrorOutput());
            $this->assertFileDoesNotExist($old);
            $this->assertFileExists($new);
            $this->assertFileDoesNotExist($named);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    #[Test]
    public function scriptSupportsHelpAndNumericVerboseLevel(): void
    {
        $script = dirname(__DIR__, 3) . '/scripts/prune-db-backups.php';

        foreach (['-h', '--help'] as $option) {
            $help = new Process([PHP_BINARY, $script, $option]);
            $help->run();
            $this->assertSame(0, $help->getExitCode(), $help->getErrorOutput());
            $this->assertStringContainsString('--dry-run', $help->getOutput());
        }

        $directory = sys_get_temp_dir() . '/backup-prune-' . bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $verbose = new Process([
                PHP_BINARY,
                $script,
                '--dir=' . $directory,
                '--database=library',
                '--dry-run',
                '--verbose=3',
            ]);
            $verbose->run();
            $this->assertSame(0, $verbose->getExitCode(), $verbose->getErrorOutput());
            $this->assertStringContainsString('Verbosity level 3', $verbose->getOutput());
        } finally {
            rmdir($directory);
        }
    }
}
