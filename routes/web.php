<?php

declare(strict_types=1);

use App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\BookAutocompleteController;
use App\Http\Controllers\Admin\BookCoverAdminController;
use App\Http\Controllers\Admin\BookExportController;
use App\Http\Controllers\Admin\BookFormController;
use App\Http\Controllers\Admin\BookImportController;
use App\Http\Controllers\Admin\BookJsonController;
use App\Http\Controllers\Admin\BookMetadataSearchController;
use App\Http\Controllers\Admin\BookPathController;
use App\Http\Controllers\Admin\BookSeriesController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\ImageProxyController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReadingProgressController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\BuiltinSkinController;
use App\Http\Controllers\SkinWebController;
use App\Http\Controllers\ThemeWebController;
use App\Http\Controllers\UserLibraryController;
use App\Http\Controllers\Api\EmailOtpController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Magic link OTP web routes (no auth required — these ARE the auth mechanism)
Route::get('/auth/magic/{token}', [EmailOtpController::class, 'magicLanding'])
    ->where('token', '[a-f0-9]{64}')
    ->name('auth.magic.landing');
Route::post('/auth/magic/{token}/continue', [EmailOtpController::class, 'magicContinue'])
    ->where('token', '[a-f0-9]{64}')
    ->middleware('web')
    ->name('auth.magic.continue');
Route::post('/auth/otp/request', [EmailOtpController::class, 'request'])
    ->name('auth.otp.request');

// --- EMERGENCY ROUTES ---
// Emergency book routes that bypass memory-intensive models
Route::get('/emergency/books', [App\Http\Controllers\EmergencyBookController::class, 'index'])
    ->name('emergency.books.index');

// --- DEBUG ROUTES (explicit local opt-in only) ---
if (app()->environment('local') && config('app.enable_debug_routes')) {
    Route::middleware(['auth', 'admin'])->group(function (): void {
        Route::get('/test-memory', [App\Http\Controllers\TestController::class, 'memoryTest']);

        Route::get('/debug/middleware', [Admin\DebugController::class, 'debugMiddleware']);
        Route::get('/debug/auth', [Admin\DebugController::class, 'auth']);
        Route::get('/debug/session', [Admin\DebugController::class, 'session']);
        Route::get('/debug/sessiondb', [Admin\DebugController::class, 'sessiondb']);
        Route::get('/debug/document/{collection}/{docId}', [Admin\DebugController::class, 'showDocument']);

        Route::get('/debug/logout', [Admin\DebugController::class, 'logout']);
        Route::get('/debug/session-write', [Admin\DebugController::class, 'sessionWrite']);

        Route::get('/debug/users-dump', [Admin\DebugController::class, 'usersDump']);
        Route::get('/debug/books-dump', [Admin\DebugController::class, 'booksDump']);
    });
}

Route::get('/', function () {
    if (Auth::check()) {
        if (config('library_profiles.active_source_mode') === 'librivox') {
            return redirect()->route('admin.librivox.index');
        }

        $role = Auth::user()->role ?? '';
        $libraryRoles = ['library-user', 'librivox-user', 'hybrid-user'];
        if (in_array($role, $libraryRoles, true)) {
            return redirect()->route('books.index')->with('status', 'Welcome to Audiobook Librarian!');
        }

        if (Auth::user()->is_admin) {
            return redirect()->route('admin.books.index')->with('status', 'Welcome to Audiobook Librarian!');
        }

        return redirect()->route('books.index')->with('status', 'Welcome to Audiobook Librarian!');
    }

    return view('welcome');
});

Auth::routes();

Route::get('/privacy', fn () => view('privacy'))->name('privacy');
Route::get('/terms', fn () => view('terms'))->name('terms');

Route::get('/password/reset/success', function () {
    return view('auth.password-reset-success');
})->name('password.reset.success');

// Google Sign-In
Route::get(
    'login/google',
    [App\Http\Controllers\Auth\LoginController::class, 'redirectToGoogle']
)->name('login.google');
Route::get(
    'login/google/callback',
    [App\Http\Controllers\Auth\LoginController::class, 'handleGoogleCallback']
);

