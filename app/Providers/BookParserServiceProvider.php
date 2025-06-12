<?php

namespace App\Providers;

use App\Services\BookDirectoryParser;
use Illuminate\Support\ServiceProvider;

class BookParserServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/bookparser.php',
            'bookparser'
        );

        $this->app->singleton(BookDirectoryParser::class, function ($app) {
            return new BookDirectoryParser();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/bookparser.php' => config_path('bookparser.php'),
        ], 'config');
    }
}
