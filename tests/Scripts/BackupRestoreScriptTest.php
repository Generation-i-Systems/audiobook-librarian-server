<?php

namespace Tests\Scripts;

use PHPUnit\Framework\TestCase;

class BackupRestoreScriptTest extends TestCase
{
    private string $projectRoot;
    private string $backupScript;
    private string $restoreScript;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = dirname(dirname(__DIR__));
        $this->backupScript = $this->projectRoot . '/scripts/backup-mysql.sh';
        $this->restoreScript = $this->projectRoot . '/scripts/restore-mysql-backup.sh';
    }

    /** @test */
    public function backupScriptExists()
    {
        $this->assertFileExists($this->backupScript);
    }

    /** @test */
    public function restoreScriptExists()
    {
        $this->assertFileExists($this->restoreScript);
    }

    /** @test */
    public function backupScriptHasValidSyntax()
    {
        $output = [];
        $returnCode = 0;
        exec("bash -n {$this->backupScript} 2>&1", $output, $returnCode);

        $this->assertEquals(0, $returnCode, 'Backup script has syntax errors: ' . implode("\n", $output));
    }

    /** @test */
    public function restoreScriptHasValidSyntax()
    {
        $output = [];
        $returnCode = 0;
        exec("bash -n {$this->restoreScript} 2>&1", $output, $returnCode);

        $this->assertEquals(0, $returnCode, 'Restore script has syntax errors: ' . implode("\n", $output));
    }

    /** @test */
    public function backupScriptIncludesCompleteInsertFlag()
    {
        $content = file_get_contents($this->backupScript);

        $this->assertStringContainsString(
            '--complete-insert',
            $content,
            'Backup script should include --complete-insert flag for field names'
        );
    }

    /** @test */
    public function backupScriptIncludesExtendedInsertFalseFlag()
    {
        $content = file_get_contents($this->backupScript);

        $this->assertStringContainsString(
            '--extended-insert=FALSE',
            $content,
            'Backup script should include --extended-insert=FALSE flag for readability'
        );
    }

    /** @test */
    public function restoreScriptChecksUsersTableCount()
    {
        $content = file_get_contents($this->restoreScript);

        $this->assertStringContainsString(
            'SELECT COUNT(*) FROM users',
            $content,
            'Restore script should check users table count'
        );
    }

    /** @test */
    public function restoreScriptChecksBooksTableCount()
    {
        $content = file_get_contents($this->restoreScript);

        $this->assertStringContainsString(
            'SELECT COUNT(*) FROM books',
            $content,
            'Restore script should check books table count'
        );
    }

    /** @test */
    public function restoreScriptSkipsConfirmationWhenTablesEmpty()
    {
        $content = file_get_contents($this->restoreScript);

        $this->assertStringContainsString(
            'Both users and books tables are empty',
            $content,
            'Restore script should skip confirmation when both tables are empty'
        );
    }

    /** @test */
    public function restoreScriptHandlesTableCountErrors()
    {
        $content = file_get_contents($this->restoreScript);

        // Should handle errors by defaulting to "0" count
        $this->assertStringContainsString(
            '2>/dev/null || echo "0"',
            $content,
            'Restore script should handle table count query errors gracefully'
        );
    }

    /** @test */
    public function backupScriptHasProperMysqldumpOptions()
    {
        $content = file_get_contents($this->backupScript);

        // Check for essential mysqldump options
        $this->assertStringContainsString('--single-transaction', $content);
        $this->assertStringContainsString('--routines', $content);
        $this->assertStringContainsString('--triggers', $content);
        $this->assertStringContainsString('--events', $content);
        $this->assertStringContainsString('--add-drop-database', $content);
    }

    /** @test */
    public function restoreScriptValidatesBackupFileExtension()
    {
        $content = file_get_contents($this->restoreScript);

        $this->assertStringContainsString(
            '*.sql.gz',
            $content,
            'Restore script should validate backup file has .sql.gz extension'
        );
    }

    /** @test */
    public function restoreScriptChecksBackupFileExists()
    {
        $content = file_get_contents($this->restoreScript);

        $this->assertStringContainsString(
            '! -f "$BACKUP_FILE"',
            $content,
            'Restore script should check if backup file exists'
        );
    }

    /** @test */
    public function scriptsHaveExecutablePermissions()
    {
        $this->assertTrue(
            is_executable($this->backupScript),
            'Backup script should be executable'
        );
        $this->assertTrue(
            is_executable($this->restoreScript),
            'Restore script should be executable'
        );
    }
}
