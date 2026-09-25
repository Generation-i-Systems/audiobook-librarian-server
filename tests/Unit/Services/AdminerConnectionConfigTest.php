<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AdminerConnectionConfig;
use Tests\TestCase;

class AdminerConnectionConfigTest extends TestCase
{
    public function testAdminerDefaultsFollowTheActiveSqlConnection(): void
    {
        $previous = config('database.default');
        try {
            foreach (['sqlite' => 'sqlite', 'pgsql' => 'pgsql', 'mysql' => 'server', 'sqlsrv' => 'mssql'] as $connection => $adminerDriver) {
                config(['database.default' => $connection]);
                $defaults = (new AdminerConnectionConfig())->get();
                $this->assertSame($adminerDriver, $defaults['driver']);
                $this->assertSame(config("database.connections.{$connection}.database"), $defaults['database']);
            }
        } finally {
            config(['database.default' => $previous]);
        }
    }
}
