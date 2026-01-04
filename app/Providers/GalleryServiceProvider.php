<?php

namespace App\Providers;

use App\Services\Contracts\SkinServiceInterface;
use App\Services\Contracts\ThemeServiceInterface;
use App\Services\SkinService;
use App\Services\ThemeService;
use Illuminate\Support\ServiceProvider;

class GalleryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(SkinServiceInterface::class, SkinService::class);
        $this->app->bind(ThemeServiceInterface::class, ThemeService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
