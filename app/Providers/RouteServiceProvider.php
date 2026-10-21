<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';


    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // imports.v1 resumable uploads: session creation/verification and chunk appends
        // are limited separately so a long upload never starves the other draft routes.
        RateLimiter::for('import-upload-sessions', function (Request $request) {
            return Limit::perMinute(30)->by($this->importLimiterKey($request));
        });
        RateLimiter::for('import-upload-chunks', function (Request $request) {
            return Limit::perMinute(1200)->by($this->importLimiterKey($request));
        });

        // Both transcription routes share one per-user budget: each request can hold a
        // Whisper worker for up to TRANSCRIPTION_TIMEOUT_SECONDS.
        RateLimiter::for('transcriptions', function (Request $request) {
            return Limit::perMinute(max(1, (int) config('transcription.rate_limit_per_minute', 30)))
                ->by($this->importLimiterKey($request));
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    private function importLimiterKey(Request $request): string
    {
        $user = $request->user();

        return $user === null ? 'ip:' . $request->ip() : 'user:' . $user->getAuthIdentifier();
    }
}
