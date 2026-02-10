<?php

declare(strict_types=1);

use App\Http\Controllers\Admin;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\ImageProxyController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReadingProgressController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SkinWebController;
use App\Http\Controllers\ThemeWebController;
use App\Http\Controllers\UserLibraryController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// --- EMERGENCY ROUTES ---
// Emergency book routes that bypass memory-intensive models
Route::get('/emergency/books', [App\Http\Controllers\EmergencyBookController::class, 'index'])
    ->name('emergency.books.index');

// --- DEBUG ROUTES (local only) ---
if (app()->environment('local')) {
    // Memory test route
    Route::get('/test-memory', [App\Http\Controllers\TestController::class, 'memoryTest']);

    // Reset test user password to a known value
    Route::get('/reset-test-password', function () {
        $user = \App\Models\User::where('email', 'eric@thelin.org')->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Test user not found',
            ], 404);
        }

        $newPassword = 'password123';
        $user->password = \Illuminate\Support\Facades\Hash::make($newPassword);
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Test user password has been reset',
            'email' => $user->email,
            'new_password' => $newPassword,
        ]);
    });

    // Test authentication with MySQL
    Route::get('/test-auth', function () {
        // Test authentication with the test user
        $user = \App\Models\User::where('email', 'eric@thelin.org')->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Test user not found',
            ], 404);
        }

        $password = 'password123'; // This should match the password set in /reset-test-password
        $credentials = [
            'email' => 'eric@thelin.org',
            'password' => $password,
        ];

        // Direct password check for debugging
        $directPasswordCheck = $user->password && \Illuminate\Support\Facades\Hash::check($password, $user->password);

        // Debug information
        $debugInfo = [
            'user_found' => true,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'password_provided' => $password,
            'password_hash_in_db' => $user->password,
            'direct_password_check' => $directPasswordCheck,
            'auth_driver' => config('auth.defaults.guard'),
            'auth_provider' => config('auth.guards.web.provider'),
            'user_provider' => config('auth.providers.users.driver'),
            'auth_guards' => config('auth.guards'),
            'auth_providers' => config('auth.providers'),
            'user_model' => $user::class,
            'user_attributes' => $user->toArray(),
        ];

        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            return response()->json([
                'status' => 'success',
                'message' => 'Authentication successful',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'session' => session()->all(),
                'debug' => $debugInfo,
            ]);
        }
        // Add more detailed error information
        $errorInfo = [
            'auth_check' => Auth::check(),
            'auth_user' => Auth::user() ? Auth::user()->only(['id', 'name', 'email']) : null,
            'session_data' => session()->all(),
            'debug' => $debugInfo,
        ];

        return response()->json([
            'status' => 'error',
            'message' => 'Authentication failed',
            'error' => 'Invalid credentials',
            'details' => $errorInfo,
        ], 401);
    });

    // Move all /debug/ routes to DebugController
    Route::get('/debug/middleware', [Admin\DebugController::class, 'debugMiddleware']);
    Route::get('/debug/auth', [Admin\DebugController::class, 'auth']);
    Route::get('/debug/session', [Admin\DebugController::class, 'session']);
    Route::get('/debug/sessiondb', [Admin\DebugController::class, 'sessiondb']);
    Route::get('/debug/document/{collection}/{docId}', [Admin\DebugController::class, 'showDocument']);

    Route::get('/debug/logout', [Admin\DebugController::class, 'logout']);
    Route::get('/debug/session-write', [Admin\DebugController::class, 'sessionWrite']);

    // Dump all users/books via DebugController (now MySQL-based)
    Route::get('/debug/users-dump', [Admin\DebugController::class, 'usersDump']);
    Route::get('/debug/books-dump', [Admin\DebugController::class, 'booksDump']);

    // Debug database relationships
    Route::get('/debug/relationships', fn () => [
        'author_book' => DB::table('author_book')->get(),
        'book_narrator' => DB::table('book_narrator')->get(),
        'book_genre' => DB::table('book_genre')->get(),
        'books' => \App\Models\Book::with(['authors', 'narrators', 'genres'])->limit(5)->get(),
    ]);
}

Route::get('/', function () {
    if (Auth::check()) {
        if (Auth::user()->is_admin) {
            return redirect()->route('admin.books.index')->with('status', 'Welcome to Audiobook Librarian!');
        }

        return redirect()->route('books.index')->with('status', 'Welcome to Audiobook Librarian!');
    }

    return view('welcome');
});

