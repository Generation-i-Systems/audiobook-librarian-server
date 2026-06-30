<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiHealthController;
use App\Http\Controllers\Api\ApiRootController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthorController;
use App\Http\Controllers\Api\BadgeController;
use App\Http\Controllers\Api\BookApiController;
use App\Http\Controllers\Api\BookAuthorController;
use App\Http\Controllers\Api\BookContributionController;
use App\Http\Controllers\Api\BookCoverController;
use App\Http\Controllers\Api\BookDownloadController;
use App\Http\Controllers\Api\BookImportApiController;
use App\Http\Controllers\Api\BookTagController;
use App\Http\Controllers\Api\BookSeriesGenreController;
use App\Http\Controllers\Api\BookmarkApiController;
use App\Http\Controllers\Api\BookmarkSyncController;
use App\Http\Controllers\Api\BookMatchController;
use App\Http\Controllers\Api\BookRequestApiController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ExternalReadApiController;
use App\Http\Controllers\Api\FollowApiController;
use App\Http\Controllers\Api\MessageApiController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\EmailOtpController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PositionSyncController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\ReadingProgressApiController;
use App\Http\Controllers\Api\ReadingStatsApiController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\SeriesController;
use App\Http\Controllers\Api\SkinController;
use App\Http\Controllers\Api\ListeningGoalController;
use App\Http\Controllers\Api\PlaylistController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\ThemeController;
use App\Http\Controllers\Api\UserApiController;
use App\Http\Controllers\Api\UserStatusController;
use App\Http\Controllers\Api\FeedbackController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // API Root
    Route::get('/', [ApiRootController::class, 'index'])->name('api.v1.root');

    // Health check endpoints (no authentication required - for uptime monitors)
    Route::get('/health/ping', [ApiHealthController::class, 'ping']);
    Route::get('/health', [ApiHealthController::class, 'health']);
    Route::get('/health/validate', [ApiHealthController::class, 'validateSpec']);
    Route::get('/health/capabilities', [ApiHealthController::class, 'capabilities']);

    Route::get('/openapi.json', function () {
        $path = base_path('docs/openapi.json');
        if (! file_exists($path)) {
            return response()->json(['error' => 'OpenAPI spec not found'], 404);
        }
        return response()->file($path, ['Content-Type' => 'application/json']);
    })->name('api.v1.openapi');

    Route::get('/books/{book}/cover', [BookCoverController::class, 'cover']);

    // Public Skin Routes (no authentication required)
    Route::get('/skins', [SkinController::class, 'index'])->name('api.v1.skins.index');
    Route::get('/skins/{id}', [SkinController::class, 'show'])->whereNumber('id')->name('api.v1.skins.show');
    Route::get('/skins/{id}/download', [SkinController::class, 'download'])->whereNumber('id')->name('api.v1.skins.download');
    Route::get('/skins/{id}/customizations', [SkinController::class, 'getCustomizations'])->whereNumber('id')->name('api.v1.skins.customizations');

    // Public Theme Routes (no authentication required)
    Route::get('/themes', [ThemeController::class, 'index'])->name('api.v1.themes.index');
    Route::get('/themes/{id}', [ThemeController::class, 'show'])->whereNumber('id')->name('api.v1.themes.show');
    Route::get('/themes/{id}/download', [ThemeController::class, 'download'])->whereNumber('id')->name('api.v1.themes.download');

    Route::middleware(['api.auth', 'standard'])->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        Route::get('/me', [UserApiController::class, 'me']);

        // Status and Queue Routes (New unified system)
        Route::prefix('status')->group(function () {
            Route::get('/list/{statusType}', [UserStatusController::class, 'list']);
            Route::get('/history', [UserStatusController::class, 'history']);
            Route::get('/goals', [UserStatusController::class, 'goals']);
            Route::post('/{book}/set', [UserStatusController::class, 'set']);
            Route::post('/non-library/set', [UserStatusController::class, 'setNonLibrary']);
            Route::post('/queue/reorder', [UserStatusController::class, 'reorder']);
        });

        // Playlist Routes
        Route::prefix('playlists')->group(function () {
            Route::get('/', [PlaylistController::class, 'index']);
            Route::post('/', [PlaylistController::class, 'store']);
            Route::get('/{playlist}', [PlaylistController::class, 'show']);
            Route::put('/{playlist}', [PlaylistController::class, 'update']);
            Route::delete('/{playlist}', [PlaylistController::class, 'destroy']);
            Route::post('/{playlist}/books', [PlaylistController::class, 'addBook']);
            Route::delete('/{playlist}/books/{bookId}', [PlaylistController::class, 'removeBook']);
            Route::post('/{playlist}/reorder', [PlaylistController::class, 'reorder']);
        });

        // Listening Goals Routes
        Route::prefix('goals/listening')->group(function () {
            Route::get('/', [ListeningGoalController::class, 'index']);
            Route::post('/', [ListeningGoalController::class, 'store']);
            Route::put('/{goal}', [ListeningGoalController::class, 'update']);
            Route::delete('/{goal}', [ListeningGoalController::class, 'destroy']);
        });

        // Sync Routes
        Route::prefix('sync')->middleware('idempotency')->group(function () {
            Route::post('/progress', [\App\Http\Controllers\Api\SyncController::class, 'progress']);
        });

        // Device Management Routes
        Route::middleware('device.identify')->group(function () {
            Route::get('/devices', [DeviceController::class, 'index']);
            Route::put('/devices/{deviceId}', [DeviceController::class, 'update']);
            Route::delete('/devices/{deviceId}', [DeviceController::class, 'destroy']);
            Route::put('/devices/{deviceId}/sync-enabled', [DeviceController::class, 'updateSyncEnabled']);

            // Position Sync Routes
            Route::prefix('sync')->group(function () {
                Route::get('/positions', [PositionSyncController::class, 'index']);
                Route::get('/positions/{bookId}', [PositionSyncController::class, 'show']);
                Route::post('/positions', [PositionSyncController::class, 'store'])->middleware('idempotency');

                // Bookmark Sync Routes
                Route::get('/bookmarks', [BookmarkSyncController::class, 'index']);
                Route::get('/bookmarks/{bookId}', [BookmarkSyncController::class, 'show']);
                Route::post('/bookmarks', [BookmarkSyncController::class, 'store'])->middleware('idempotency');
                Route::delete('/bookmarks/{stringId}', [BookmarkSyncController::class, 'destroy']);

                // Event Sync Routes
                Route::post('/events', [EventController::class, 'sync'])->middleware('idempotency');
                Route::get('/events/book/{bookId}', [EventController::class, 'getBookEvents']);
                Route::get('/events/stats', [EventController::class, 'getStats']);
            });
        });

        // Book Match Routes
        Route::prefix('books/match')->group(function () {
            Route::post('/search', [BookMatchController::class, 'search']);
            Route::get('/{bookId}/details', [BookMatchController::class, 'details']);
            Route::post('/reassign-events', [BookMatchController::class, 'reassignEvents']);
        });

        // Book Routes
        Route::get('/books', [BookApiController::class, 'index']);
        Route::post('/books/batch', [BookApiController::class, 'batch']);
        Route::get('/books/browse', [BookApiController::class, 'browse']);
        Route::get('/books/search', [BookApiController::class, 'search']);
        Route::post('/books/queue/download', [BookDownloadController::class, 'queueDownload']);
        Route::get('/books/queue/download/{zipId}', [BookDownloadController::class, 'downloadQueuedZip']);
        Route::post('/books/queue/download/{zipId}/mark-downloaded', [BookDownloadController::class, 'markZipDownloaded']);
        Route::get('/books/{book}', [BookApiController::class, 'show']);
        Route::get('/books/{book}/tags', [BookTagController::class, 'show']);
        Route::put('/books/{book}/tags', [BookTagController::class, 'update']);
        Route::get('/books/{book}/download', [BookDownloadController::class, 'download']);
        Route::get('/books/{book}/download/{file}', [BookDownloadController::class, 'downloadFile'])
            ->where('file', '.*')
            ->name('api.books.downloadFile');
        Route::get('/books/{book}/download-url', [BookDownloadController::class, 'downloadUrl']);
        Route::get('/download/remote', [BookDownloadController::class, 'remoteDownload'])->name('api.download.remote');

        // Series Books Route
        Route::get('/series/{seriesId}/books', [BookSeriesGenreController::class, 'booksBySeries']);

        // Series Routes - with author filtering and pagination
        Route::get('/series', [BookSeriesGenreController::class, 'series']);
        // Series Autocomplete
        Route::get('/series/autocomplete', [BookSeriesGenreController::class, 'autocompleteSeries']);

        // Series search and management (moved up to avoid conflict with {seriesId})
        Route::get('/series/search', [BookImportApiController::class, 'searchSeries']);
        Route::post('/series', [BookImportApiController::class, 'createSeries']);

        Route::get('/series/{seriesId}', [BookSeriesGenreController::class, 'seriesDetails']);
        // Toggle Series Favorite
        Route::post('/series/{series}/favorite', [SeriesController::class, 'toggleFavorite']);

        // Authors Route - with genre filtering and pagination
        Route::get('/authors', [BookAuthorController::class, 'authors']);
        // Author Autocomplete
        Route::get('/authors/autocomplete', [BookAuthorController::class, 'autocompleteAuthors']);

        // Author search and management (moved up to avoid conflict with {authorId})
        Route::get('/authors/search', [BookImportApiController::class, 'searchAuthors']);
        Route::post('/authors', [BookImportApiController::class, 'createAuthor']);

        Route::get('/authors/{authorId}', [BookAuthorController::class, 'authorDetails']);
        // Toggle Author Favorite
        Route::post('/authors/{author}/favorite', [AuthorController::class, 'toggleFavorite']);
        // Narrator Autocomplete
        Route::get('/narrators/autocomplete', [BookSeriesGenreController::class, 'autocompleteNarrators']);

        // Author Books Route
        Route::get('/authors/{authorId}/books', [BookAuthorController::class, 'booksByAuthor']);
        // Author Books by Genre Route
        Route::get('/authors/{authorId}/genres/{genreId}/books', [BookAuthorController::class, 'booksByAuthorAndGenre']);

        // Author Series Route
        Route::get('/authors/{authorId}/series', [BookAuthorController::class, 'seriesByAuthor']);

        // Genre Routes
        Route::get('/genres', [BookSeriesGenreController::class, 'listGenres']);
        Route::get('/genres/{genre}/authors', [BookSeriesGenreController::class, 'authorsByGenre']);
        Route::get('/genres/{genreId}/authors', [BookSeriesGenreController::class, 'authorsByGenreSimple']);

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
        // Client compatibility bookmark route (delete by ID)
        Route::delete('/bookmarks/{bookmark}', [BookmarkApiController::class, 'deleteBookmarkById']);

        // Legacy bookmark routes
        Route::get('/books/{book}/bookmarks', [BookmarkApiController::class, 'getBookmarks']);
        Route::post('/books/{book}/bookmarks', [BookmarkApiController::class, 'createBookmark']);
        Route::get('/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'getBookmark']);
        Route::put('/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'updateBookmark']);
        Route::patch('/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'updateBookmark']);
        Route::delete('/books/{book}/bookmarks/{bookmark}', [BookmarkApiController::class, 'deleteBookmark']);

        // External/Previously Read Routes
        Route::get('/books/{book}/external-reads', [ExternalReadApiController::class, 'getExternalReads']);
        Route::post('/external-reads', [ExternalReadApiController::class, 'createExternalReadNonLibrary']);
        Route::post('/books/{book}/external-reads', [ExternalReadApiController::class, 'createExternalRead']);
        Route::get('/books/{book}/external-reads/{externalRead}', [ExternalReadApiController::class, 'getExternalRead']);
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
        Route::get('/progress/device/{deviceId}', [ProgressController::class, 'getDeviceProgressByPath']); // Client compatibility
        Route::get('/progress', [ProgressController::class, 'getAllProgress']);
        Route::post('/progress/{book}/mark-completed', [ProgressController::class, 'markCompletedByPath']); // Client compatibility
        Route::get('/progress/{book}', [ProgressController::class, 'getBookProgress']);
        Route::put('/progress/{book}', [ProgressController::class, 'updateBookProgress'])->middleware('idempotency');

        // Book Progress Routes (cross-device listening continuity) - legacy routes
        Route::get('/books/{book}/progress', [ProgressController::class, 'getProgress']);
        Route::put('/books/{book}/progress', [ProgressController::class, 'updateProgress']);
        Route::post('/books/{book}/progress/complete', [ProgressController::class, 'markCompleted']);
        Route::delete('/books/{book}/progress', [ProgressController::class, 'resetProgress']);

        // OpenAPI spec statistics routes
        Route::get('/statistics/overview', [StatisticsController::class, 'getOverview']);
        Route::get('/statistics/daily', [StatisticsController::class, 'getDailyStatsOpenApi']);
        Route::get('/statistics/timeline', [StatisticsController::class, 'getTimelineStats']);
        Route::get('/statistics/timeline/day', [StatisticsController::class, 'getDayTimeline']);
        Route::get('/statistics/reading-history', [StatisticsController::class, 'getReadingHistoryStats']);
        Route::get('/statistics/diagnostics', [StatisticsController::class, 'getDiagnostics']);
        Route::post('/statistics/report', [StatisticsController::class, 'reportSession']);

        // Legacy statistics routes
        Route::post('/statistics/sessions', [StatisticsController::class, 'recordSession']);
        Route::get('/statistics/legacy-daily', [StatisticsController::class, 'getDailyStats']);
        Route::get('/statistics/weekly', [StatisticsController::class, 'getWeeklyStats']);
        Route::get('/statistics/trends', [StatisticsController::class, 'getListeningTrends']);
        Route::get('/statistics/top-books', [StatisticsController::class, 'getTopBooks']);
        Route::get('/statistics/dashboard', [StatisticsController::class, 'getDashboardStats']);
        Route::get('/books/{book}/statistics', [StatisticsController::class, 'getBookStats']);

        // Recommendation Routes
        Route::prefix('recommendations')->group(function () {
            Route::post('/{book}', [RecommendationController::class, 'send']);
            Route::get('/inbox', [RecommendationController::class, 'inbox']);
            Route::post('/{recommendation}/acknowledge', [RecommendationController::class, 'acknowledge']);
        });

        Route::post('/books/{book}/recommend', [RecommendationController::class, 'send']);

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

        // Analytics Routes
        Route::post('/analytics/event', [AnalyticsController::class, 'recordEvent']);

        // Skin Routes (authenticated)
        Route::post('/skins/upload', [SkinController::class, 'store']);
        Route::post('/skins/{id}/customizations', [SkinController::class, 'uploadCustomization']);
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

        // Message Routes
        Route::get('/messages', [MessageApiController::class, 'index']);
        Route::post('/messages', [MessageApiController::class, 'store']);
        Route::post('/messages/{id}/acknowledge', [MessageApiController::class, 'acknowledge']);

        // Import Support Routes (for NativePHP desktop app)
        Route::prefix('imports')->group(function () {
            Route::post('/check-duplicate', [BookImportApiController::class, 'checkDuplicate']);
            Route::get('/queue/stats', [BookImportApiController::class, 'getQueueStats']);
            Route::get('/queue/pending', [BookImportApiController::class, 'getPendingImports']);
            Route::get('/{importId}/status', [BookImportApiController::class, 'getImportStatus']);
            Route::get('/genres', [BookImportApiController::class, 'getGenres']);
        });

        // Book Contribution Routes
        Route::post('/books/{book}/contributions', [BookContributionController::class, 'store']);
        Route::get('/contributions', [BookContributionController::class, 'mine']);
        Route::delete('/contributions/{contribution}', [BookContributionController::class, 'destroy']);

        // Admin: Contribution review routes
        Route::middleware('admin')->prefix('admin/contributions')->group(function () {
            Route::get('/', [BookContributionController::class, 'pending']);
            Route::post('/{contribution}/approve', [BookContributionController::class, 'approve']);
            Route::post('/{contribution}/reject', [BookContributionController::class, 'reject']);
        });

        // Admin: User management routes
        Route::middleware('admin')->prefix('admin/users')->group(function () {
            Route::get('/', [AdminUserController::class, 'index']);
            Route::post('/', [AdminUserController::class, 'store']);
            Route::post('/{id}/send-otp', [AdminUserController::class, 'sendOtp']);
        });

        // Authenticated: set initial password (used after OTP login when must_change_password=true)
        Route::post('/auth/set-initial-password', [EmailOtpController::class, 'setInitialPassword']);

        // Feedback / Bug Reports
        Route::post('/feedback', [FeedbackController::class, 'submit']);

        // Logout
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
    });

    // Authentication Routes (outside the auth:sanctum middleware)
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/check-status', [AuthController::class, 'checkStatus']);
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
        Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
        Route::post('/auth/otp/request', [EmailOtpController::class, 'request']);
        Route::post('/auth/otp/verify', [EmailOtpController::class, 'verify']);
        Route::post('/auth/google', [AuthController::class, 'googleLogin']);
        Route::post('/auth/facebook', [AuthController::class, 'facebookLogin']);
        Route::post('/auth/apple', [AuthController::class, 'appleLogin']);
        Route::post('/auth/discord', [AuthController::class, 'discordLogin']);
    });

    // Backwards compatible auth-prefixed routes (mobile clients expect /auth/* paths)
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/check-status', [AuthController::class, 'checkStatus']);
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
        Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
        Route::post('/otp/request', [EmailOtpController::class, 'request']);
        Route::post('/otp/verify', [EmailOtpController::class, 'verify']);
        Route::post('/google', [AuthController::class, 'googleLogin']);
        Route::post('/facebook', [AuthController::class, 'facebookLogin']);
        Route::post('/apple', [AuthController::class, 'appleLogin']);
        Route::post('/discord', [AuthController::class, 'discordLogin']);
    });
});
