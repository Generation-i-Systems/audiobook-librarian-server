<?php

namespace App\Providers;

use App\Services\HardcoverService;
use Illuminate\Support\ServiceProvider;

class HardcoverServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(HardcoverService::class, function ($app) {
            $service = new HardcoverService();

            // Additional service configuration can be done here if needed
            return $service;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish the config file
        $this->publishes([
            __DIR__ . '/../../config/hardcover.php' => config_path('hardcover.php'),
        ], 'config');
    }
}