Auth::routes();

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

    Route::resource('books', BookController::class)->only(['index', 'show']);
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
Route::get('/api/books/json', [BookController::class, 'jsonIndex'])->name('api.books.json');
Route::get('/api/books/recent/json', [BookController::class, 'jsonRecent'])->name('api.books.recent.json');
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
Route::get('/admin/series-autocomplete', [Admin\BookController::class, 'autocompleteSeries'])
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
    Route::post('/books/resync-from-path', [Admin\BookController::class, 'resyncFromPath'])
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
    Route::get('/books/import', [Admin\BookController::class, 'import'])->name('books.import');
    Route::get('/books/import-file', [Admin\BookController::class, 'importFile'])->name('books.importFile');

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
    Route::get('/books/search', action: [Admin\BookController::class, 'searchBooks'])
        ->name('books.search');

    // Legacy endpoints (deprecated)
    Route::get('/books/googleBooks', action: [Admin\BookController::class, 'googleBooks'])->name('books.googleBooks');
    Route::get('/books/audible', action: [Admin\BookController::class, 'audible'])->name('books.audible');

    // AJAX endpoints for Tom Select
    Route::get('/authors/ajax', [Admin\AuthorController::class, 'ajax'])->name('authors.ajax');
    Route::get('/series/ajax', [Admin\BookController::class, 'seriesAjax'])->name('series.ajax');
    Route::post(
        '/import/rename',
        [Admin\BookFilesystemController::class, 'renameImportItem']
    )->name('import.rename');

    // AJAX: List files in book directory
    Route::get('books/files-ajax', [Admin\BookFilesystemController::class, 'filesAjax'])->name('books.filesAjax');

    // AJAX: Extract embedded cover from audio files
    Route::post('books/extract-embedded-cover', [Admin\BookController::class, 'extractEmbeddedCover'])
        ->name('books.extract-embedded-cover');

    // AJAX: Get audio file metadata
    Route::get('books/audio-metadata', [Admin\AudioMetadataController::class, 'getMetadata'])
        ->name('books.audioMetadata');

    // AJAX: Browse directories for path selection
    Route::get('books/browse-directories', [Admin\BookFilesystemController::class, 'browseDirectories'])
        ->name('books.browseDirectories');

    // AJAX: Rename series across all books
    Route::post('books/rename-series', [Admin\BookController::class, 'renameSeries'])->name('books.renameSeries');

    // AJAX: Check for directory path conflicts
    Route::post('books/check-directory-conflict', [Admin\BookController::class, 'checkDirectoryConflict'])
        ->name('books.checkDirectoryConflict');

    // AJAX: Build directory path from form fields
    Route::post('books/build-path-from-fields', [Admin\BookController::class, 'buildPathFromFields'])
        ->name('books.buildPathFromFields');

    // AJAX: Execute immediate directory move
    Route::post('books/{id}/execute-immediate-move', [Admin\BookController::class, 'executeImmediateMove'])
        ->name('books.executeImmediateMove');

    // AJAX: Planned actions preview for edit form
    Route::post('books/{id}/planned-actions', [Admin\BookController::class, 'plannedActions'])
        ->name('books.plannedActions');

    Route::get('genres/{genre}/authors', [Admin\GenreController::class, 'authors'])->name('genres.authors');
    Route::post('genres/merge', [Admin\GenreController::class, 'merge'])->name('genres.merge');

    Route::get('authors/{author}/browse', [Admin\AuthorController::class, 'browse'])->name('authors.browse');
    Route::post('authors/toggle-merge', [Admin\AuthorController::class, 'toggleMerge'])->name('authors.toggle-merge');
    Route::post('authors/clear-merge', [Admin\AuthorController::class, 'clearMerge'])->name('authors.clear-merge');
    Route::post('authors/merge', [Admin\AuthorController::class, 'merge'])->name('authors.merge');

    Route::resource('genres', Admin\GenreController::class);
    Route::resource('authors', Admin\AuthorController::class);
    Route::resource('books', Admin\BookController::class);
    Route::get('books/{id}/raw-json', [Admin\BookController::class, 'getRawJson'])->name('books.rawJson');
    Route::post('books/{id}/raw-json', [Admin\BookController::class, 'saveRawJson'])->name('books.saveRawJson');

    // Autocomplete routes for Book form
    Route::get('/books/autocomplete/authors', [
        Admin\BookController::class,
        'autocompleteAuthors',
    ])->name('books.autocomplete.authors');

    Route::get('/books/autocomplete/series', [
        Admin\BookController::class,
        'autocompleteSeries',
    ])->name('books.autocomplete.series');

    Route::get('/books/autocomplete/narrators', [
        Admin\BookController::class,
        'autocompleteNarrators',
    ])->name('books.autocomplete.narrators');

    Route::get('/books/autocomplete/genres', [
        Admin\BookController::class,
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
        Admin\BookController::class,
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
