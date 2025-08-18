<?php

namespace App\Providers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\MongoService;
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
            $driver = config('documentstore.driver');

            // In testing, force MySqlService to stabilize tests without external deps
            if ($app->environment('testing')) {
                Log::info('Testing environment detected; binding MySqlService for DocumentStoreServiceInterface');
                return $this->createMysqlService();
            }

            switch ($driver) {
                case 'mongodb':
                    // Mongo driver is archived and should not be used at runtime
                    Log::warning('MongoService is archived and only intended for migration commands. Using MySqlService instead.');
                    return $this->createMysqlService();
                case 'mysql':
                default:
                    return $this->createMysqlService();
            }
        });
    }

    /**
     * Create MongoService with proper configuration validation
     */
    protected function createMongoService(): MongoService
    {
        $uri = config('mongodb.uri');
        $database = config('mongodb.database');

        if (!$uri || !$database) {
            throw new \RuntimeException(
                "MongoDB service requested but configuration is missing. " .
                "Set MONGODB_URI and MONGODB_DB environment variables or change DOCUMENT_STORE_DRIVER to 'mysql'."
            );
        }

        return new MongoService();
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
