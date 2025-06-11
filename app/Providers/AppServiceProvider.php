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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register custom Firestore user provider
        Auth::provider('firestore', function ($app, array $config) {
            return new \App\Auth\FirestoreUserProvider();
        });

        // Register Firestore queue driver
        Queue::extend('firestore', function ($app) {
            return new \App\Queue\FirestoreQueueConnector();
        });
    }
}
