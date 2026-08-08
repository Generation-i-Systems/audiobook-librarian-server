<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\BackupDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupDatabaseHoursSinceLastBackupTest extends TestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . '/backup_test_' . uniqid('', true);
        File::makeDirectory($this->backupDir, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returnsNullWhenNoBackupsExist(): void
    {
        $this->assertNull(BackupDatabase::hoursSinceLastBackup($this->backupDir));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returnsNullWhenBackupDirectoryDoesNotExist(): void
    {
        $this->assertNull(BackupDatabase::hoursSinceLastBackup($this->backupDir . '/missing'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returnsHoursSinceTheMostRecentBackupFile(): void
    {
        $oldFile = $this->backupDir . '/backup_test_old_20260101_000000.sql.gz';
        $recentFile = $this->backupDir . '/backup_test_recent_20260101_000000.sql.gz';
        File::put($oldFile, 'x');
        File::put($recentFile, 'x');

        touch($oldFile, time() - (10 * 3600));
        touch($recentFile, time() - (1 * 3600));

        $hours = BackupDatabase::hoursSinceLastBackup($this->backupDir);

        $this->assertNotNull($hours);
        $this->assertEqualsWithDelta(1.0, $hours, 0.05);
    }
}
