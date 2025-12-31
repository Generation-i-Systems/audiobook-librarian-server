<?php

namespace App\Providers;

use App\Models\Book;
use App\Observers\BookObserver;
use App\Services\AudibleApiService;
// Firestore support removed
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
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
        // DocumentStoreServiceInterface binding is now handled by DocumentStoreServiceProvider
        // to avoid conflicts and ensure proper driver selection based on documentstore.driver config

        // Firestore support removed
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->runningUnitTests()) {
            $defaultConnection = (string) config('database.default');
            $sqliteDatabase = (string) config('database.connections.sqlite.database');

            if ($defaultConnection !== 'sqlite' || $sqliteDatabase !== ':memory:') {
                throw new \RuntimeException(
                    "CRITICAL SAFETY FAILURE: Tests must run on in-memory SQLite. " .
                    "Got database.default='{$defaultConnection}', sqlite.database='{$sqliteDatabase}'."
                );
            }
        }

        // Register Book observer for automatic librarian.json updates
        Book::observe(BookObserver::class);

        // Register custom Documentstore user provider
        Auth::provider('documentstore', function ($app, array $config) {
            return new \App\Auth\DocumentUserProvider(
                $app->make(\App\Contracts\DocumentStoreServiceInterface::class)
            );
        });


        // Force HTTPS scheme for generated links when enabled (but not during unit tests)
        if ((bool) env('FORCE_HTTPS', true) && !app()->runningUnitTests()) {
            URL::forceScheme('https');
        }
    }
}
