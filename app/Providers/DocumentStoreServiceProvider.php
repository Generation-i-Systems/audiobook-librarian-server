<?php

namespace App\Providers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\MySqlService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class DocumentStoreServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(DocumentStoreServiceInterface::class, function ($app) {
            if ($app->environment('testing')) {
                Log::info('Testing environment detected; binding MySqlService for DocumentStoreServiceInterface');
            }

            $driver = config('documentstore.driver');
            if ($driver !== 'mysql') {
                Log::warning("Document store driver '{$driver}' is no longer supported. Defaulting to MySqlService.");
            }

            return $this->createMysqlService();
        });
    }

    /**
     * Create MySqlService
     */
    protected function createMysqlService(): MySqlService
    {
        return new MySqlService();
    }

    // Firestore support removed

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