Route::get('/home', fn () => redirect()
    ->route('books.index')
    ->with('status', 'Welcome to Audiobook Librarian!'))
    ->name('home');

// Documentation routes (public access)
Route::prefix('docs')->group(function (): void {
    Route::get('/', [App\Http\Controllers\DocsController::class, 'index'])->name('docs.index');
    Route::get('/openapi.json', [App\Http\Controllers\DocsController::class, 'openapi'])->name('docs.openapi');
    Route::get('/{path?}', [App\Http\Controllers\DocsController::class, 'show'])
        ->where('path', '.*')
        ->name('docs.show');
});

// API docs routes (aliases)
Route::prefix('api-docs')->group(function (): void {
    Route::get('/openapi.json', [App\Http\Controllers\DocsController::class, 'openapi'])->name('api-docs.openapi');
});

Route::middleware(['auth'])->group(function (): void {
    // User Library & Social Routes
    Route::name('my-library.')->prefix('my-library')->group(function (): void {
        Route::get('/queue', [UserLibraryController::class, 'queue'])->name('queue');
        Route::get('/wishlist', [UserLibraryController::class, 'wishlist'])->name('wishlist');
        Route::get('/recommendations', [UserLibraryController::class, 'recommendations'])->name('recommendations');
        Route::get('/history', [UserLibraryController::class, 'history'])->name('history');
        Route::get('/goals', [UserLibraryController::class, 'goals'])->name('goals');
    });

    Route::resource('books', BookController::class)->only(['index', 'show'])->middleware('library');
    Route::get('/books/create', [
        \App\Http\Controllers\Admin\BookController::class,
        'showCreateForm',
    ])->name('books.create');
    Route::get('/books/{book}/download', [BookController::class, 'download'])->name('books.download');
    Route::get('/books/{book}/play', [\App\Http\Controllers\PlayerController::class, 'show'])->name('books.play');
    Route::post('/books/{book}/reviews', [ReviewController::class, 'store'])->name('reviews.store');
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');

    // Favorite Authors routes
    Route::get('/favorites', [\App\Http\Controllers\FavoriteAuthorWebController::class, 'index'])
        ->name('favorites.index');
    Route::post('/favorites', [\App\Http\Controllers\FavoriteAuthorWebController::class, 'store'])
        ->name('favorites.store');
    Route::delete('/favorites/{favorite}', [\App\Http\Controllers\FavoriteAuthorWebController::class, 'destroy'])
        ->name('favorites.destroy');
    Route::patch(
        '/favorites/{favorite}/toggle-notifications',
        [\App\Http\Controllers\FavoriteAuthorWebController::class, 'toggleNotifications']
    )->name('favorites.toggle-notifications');
    Route::post(
        '/follow/{followableType}/{followableId}',
        [FollowController::class, 'follow']
    )->name('follow');
    Route::delete(
        '/unfollow/{followableType}/{followableId}',
        [FollowController::class, 'unfollow']
    )->name('unfollow');
    Route::post(
        '/reading-progress/{book}',
        [ReadingProgressController::class, 'update']
    )->name('reading_progress.update');
    Route::get(
        '/reading-progress/{book}',
        [ReadingProgressController::class, 'get']
    )->name('reading_progress.get');

    // Profile routes (require authentication)
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/change-password', [ProfileController::class, 'changePassword'])
        ->name('profile.changePassword');
    Route::post('/profile/request-admin', [ProfileController::class, 'requestAdminPermissions'])
        ->name('profile.requestAdminPermissions');
});

Route::post('/messages', [MessageController::class, 'store'])->name('messages.store');

// CSRF token refresh endpoint
Route::get('/csrf-token', fn () => response()->json(['csrf_token' => csrf_token()]))->name('csrf.token');

// Regular book routes (handled by the auth middleware group above)

