<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DatabaseBackupWriter;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseBackupWriterTest extends TestCase
{
    public function testReportsSupportedBackupDrivers(): void
    {
        foreach (['sqlite', 'mysql', 'mariadb', 'pgsql'] as $driver) {
            $this->assertTrue(DatabaseBackupWriter::supportsDriver($driver));
        }
        $this->assertFalse(DatabaseBackupWriter::supportsDriver('sqlsrv'));
    }

    public function testSqliteBackupCopiesAConsistentDatabaseSnapshot(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'abl-source-');
        $destination = tempnam(sys_get_temp_dir(), 'abl-backup-');
        unlink($destination);

        try {
            config(['database.connections.backup_test' => [
                'driver' => 'sqlite',
                'database' => $source,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
            DB::purge('backup_test');
            $connection = DB::connection('backup_test');
            $connection->statement('CREATE TABLE backup_probe (value TEXT NOT NULL)');
            $connection->table('backup_probe')->insert(['value' => 'portable']);

            (new DatabaseBackupWriter())->write($connection, $destination);

            $snapshot = new \PDO('sqlite:' . $destination);
            $this->assertSame('portable', $snapshot->query('SELECT value FROM backup_probe')->fetchColumn());
        } finally {
            DB::disconnect('backup_test');
            @unlink($source);
            @unlink($destination);
        }
    }
}
