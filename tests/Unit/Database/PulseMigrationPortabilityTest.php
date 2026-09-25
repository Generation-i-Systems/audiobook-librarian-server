<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Tests\TestCase;

class PulseMigrationPortabilityTest extends TestCase
{
    public function testOptionalPulseMigrationDoesNotBlockAnotherLaravelSqlDriver(): void
    {
        $previous = config('database.default');
        $previousEnvironment = app()->environment();
        config(['database.default' => 'sqlsrv']);
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            $migration = require base_path('database/migrations/2026_04_10_180958_create_pulse_tables.php');
            $migration->up();
            $this->assertNull($migration->up());
        } finally {
            config(['database.default' => $previous]);
            app()->detectEnvironment(static fn (): string => $previousEnvironment);
        }
    }
}
