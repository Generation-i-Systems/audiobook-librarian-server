<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
        \Auth::provider('firestore', function ($app, array $config) {
            return new \App\Auth\FirestoreUserProvider();
        });
    }
}
