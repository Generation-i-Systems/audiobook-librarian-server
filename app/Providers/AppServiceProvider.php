<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
