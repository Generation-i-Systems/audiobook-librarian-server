<?php

namespace App\Providers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Contracts\DocumentStatsServiceInterface;
use App\Services\SqlDatabaseService;
use Illuminate\Support\ServiceProvider;

class DocumentStoreServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(DocumentStoreServiceInterface::class, SqlDatabaseService::class);
        $this->app->bind(DocumentStatsServiceInterface::class, SqlDatabaseService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
