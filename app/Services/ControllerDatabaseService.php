<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ControllerDatabaseService
{
    public static function table(string $table): Builder
    {
        return DB::table($table);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    public static function beginTransaction(): void
    {
        DB::beginTransaction();
    }

    public static function commit(): void
    {
        DB::commit();
    }

    public static function rollBack(): void
    {
        DB::rollBack();
    }

    public static function getDriverName(): string
    {
        return DB::connection()->getDriverName();
    }

    public static function connection(): Connection
    {
        return DB::connection();
    }
}