// JSON API endpoints for AJAX requests
Route::middleware(['auth', 'library'])->group(function (): void {
    Route::get('/api/books/json', [BookController::class, 'jsonIndex'])->name('api.books.json');
    Route::get('/api/books/recent/json', [BookController::class, 'jsonRecent'])->name('api.books.recent.json');
});
Route::post('/books/set-preference', [BookController::class, 'setPreference'])->name('books.set-preference');

// General image proxy for covers and previews
Route::get('/image-proxy', [ImageProxyController::class, 'show'])->name('image.proxy');
// Pretty URL for covers, supports slashes in path
Route::get('/cover/{path}', [
    ImageProxyController::class,
    'cover',
])->where('path', '.*')->name('cover.proxy');

Route::get('/google-books-cover/{encodedUrl}', [
    ImageProxyController::class,
    'googleBooksCover',
])->where('encodedUrl', '.+')->name('google.books.cover.proxy');

// Skin asset proxy - serves assets from configured skin paths
Route::get('/skin-asset/{skinId}/{path}', [
    \App\Http\Controllers\SkinAssetController::class,
    'show',
])->where('path', '.*')->name('skin.asset.proxy');

// Admin series autocomplete endpoint for book form (accessible to admin users)
Route::get('/admin/series-autocomplete', [BookAutocompleteController::class, 'autocompleteSeries'])
    ->name('admin.series.autocomplete')
    ->middleware(['auth', 'admin']);



