<?php

namespace App\Providers;

use App\Models\Book;
use App\Observers\BookObserver;
use App\Services\AudibleApiService;
// FirestoreService has been archived
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
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
        // DocumentStoreServiceInterface binding is now handled by DocumentStoreServiceProvider
        // to avoid conflicts and ensure proper driver selection based on documentstore.driver config

        $this->app->singleton(FirestoreClient::class, function ($app) {
            return new FirestoreClient([
                'projectId' => env('FIREBASE_PROJECT_ID'),
                'keyFilePath' => base_path(env('FIREBASE_CREDENTIALS')),
            ]);
        });

        // FirestoreService has been archived
        // $this->app->singleton(FirestoreService::class, function ($app) {
        //     return new FirestoreService($app->make(FirestoreClient::class));
        // });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register Book observer for automatic library.json updates
        Book::observe(BookObserver::class);

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

        // Force HTTPS scheme for generated links when enabled (but not during unit tests)
        if ((bool) env('FORCE_HTTPS', true) && !app()->runningUnitTests()) {
            URL::forceScheme('https');
        }
    }
}
