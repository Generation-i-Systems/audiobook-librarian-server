<?php

namespace App\Providers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\FirestoreService;
use App\Services\MongoService;
use App\Services\MySqlService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Tests\Mocks\MockDocumentStoreService;

class DocumentStoreServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(DocumentStoreServiceInterface::class, function ($app) {
            $driver = config('documentstore.driver');

            switch ($driver) {
                case 'firestore':
                    return $this->createFirestoreService();
                case 'mongodb':
                    return $this->createMongoService();
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

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
