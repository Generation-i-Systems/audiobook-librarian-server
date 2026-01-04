<?php

use App\Http\Controllers\Api\ApiHealthController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\BadgeController;
use App\Http\Controllers\Api\BookApiController;
use App\Http\Controllers\Api\BookmarkApiController;
use App\Http\Controllers\Api\BookRequestApiController;
use App\Http\Controllers\Api\ExternalReadApiController;
use App\Http\Controllers\Api\FavoriteAuthorController;
use App\Http\Controllers\Api\FollowApiController;
use App\Http\Controllers\Api\MessageApiController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\ReadingProgressApiController;
use App\Http\Controllers\Api\ReadingStatsApiController;
use App\Http\Controllers\Api\SkinController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\ThemeController;
use App\Http\Controllers\Api\UserApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Health check endpoints (no authentication required - for uptime monitors)
    Route::get('/health/ping', [ApiHealthController::class, 'ping']);
    Route::get('/health', [ApiHealthController::class, 'health']);
    Route::get('/health/validate', [ApiHealthController::class, 'validateSpec']);

    Route::get('/books/{book}/cover', [BookApiController::class, 'cover']);

    // Public Skin Routes (no authentication required)
    Route::get('/skins', [SkinController::class, 'index']);
    Route::get('/skins/{id}', [SkinController::class, 'show']);
    Route::get('/skins/{id}/download', [SkinController::class, 'download']);

    // Public Theme Routes (no authentication required)
    Route::get('/themes', [ThemeController::class, 'index']);
    Route::get('/themes/{id}', [ThemeController::class, 'show']);
    Route::get('/themes/{id}/download', [ThemeController::class, 'download']);

    Route::middleware(['api.auth', 'standard'])->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        Route::get('/me', [UserApiController::class, 'me']);

        // Book Routes
        Route::get('/books', [BookApiController::class, 'index']);
        Route::get('/books/enhanced', [BookApiController::class, 'booksEnhanced']);
        Route::get('/books/{book}', [BookApiController::class, 'show']);
        Route::get('/books/{book}/download', [BookApiController::class, 'download']);
        Route::get('/books/{book}/download/{file}', [BookApiController::class, 'downloadFile'])
            ->name('api.books.downloadFile');
        Route::get('/books/{book}/download-url', [BookApiController::class, 'downloadUrl']);
        Route::get('/books/browse', [BookApiController::class, 'browse']);
        Route::get('/books/search', [BookApiController::class, 'search']);
        Route::post('/books/queue/download', [BookApiController::class, 'queueDownload']);
        Route::get('/books/queue/download/{zipId}', [BookApiController::class, 'downloadQueuedZip']);
        Route::post('/books/queue/download/{zipId}/mark-downloaded', [BookApiController::class, 'markZipDownloaded']);

        // Series Books Route
        Route::get('/series/{seriesId}/books', [BookApiController::class, 'booksBySeries']);

        // Series Routes - with author filtering and pagination
        Route::get('/series', [BookApiController::class, 'series']);
        // Series Autocomplete
        Route::get('/series/autocomplete', [BookApiController::class, 'autocompleteSeries']);
        // Authors Route - with genre filtering and pagination
        Route::get('/authors', [BookApiController::class, 'authors']);
        // Author Autocomplete
        Route::get('/authors/autocomplete', [BookApiController::class, 'autocompleteAuthors']);
        // Narrator Autocomplete
        Route::get('/narrators/autocomplete', [BookApiController::class, 'autocompleteNarrators']);

        // Author Books Route
        Route::get('/authors/{authorId}/books', [BookApiController::class, 'booksByAuthor']);
        // Author Books by Genre Route
        Route::get('/authors/{authorId}/genres/{genreId}/books', [BookApiController::class, 'booksByAuthorAndGenre']);

        // Author Series Route
        Route::get('/authors/{authorId}/series', [BookApiController::class, 'seriesByAuthor']);

        // Genre Routes
        Route::get('/genres', [BookApiController::class, 'listGenres']);
        Route::get('/genres/{genre}/authors', [BookApiController::class, 'authorsByGenre']);
        Route::get('/genres/{genreId}/authors', [BookApiController::class, 'authorsByGenreSimple']);

        // Book Request Route
        Route::post('/book-requests', [BookRequestApiController::class, 'store']);

        // Follow and Unfollow routes
        Route::post('/follow/{followableType}/{followableId}', [FollowApiController::class, 'follow']);
        Route::delete('/unfollow/{followableType}/{followableId}', [FollowApiController::class, 'unfollow']);

        // Reading Progress Routes
        Route::post('/reading-progress/reset', [ReadingProgressApiController::class, 'reset']);
        Route::post('/reading-progress/{book}', [ReadingProgressApiController::class, 'update']);
        Route::get('/reading-progress/{book}', [ReadingProgressApiController::class, 'get']);

        // OpenAPI spec bookmark routes
        Route::get('/bookmarks/{book}', [BookmarkApiController::class, 'getBookmarksOpenApi']);
        Route::post('/bookmarks/{book}', [BookmarkApiController::class, 'createBookmarkOpenApi']);

        // Legacy bookmark routes
        Route::get('/books/{book}/bookmarks', [BookmarkApiController::class, 'getBookmarks']);
        Route::post('/books/{book}/bookmarks', [BookmarkApiController::class, 'createBookmark']);
        Route::get('/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'getBookmark']);
        Route::put('/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'updateBookmark']);
        Route::patch('/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'updateBookmark']);
        Route::delete('/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'deleteBookmark']);

        // External/Previously Read Routes
        Route::get('/books/{book}/external-reads', [ExternalReadApiController::class, 'getExternalReads']);
        Route::post('/books/{book}/external-reads', [ExternalReadApiController::class, 'createExternalRead']);
        Route::get('/books/{book}/external-reads/{externalRead}', [
            ExternalReadApiController::class,
            'getExternalRead',
        ]);
        Route::put('/books/{book}/external-reads/{externalRead}', [
            ExternalReadApiController::class,
            'updateExternalRead',
        ]);
        Route::patch('/books/{book}/external-reads/{externalRead}', [
            ExternalReadApiController::class,
            'updateExternalRead',
        ]);
        Route::delete('/books/{book}/external-reads/{externalRead}', [
            ExternalReadApiController::class,
            'deleteExternalRead',
        ]);

        // Reading Stats Routes
        Route::post('/books/{book}/reading-stats/sessions', [ReadingStatsApiController::class, 'recordSession']);
        Route::get('/reading-stats/daily', [ReadingStatsApiController::class, 'getDaily']);
        Route::get('/books/{book}/reading-stats', [ReadingStatsApiController::class, 'getBookStats']);
        Route::get('/reading-stats/user', [ReadingStatsApiController::class, 'getUserStats']);
        Route::get('/reading-stats/streaks', [ReadingStatsApiController::class, 'getStreaks']);

        // OpenAPI spec progress routes - specific routes must come before dynamic ones
        Route::get('/progress/device', [ProgressController::class, 'getDeviceProgress']);
        Route::get('/progress', [ProgressController::class, 'getAllProgress']);
        Route::get('/progress/{book}', [ProgressController::class, 'getBookProgress']);
        Route::put('/progress/{book}', [ProgressController::class, 'updateBookProgress']);

        // Book Progress Routes (cross-device listening continuity) - legacy routes
        Route::get('/books/{book}/progress', [ProgressController::class, 'getProgress']);
        Route::put('/books/{book}/progress', [ProgressController::class, 'updateProgress']);
        Route::post('/books/{book}/progress/complete', [ProgressController::class, 'markCompleted']);
        Route::delete('/books/{book}/progress', [ProgressController::class, 'resetProgress']);

        // OpenAPI spec statistics routes
        Route::get('/statistics/overview', [StatisticsController::class, 'getOverview']);
        Route::get('/statistics/daily', [StatisticsController::class, 'getDailyStatsOpenApi']);
        Route::post('/statistics/report', [StatisticsController::class, 'reportSession']);

        // Legacy statistics routes
        Route::post('/statistics/sessions', [StatisticsController::class, 'recordSession']);
        Route::get('/statistics/legacy-daily', [StatisticsController::class, 'getDailyStats']);
        Route::get('/statistics/weekly', [StatisticsController::class, 'getWeeklyStats']);
        Route::get('/statistics/trends', [StatisticsController::class, 'getListeningTrends']);
        Route::get('/statistics/top-books', [StatisticsController::class, 'getTopBooks']);
        Route::get('/statistics/dashboard', [StatisticsController::class, 'getDashboardStats']);
        Route::get('/books/{book}/statistics', [StatisticsController::class, 'getBookStats']);

        // Badge routes
        Route::prefix('badges')->group(function () {
            // Core badge endpoints
            Route::get('/', [BadgeController::class, 'index']);
            Route::get('/user', [BadgeController::class, 'userBadges']);
            Route::get('/stats', [BadgeController::class, 'userStats']);
            Route::get('/categories', [BadgeController::class, 'byCategory']);
            Route::get('/progress', [BadgeController::class, 'progress']);
            Route::get('/unnotified', [BadgeController::class, 'unnotified']);
            Route::post('/mark-notified', [BadgeController::class, 'markNotified']);
            Route::get('/leaderboard', [BadgeController::class, 'leaderboard']);
        });

        // Favorite Authors routes
        Route::get('/favorites/new-books', [FavoriteAuthorController::class, 'getNewBooks']);
        Route::get('/favorites', [FavoriteAuthorController::class, 'index'])->name('api.favorites.index');
        Route::post('/favorites', [FavoriteAuthorController::class, 'store'])->name('api.favorites.store');
        Route::get('/favorites/{favorite}', [FavoriteAuthorController::class, 'show'])->name('api.favorites.show');
        Route::put('/favorites/{favorite}', [FavoriteAuthorController::class, 'update'])->name('api.favorites.update');
        Route::delete('/favorites/{favorite}', [FavoriteAuthorController::class, 'destroy'])
            ->name('api.favorites.destroy');

        // Skin Routes (authenticated)
        Route::post('/skins/upload', [SkinController::class, 'store']);
        Route::post('/skins/{id}/rate', [SkinController::class, 'rate']);
        Route::get('/skins/my-skins', [SkinController::class, 'mySkins']);
        Route::post('/skins/{id}/fork', [SkinController::class, 'fork']);
        Route::patch('/skins/{id}', [SkinController::class, 'update']);
        Route::delete('/skins/{id}', [SkinController::class, 'destroy']);

        // Theme Routes (authenticated)
        Route::post('/themes/upload', [ThemeController::class, 'store']);
        Route::post('/themes/{id}/rate', [ThemeController::class, 'rate']);
        Route::get('/themes/my-themes', [ThemeController::class, 'myThemes']);
        Route::post('/themes/{id}/fork', [ThemeController::class, 'fork']);
        Route::patch('/themes/{id}', [ThemeController::class, 'update']);
        Route::delete('/themes/{id}', [ThemeController::class, 'destroy']);

        // Message Route
        Route::post('/messages', [MessageApiController::class, 'store']);

        // Logout
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Authentication Routes (outside the auth:sanctum middleware)
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
    Route::post('/auth/google', [AuthController::class, 'googleLogin']);
});