Route::name('admin.')->prefix('admin')->middleware(['auth', 'admin'])->group(function (): void {
    // Admin Social Activity Dashboard
    Route::get('/social-activity', [Admin\SocialController::class, 'index'])->name('social.index');

    Route::any('/adminer/{any?}', [Admin\AdminerController::class, 'handle'])->where('any', '.*')->name('adminer');
    // NEW ROUTE FOR DATABASE ADMIN PAGE
    Route::get('/database', [Admin\AdminerController::class, 'index'])->name('database');
    Route::get('/', fn () => redirect()->route('admin.books.index'));
    // Library repair + needs review dashboards
    Route::get('/needs-review', [Admin\NeedsReviewController::class, 'index'])
        ->name('needs_review.index');
    Route::get('/library-repair', [Admin\LibraryRepairController::class, 'index'])
        ->name('library-repair.index');
    Route::post('/library-repair/{issue}/resolve', [Admin\LibraryRepairController::class, 'resolve'])
        ->name('library-repair.resolve');
    Route::post('/library-repair/{issue}/rescan', [Admin\LibraryRepairController::class, 'rescan'])
        ->name('library-repair.rescan');
    Route::post(
        '/library-repair/{issue}/import-missing',
        [Admin\LibraryRepairController::class, 'importMissingDirectory']
    )->name('library-repair.import-missing');
    Route::post('/library-repair/refresh', [Admin\LibraryRepairController::class, 'refresh'])
        ->name('library-repair.refresh');
    Route::get('/library-repair/{issue}/compare', [Admin\LibraryRepairController::class, 'compare'])
        ->name('library-repair.compare');
    Route::post('/library-repair/{issue}/resolve-duplicate', [Admin\LibraryRepairController::class, 'resolveDuplicate'])
        ->name('library-repair.resolve-duplicate');
    Route::post('/library-repair/{issue}/split-duplicate', [Admin\LibraryRepairController::class, 'splitDuplicate'])
        ->name('library-repair.split-duplicate');
    Route::patch('/library-repair/books/{book}/field', [Admin\LibraryRepairController::class, 'updateBookField'])
        ->name('library-repair.update-book-field');
    Route::post('/books/resync-from-path', [BookFormController::class, 'resyncFromPath'])
        ->name('books.resyncFromPath');

    // AI Query routes (SQL-based)
    Route::post('/ai-query/process', [Admin\AIQueryController::class, 'process'])
        ->name('ai-query.process');
    Route::get('/ai-query/results/{queryId}', [Admin\AIQueryController::class, 'results'])
        ->name('ai-query.results');
    Route::post('/ai-query/apply-bulk-update', [Admin\AIQueryController::class, 'applyBulkUpdate'])
        ->name('ai-query.apply-bulk-update');
    Route::post('/ai-query/execute-custom', [Admin\AIQueryController::class, 'executeCustom'])
        ->name('ai-query.execute-custom');
    Route::post('/ai-query/edit-prompt', [Admin\AIQueryController::class, 'editPrompt'])
        ->name('ai-query.edit-prompt');
    Route::post('/ai-query/refine-item', [Admin\AIQueryController::class, 'refineItem'])
        ->name('ai-query.refine-item');

    // AI Query routes (Tool-based - new flexible system)
    Route::post('/ai-query/tools/process', [Admin\AIQueryController::class, 'processWithTools'])
        ->name('ai-query.tools.process');
    Route::get('/ai-query/tools/history', [Admin\AIQueryController::class, 'toolQueryHistory'])
        ->name('ai-query.tools.history');
    Route::get('/ai-query/tools/{queryId}', [Admin\AIQueryController::class, 'toolQueryDetails'])
        ->name('ai-query.tools.details');

    // AI Assistant routes (New conversational book management system)
    Route::get('/ai-assistant', [Admin\AIAssistantController::class, 'index'])
        ->name('ai-assistant.index');
    Route::post('/ai-assistant/process', [Admin\AIAssistantController::class, 'process'])
        ->name('ai-assistant.process');
    Route::get('/ai-assistant/session/{sessionId}', [Admin\AIAssistantController::class, 'session'])
        ->name('ai-assistant.session');
    Route::post('/ai-assistant/session/{sessionId}/execute', [Admin\AIAssistantController::class, 'execute'])
        ->name('ai-assistant.execute');
    Route::post('/ai-assistant/session/{sessionId}/refine', [Admin\AIAssistantController::class, 'refine'])
        ->name('ai-assistant.refine');
    Route::post('/ai-assistant/session/{sessionId}/cancel', [Admin\AIAssistantController::class, 'cancel'])
        ->name('ai-assistant.cancel');
    Route::get('/ai-assistant/history', [Admin\AIAssistantController::class, 'history'])
        ->name('ai-assistant.history');
    Route::get('/ai-assistant/stats', [Admin\AIAssistantController::class, 'stats'])
        ->name('ai-assistant.stats');

    // Directory validation routes
    Route::get('/directory-validation', [Admin\DirectoryValidationController::class, 'index'])
        ->name('directory-validation');
    Route::post('/directory-validation/rescan', [Admin\DirectoryValidationController::class, 'rescan'])
        ->name('directory-validation.rescan');
    Route::post('/directory-validation/rename', [Admin\DirectoryValidationController::class, 'renameDirectory'])
        ->name('directory-validation.rename');
    Route::delete('/directory-validation/delete-book', [Admin\DirectoryValidationController::class, 'deleteBook'])
        ->name('directory-validation.delete-book');
    Route::post('/directory-validation/import', [Admin\DirectoryValidationController::class, 'importOrphanedDirectory'])
        ->name('directory-validation.import');
    Route::delete(
        '/directory-validation/delete-orphan',
        [Admin\DirectoryValidationController::class, 'deleteOrphanedDirectory']
    )->name('directory-validation.delete-orphan');
    Route::post(
        '/directory-validation/rename-orphan',
        [Admin\DirectoryValidationController::class, 'renameOrphanedDirectory']
    )->name('directory-validation.rename-orphan');

    Route::post(
        '/users/{user}/update-role',
        [Admin\AdminController::class, 'updateRole']
    )->name('users.updateRole');
    Route::get('/books/import', [BookImportController::class, 'import'])->name('books.import');
    Route::get('/books/import-file', [BookImportController::class, 'importFile'])->name('books.importFile');

    // Import file browser routes
    Route::prefix('import')->group(function (): void {
        Route::get('roots', [
            \App\Http\Controllers\Admin\ImportFileController::class,
            'roots',
        ])->name('import.roots');

        Route::get('list', [
            \App\Http\Controllers\Admin\ImportFileController::class,
            'list',
        ])->name('import.list');

        Route::post('extract', [
            \App\Http\Controllers\Admin\ImportFileController::class,
            'extract',
        ])->name('import.extract');

        Route::post('extract-ai', [
            \App\Http\Controllers\Admin\ImportFileController::class,
            'extractWithAI',
        ])->name('import.extract.ai');

        Route::post('move', [
            \App\Http\Controllers\Admin\ImportFileController::class,
            'moveSelected',
        ])->name('import.move');
    });
    // Unified search endpoint for all book APIs
    Route::get('/books/search', action: [BookMetadataSearchController::class, 'searchBooks'])
        ->name('books.search');

    // Legacy endpoints (deprecated)
    Route::get('/books/googleBooks', action: [BookMetadataSearchController::class, 'googleBooks'])->name('books.googleBooks');
    Route::get('/books/audible', action: [BookMetadataSearchController::class, 'audible'])->name('books.audible');

    // AJAX endpoints for Tom Select
    Route::get('/authors/ajax', [Admin\AuthorController::class, 'ajax'])->name('authors.ajax');
    Route::get('/series/ajax', [BookSeriesController::class, 'seriesAjax'])->name('series.ajax');
    Route::post(
        '/import/rename',
        [Admin\BookFilesystemController::class, 'renameImportItem']
    )->name('import.rename');

    // AJAX: List files in book directory
    Route::get('books/files-ajax', [Admin\BookFilesystemController::class, 'filesAjax'])->name('books.filesAjax');

    // AJAX: Extract embedded cover from audio files
    Route::post('books/extract-embedded-cover', [BookCoverAdminController::class, 'extractEmbeddedCover'])
        ->name('books.extract-embedded-cover');

    // AJAX: Get audio file metadata
    Route::get('books/audio-metadata', [Admin\AudioMetadataController::class, 'getMetadata'])
        ->name('books.audioMetadata');

    // AJAX: Browse directories for path selection
    Route::get('books/browse-directories', [Admin\BookFilesystemController::class, 'browseDirectories'])
        ->name('books.browseDirectories');

    // AJAX: Rename series across all books
    Route::post('books/rename-series', [BookSeriesController::class, 'renameSeries'])->name('books.renameSeries');

    // AJAX: Check for directory path conflicts
    Route::post('books/check-directory-conflict', [BookPathController::class, 'checkDirectoryConflict'])
        ->name('books.checkDirectoryConflict');

    // AJAX: Build directory path from form fields
    Route::post('books/build-path-from-fields', [BookPathController::class, 'buildPathFromFields'])
        ->name('books.buildPathFromFields');

    // AJAX: Execute immediate directory move
    Route::post('books/{id}/execute-immediate-move', [BookPathController::class, 'executeImmediateMove'])
        ->name('books.executeImmediateMove');

    // AJAX: Planned actions preview for edit form
    Route::post('books/{id}/planned-actions', [BookFormController::class, 'plannedActions'])
        ->name('books.plannedActions');

    Route::get('genres/{genre}/authors', [Admin\GenreController::class, 'authors'])->name('genres.authors');
    Route::post('genres/merge', [Admin\GenreController::class, 'merge'])->name('genres.merge');

    Route::get('authors/{author}/browse', [Admin\AuthorController::class, 'browse'])->name('authors.browse');
    Route::post('authors/toggle-merge', [Admin\AuthorController::class, 'toggleMerge'])->name('authors.toggle-merge');
    Route::post('authors/clear-merge', [Admin\AuthorController::class, 'clearMerge'])->name('authors.clear-merge');
    Route::post('authors/merge', [Admin\AuthorController::class, 'merge'])->name('authors.merge');

    Route::resource('genres', Admin\GenreController::class);
    Route::resource('authors', Admin\AuthorController::class);
    Route::resource('books', Admin\BookController::class)->except(['create']);
    Route::post('books/{book}/autofill-from-path', [Admin\BookController::class, 'autofillFromPath'])
        ->name('books.autofillFromPath');
    Route::get('books/create', [BookFormController::class, 'create'])->name('books.create');
    Route::get('books/{id}/download-zip', [BookExportController::class, 'download'])->name('books.downloadZip');
    Route::get('books/{id}/raw-json', [BookJsonController::class, 'getRawJson'])->name('books.rawJson');
    Route::post('books/{id}/raw-json', [BookJsonController::class, 'saveRawJson'])->name('books.saveRawJson');

    // Autocomplete routes for Book form
    Route::get('/books/autocomplete/authors', [
        BookAutocompleteController::class,
        'autocompleteAuthors',
    ])->name('books.autocomplete.authors');

    Route::get('/books/autocomplete/series', [
        BookAutocompleteController::class,
        'autocompleteSeries',
    ])->name('books.autocomplete.series');

    Route::get('/books/autocomplete/narrators', [
        BookAutocompleteController::class,
        'autocompleteNarrators',
    ])->name('books.autocomplete.narrators');

    Route::get('/books/autocomplete/genres', [
        BookAutocompleteController::class,
        'autocompleteGenres',
    ])->name('books.autocomplete.genres');

    Route::post('/books/parse-path', [
        Admin\ParsePathController::class,
        'parsePath',
    ])->name('books.parsePath');

    // Series management routes
    Route::get('/series/manage', [
        Admin\ManageSeriesController::class,
        'index',
    ])->name('series.manage');

    Route::post('/series/merge', [
        Admin\ManageSeriesController::class,
        'merge',
    ])->name('series.merge');

    Route::post('/series/rename', [
        Admin\ManageSeriesController::class,
        'rename',
    ])->name('series.rename');

    Route::get('/series/{series}/edit', [
        Admin\SeriesController::class,
        'edit',
    ])->name('series.edit');

    Route::put('/series/{series}', [
        Admin\SeriesController::class,
        'update',
    ])->name('series.update');

    Route::delete('/series/{series}', [
        Admin\SeriesController::class,
        'destroy',
    ])->name('series.destroy');

    // LibriVox management
    Route::prefix('librivox')->name('librivox.')->group(function (): void {
        Route::get('/', [\App\Http\Controllers\Admin\LibriVox\LibriVoxController::class, 'index'])->name('index');
        Route::get('/search', [\App\Http\Controllers\Admin\LibriVox\LibriVoxController::class, 'search'])->name('search');
        Route::get('/genres', [\App\Http\Controllers\Admin\LibriVox\LibriVoxController::class, 'genres'])->name('genres');
        Route::get('/genres/{genre}', [\App\Http\Controllers\Admin\LibriVox\LibriVoxController::class, 'genreBooks'])->name('genre.books')->where('genre', '.+');
        Route::get('/authors', [\App\Http\Controllers\Admin\LibriVox\LibriVoxController::class, 'authors'])->name('authors');
        Route::get('/authors/{authorId}/books', [\App\Http\Controllers\Admin\LibriVox\LibriVoxController::class, 'authorBooks'])->name('author.books');
        Route::post('/sync', [\App\Http\Controllers\Admin\LibriVox\LibriVoxController::class, 'triggerSync'])->name('sync');
        Route::post('/sync/cancel', [\App\Http\Controllers\Admin\LibriVox\LibriVoxController::class, 'cancelSync'])->name('sync.cancel');
    });

    Route::resource('account_requests', Admin\AccountRequestController::class);
    Route::get('/books/import-from-title', [
        Admin\BookController::class,
        'importFromTitle',
    ])->name('books.importFromTitle');

    Route::post('/books/import-from-google-books', [
        Admin\BookController::class,
        'importFromGoogleBooks',
    ])->name('books.importFromGoogleBooks');

    Route::post('/books/processImport', [
        BookImportController::class,
        'processImport',
    ])->name('books.processImport');
    Route::get(
        '/directory-browser',
        [Admin\DirectoryBrowserController::class, 'browse']
    )->name('directoryBrowser');

    // Bulk import books from directory (recursive, queued)
    Route::post('/books/bulk-import', [
        Admin\QueueController::class,
        'bulkImportBooks',
    ])->name('books.bulkImport');

    // Bulk import from a specific directory (recursive)
    Route::post('/books/bulk-import-dir', [
        Admin\QueueController::class,
        'bulkImportBooksFromDir',
    ])->name('books.bulkImportDir');

    // User management
    Route::get('/badges', [Admin\BadgeController::class, 'index'])->name('badges.index');
    Route::resource('users', Admin\UserController::class);
    Route::post('users/{id}/verify', [Admin\UserController::class, 'verify'])
        ->name('users.verify');

    // Event timeline
    Route::get('users/{user}/events', [Admin\EventTimelineController::class, 'index'])
        ->name('events.timeline');

    // Books in progress (materialized positions)
    Route::get('users/{user}/book-positions', [Admin\BookPositionController::class, 'index'])
        ->name('books.positions');

    // Queue management (admin only)
    Route::middleware(['auth', 'admin'])->group(function (): void {
        Route::get('/queue', [Admin\QueueController::class, 'index'])->name('queue.index');
        Route::get('/queue/list', [Admin\QueueController::class, 'list']);
        Route::post('/queue/remove/{id}', [Admin\QueueController::class, 'remove']);
        Route::get('/queue/status', [Admin\QueueController::class, 'status']);
        Route::post('/queue/start', [Admin\QueueController::class, 'startWorker']);
        Route::post('/queue/clear', [Admin\QueueController::class, 'clear'])->name('queue.clear');
    });

    // Horizon dashboard route (admin only)
    Route::get('/horizon', fn () => view('horizon'))
        ->name('horizon')
        ->middleware(['auth', 'admin']);

    // Admin messaging system
    Route::get('messages', [Admin\MessageController::class, 'index'])->name('messages.index');
    Route::get('messages/create', [Admin\MessagesController::class, 'create'])->name('messages.create');
    Route::post('messages', [MessageController::class, 'storeAdmin'])->name('messages.store');
    // Route::get('messages/{id}', [Admin\MessagesController::class, 'show'])->name('messages.show');
    Route::post('messages/{id}/mark-as-read', [
        Admin\MessagesController::class,
        'markAsRead',
    ])->name('messages.markAsRead');

    Route::post(
        '/send-notification',
        [AdminNotificationController::class, 'sendNotification']
    )->name('send.notification');
    // Message routes
    Route::post(
        '/messages/{messageId}/acknowledge',
        [Admin\MessageController::class, 'acknowledge']
    )->name('messages.acknowledge');

    // Job management
    Route::get('/jobs', [
        Admin\JobController::class,
        'index',
    ])->name('jobs.index');

    Route::get('/jobs/{id}', [
        Admin\JobController::class,
        'show',
    ])->name('jobs.show');

    Route::post('/jobs/{id}/retry', [
        Admin\JobController::class,
        'retry',
    ])->name('jobs.retry');

    Route::post('/jobs/{id}/cancel', [
        Admin\JobController::class,
        'cancel',
    ])->name('jobs.cancel');

    Route::delete('/jobs/cleanup/{daysOld?}', [
        Admin\JobController::class,
        'cleanup',
    ])->name('jobs.cleanup');

    Route::get('/jobs/{id}/logs', [
        Admin\JobController::class,
        'logs',
    ])->name('jobs.logs');

    Route::get('/jobs/{id}/output', [
        Admin\JobController::class,
        'output',
    ])->name('jobs.output');

    Route::get('/jobs/{id}/errors', [
        Admin\JobController::class,
        'errors',
    ])->name('jobs.errors');

    // Trash management routes
    Route::get('/trash', [Admin\TrashController::class, 'index'])->name('trash.index');
    Route::post('/trash/{id}/restore', [Admin\TrashController::class, 'restore'])->name('trash.restore');
    Route::delete('/trash/{id}', [Admin\TrashController::class, 'destroy'])->name('trash.destroy');
    Route::delete('/trash', [Admin\TrashController::class, 'destroyAll'])->name('trash.destroyAll');
    Route::post('/trash/cleanup', [Admin\TrashController::class, 'applyAutoCleanup'])->name('trash.cleanup');

    // Skin & Theme Management
    Route::resource('skins', Admin\SkinController::class);
    Route::resource('themes', Admin\ThemeController::class);
});

