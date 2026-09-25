<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminerConnectionConfig
{
    /** @return array{driver: string, server: string, username: string, password: string, database: string} */
    public function get(): array
    {
        $connection = DB::connection();
        $config = $connection->getConfig();
        $driver = $connection->getDriverName();
        $adminerDriver = match ($driver) {
            'mysql', 'mariadb' => 'server',
            'pgsql' => 'pgsql',
            'sqlite' => 'sqlite',
            'sqlsrv' => 'mssql',
            default => throw new RuntimeException("Adminer does not support SQL driver '{$driver}'."),
        };

        if ($driver === 'sqlite') {
            $server = (string) $connection->getDatabaseName();
        } else {
            $server = (string) ($config['host'] ?? '127.0.0.1');
            if (!empty($config['port'])) {
                $server .= ':' . $config['port'];
            }
        }

        return [
            'driver' => $adminerDriver,
            'server' => $server,
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
            'database' => (string) $connection->getDatabaseName(),
        ];
    }
}
