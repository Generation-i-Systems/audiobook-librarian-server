<?php

namespace App\Providers;

use App\Services\AudibleApiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AudibleApiService::class, function ($app) {
            return new AudibleApiService(config('services.audible', []));
        });
        // Bind DocumentStoreServiceInterface to MongoService
        $this->app->bind(\App\Contracts\DocumentStoreServiceInterface::class, \App\Services\MongoService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register custom Documentstore user provider
        Auth::provider('documentstore', function ($app, array $config) {
            return new \App\Auth\DocumentUserProvider(
                $app->make(\App\Contracts\DocumentStoreServiceInterface::class)
            );
        });

        // Register Documentstore queue driver
        Queue::extend('documentstore', function ($app) {
            return new \App\Queue\DocumentstoreQueueConnector();
        });
    }
}
