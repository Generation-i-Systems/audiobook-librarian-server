<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackupWriter
{
    public static function supportsDriver(string $driver): bool
    {
        return in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true);
    }

    public function write(Connection $connection, string $destination): void
    {
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            if ($connection->getDatabaseName() === ':memory:') {
                throw new RuntimeException('An in-memory SQLite database cannot be backed up to a persistent file.');
            }

            $quotedPath = $connection->getPdo()->quote($destination);
            $connection->statement('VACUUM INTO ' . $quotedPath);
            return;
        }

        $config = $connection->getConfig();
        $name = (string) $connection->getDatabaseName();
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? ($driver === 'pgsql' ? '5432' : '3306'));
        $user = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        if ($driver === 'pgsql') {
            $arguments = ['pg_dump', '--format=plain', '--no-owner', '--no-privileges',
                '--host=' . $host, '--port=' . $port, '--username=' . $user, $name];
            $environment = ['PGPASSWORD' => $password];
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $arguments = ['mysqldump', '--single-transaction', '--routines', '--triggers', '--events',
                '--host=' . $host, '--port=' . $port, '--user=' . $user, $name];
            $environment = ['MYSQL_PWD' => $password];
        } else {
            throw new RuntimeException("Database backup is not implemented for SQL driver '{$driver}'.");
        }

        $stream = fopen($destination, 'wb');
        if ($stream === false) {
            throw new RuntimeException("Cannot write database backup to {$destination}.");
        }

        $failed = false;
        try {
            $process = new Process($arguments, null, $environment);
            $process->setTimeout(null);
            $process->run(static function (string $type, string $buffer) use ($stream): void {
                if ($type === Process::OUT) {
                    $remaining = $buffer;
                    while ($remaining !== '') {
                        $written = fwrite($stream, $remaining);
                        if ($written === false || $written === 0) {
                            throw new RuntimeException('Writing the database backup failed.');
                        }
                        $remaining = substr($remaining, $written);
                    }
                }
            });
            if (!$process->isSuccessful()) {
                throw new RuntimeException('Database dump failed: ' . trim($process->getErrorOutput()));
            }
        } catch (\Throwable $exception) {
            $failed = true;
            throw $exception;
        } finally {
            fclose($stream);
            if ($failed) {
                @unlink($destination);
            }
        }
    }
}
