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

        $this->app->singleton(\App\Services\AI\AIAssistantService::class, function ($app) {
            $provider = config('services.ai.default_provider', 'gemini');
            $model = config('services.ai.default_model', 'gemini-2.5-flash-lite');

            return new \App\Services\AI\AIAssistantService($provider, $model);
        });

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


        // Dynamically set the application's base URL based on the incoming request.
        // This allows the app to respond correctly via multiple domains
        // (like books.thelin.org, api.ablibrarian.com, etc.)
        if (!app()->runningInConsole()) {
            $scheme = (bool) config('app.force_https', true) ? 'https' : request()->getScheme();
            URL::forceRootUrl($scheme . '://' . request()->getHost());
        }

        // Force HTTPS scheme for generated links when enabled (but not during unit tests)
        if ((bool) config('app.force_https', true) && !app()->runningUnitTests()) {
            URL::forceScheme('https');
        }
    }
}
