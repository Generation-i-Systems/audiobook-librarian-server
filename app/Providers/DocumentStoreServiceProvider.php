<?php

namespace App\Providers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\FirestoreService;
use App\Services\MongoService;
use App\Services\MySqlService;
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
            if ($app->environment('testing')) {
                return new MockDocumentStoreService();
            }

            $driver = config('documentstore.driver', 'mysql');

            return match ($driver) {
                'mongodb' => new MongoService(),
                'firestore' => new FirestoreService(),
                default => new MySqlService(),
            };
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