// Gallery Routes (Skins & Themes)
Route::name('gallery.')->prefix('gallery')->group(function (): void {
    // Skin Routes
    Route::name('skins.')->prefix('skins')->group(function (): void {
        Route::get('/', [SkinWebController::class, 'index'])->name('index');
        Route::get('/create', [SkinWebController::class, 'create'])->name('create');
        Route::get('/design-new', [SkinWebController::class, 'designerNew'])->name('designerNew');
        Route::post('/', [SkinWebController::class, 'store'])->name('store');
        Route::get('/my-skins', [SkinWebController::class, 'mySkins'])->name('my-skins');
        Route::get('/sample-data', [SkinWebController::class, 'getSampleData'])->name('sample-data');
        Route::get('/{id}', [SkinWebController::class, 'show'])->name('show')->whereNumber('id');
        Route::get('/{id}/edit', [SkinWebController::class, 'edit'])->name('edit')->whereNumber('id');
        Route::put('/{id}', [SkinWebController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('/{id}', [SkinWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
        Route::post('/{id}/fork', [SkinWebController::class, 'fork'])->name('fork')->whereNumber('id');
        Route::post('/{id}/rate', [SkinWebController::class, 'rate'])->name('rate')->whereNumber('id');

        // Designer Routes
        Route::get('/{id}/designer', [SkinWebController::class, 'designer'])->name('designer')->whereNumber('id');
        Route::post('/{id}/manifest', [SkinWebController::class, 'updateManifest'])->name('updateManifest')->whereNumber('id');
        Route::post('/{id}/assets', [SkinWebController::class, 'uploadAsset'])->name('uploadAsset')->whereNumber('id');
        Route::get('/{id}/assets', [SkinWebController::class, 'listAssets'])->name('listAssets')->whereNumber('id');
        Route::post('/{id}/fork-designer', [SkinWebController::class, 'forkForDesigner'])->name('forkForDesigner')->whereNumber('id');
        Route::get('/{id}/export', [SkinWebController::class, 'exportZip'])->name('exportZip')->whereNumber('id');

        // Built-in skins (from client repository)
        Route::prefix('builtin')->name('builtin.')->group(function (): void {
            Route::get('/', [BuiltinSkinController::class, 'index'])->name('index');
            Route::get('/asset/{slug}/{path}', [BuiltinSkinController::class, 'serveAsset'])->name('asset')->where('path', '.*');
            Route::get('/{slug}', [BuiltinSkinController::class, 'show'])->name('show');
            Route::get('/{slug}/designer', [BuiltinSkinController::class, 'designer'])->name('designer');
            Route::post('/{slug}/manifest', [BuiltinSkinController::class, 'updateManifest'])->name('updateManifest');
            Route::post('/{slug}/assets', [BuiltinSkinController::class, 'uploadAsset'])->name('uploadAsset');
            Route::get('/{slug}/assets', [BuiltinSkinController::class, 'listAssets'])->name('listAssets');
            Route::post('/{slug}/fork', [BuiltinSkinController::class, 'fork'])->name('fork');
            Route::get('/{slug}/download', [BuiltinSkinController::class, 'download'])->name('download');
        });
    });

    // Theme Routes
    Route::name('themes.')->prefix('themes')->group(function (): void {
        Route::get('/', [ThemeWebController::class, 'index'])->name('index');
        Route::get('/create', [ThemeWebController::class, 'create'])->name('create');
        Route::post('/', [ThemeWebController::class, 'store'])->name('store');
        Route::get('/my-themes', [ThemeWebController::class, 'myThemes'])->name('my-themes');
        Route::get('/{id}', [ThemeWebController::class, 'show'])->name('show');
        Route::get('/{id}/edit', [ThemeWebController::class, 'edit'])->name('edit');
        Route::put('/{id}', [ThemeWebController::class, 'update'])->name('update');
        Route::delete('/{id}', [ThemeWebController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/fork', [ThemeWebController::class, 'fork'])->name('fork');
        Route::post('/{id}/rate', [ThemeWebController::class, 'rate'])->name('rate');
    });
});
