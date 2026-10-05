<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Models\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ArchiveAuditsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/audit-cmd-' . uniqid());
        config(['audits.archive_path' => $this->dir, 'app.database_backup_path' => $this->dir . '-backups']);
        foreach ([50, 2] as $days) {
            Audit::create([
                'auditable_type' => 'App\\Models\\Book',
                'auditable_id' => 1,
                'event' => 'created',
                'created_at' => now()->subDays($days),
                'updated_at' => now()->subDays($days),
            ]);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory($this->dir . '-backups');
        parent::tearDown();
    }

    private function freshBackup(float $hoursOld = 1.0): void
    {
        File::ensureDirectoryExists($this->dir . '-backups');
        $file = $this->dir . '-backups/backup_x_20260101_000000.sql.gz';
        touch($file, (int) (time() - $hoursOld * 3600));
    }

    public function testDryRunChangesNothing(): void
    {
        $this->artisan('audits:archive --dry-run')->expectsOutputToContain('1 audit row(s)')->assertSuccessful();

        $this->assertSame(2, Audit::count());
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function testRefusesWithoutARecentBackup(): void
    {
        $this->artisan('audits:archive --force')->assertFailed();
        $this->freshBackup(30.0);
        $this->artisan('audits:archive --force')->assertFailed();

        $this->assertSame(2, Audit::count());
    }

    public function testArchivesWithForceWhenABackupIsRecent(): void
    {
        $this->freshBackup();

        $this->artisan('audits:archive --force')->assertSuccessful();

        $this->assertSame(1, Audit::count());
        $this->assertCount(1, glob($this->dir . '/audits_*.ndjson.gz') ?: []);
    }

    public function testDecliningTheConfirmationChangesNothing(): void
    {
        $this->freshBackup();

        $this->artisan('audits:archive')->expectsConfirmation(
            "Export these rows to {$this->dir} and DELETE them from the database?",
            'no'
        )->assertFailed();

        $this->assertSame(2, Audit::count());
    }

    public function testDaysOptionMustBePositive(): void
    {
        $this->artisan('audits:archive --days=0')->assertFailed();
        $this->assertSame(2, Audit::count());
    }
}
