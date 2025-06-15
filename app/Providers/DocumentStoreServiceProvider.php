<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\DocumentStoreServiceInterface;
use App\Services\MongoService;

class DocumentStoreServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register()
    {
        $this->app->singleton(DocumentStoreServiceInterface::class, function ($app) {
            $driver = config('documentstore.driver', 'firestore');
            if ($driver === 'mongodb') {
                return new MongoService();
            }
            return new \App\Services\FirestoreService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot()
    {
        //
    }
}
