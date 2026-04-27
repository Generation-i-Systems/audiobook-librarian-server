<?php

namespace App\Providers;

use App\Contracts\BookServiceInterface;
use App\Services\AudibleService;
use App\Services\GoogleBooksApiService;
use App\Services\HardcoverService;
use App\Services\LibriVoxApiService;
use Illuminate\Support\ServiceProvider;

class BookServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('book.services', function ($app) {
            return new \SplObjectStorage();
        });

        // Register each book service
        $this->registerBookService('audible', function () {
            return new AudibleService();
        });

        $this->registerBookService('librivox', function () {
            return new LibriVoxApiService();
        });

        // TODO: Register other services once they're updated to use the interface
        // $this->registerBookService('google_books', function () {
        //     return new GoogleBooksApiService();
        // });

        // $this->registerBookService('hardcover', function () {
        //     return new HardcoverService();
        // });
    }

    /**
     * Register a book service
     */
    protected function registerBookService(string $name, callable $factory): void
    {
        $this->app->when($name)
            ->needs(BookServiceInterface::class)
            ->give($factory);

        $this->app->extend('book.services', function ($services) use ($name, $factory) {
            $service = $factory();
            if ($service->isAvailable()) {
                $services->attach($name, $service);
            }

            return $services;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
