<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DatabaseBackupScheduleTest extends TestCase
{
    public function testAutomaticBackupRemainsScheduledForSqlite(): void
    {
        $previous = config('database.default');
        try {
            Artisan::call('list');
            config(['database.default' => 'sqlite']);
            app()->forgetInstance(Schedule::class);
            $events = app(Schedule::class)->events();
            $backupEvents = array_filter($events, static fn ($event): bool => str_contains($event->command, 'backup:database'));
            $this->assertCount(2, $backupEvents);
        } finally {
            config(['database.default' => $previous]);
            app()->forgetInstance(Schedule::class);
        }
    }

    public function testAutomaticBackupIsNotScheduledForUnsupportedSqlDriver(): void
    {
        $previous = config('database.default');
        try {
            Artisan::call('list');
            config(['database.default' => 'sqlsrv']);
            app()->forgetInstance(Schedule::class);
            $events = app(Schedule::class)->events();
            $backupEvents = array_filter($events, static fn ($event): bool => str_contains($event->command, 'backup:database'));
            $this->assertSame([], array_values($backupEvents));
        } finally {
            config(['database.default' => $previous]);
            app()->forgetInstance(Schedule::class);
        }
    }
}
