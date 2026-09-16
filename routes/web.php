<?php

declare(strict_types=1);

use App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\BookAutocompleteController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Admin\BookCoverAdminController;
use App\Http\Controllers\Admin\BookExportController;
use App\Http\Controllers\Admin\BookFormController;
use App\Http\Controllers\Admin\BookImportController;
use App\Http\Controllers\Admin\BookJsonController;
use App\Http\Controllers\Admin\BookMetadataSearchController;
use App\Http\Controllers\Admin\BookPathController;
use App\Http\Controllers\Admin\BookSeriesController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\AppConnectController;
use App\Http\Controllers\AuthorController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\GenreController;
use App\Http\Controllers\ImageProxyController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReadingProgressController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\UserLibraryController;
use App\Http\Controllers\Api\EmailOtpController;
use App\Http\Controllers\AccountDeletionCancellationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Skin/theme management (gallery pages, designer, admin panels, built-in
 * skin browsing) has moved entirely to audiobook-librarian-www — see the
 * extraction plan and docs/GALLERY_MIGRATION.md. Every route below that used
 * to serve a Blade view now redirects to the identical path on www, since
 * both apps use the same URL structure for this feature. Route names are
 * kept unchanged so existing route() calls elsewhere in this app (e.g. the
 * nav in layouts/app.blade.php) keep working without modification.
 *
 * A closure (not a top-level function) so requiring this file more than
 * once in the same process — which Laravel's test suite does whenever it
 * boots a fresh application — never triggers a "cannot redeclare" fatal.
 */
$redirectToGalleryWww = function (Request $request) {
    $target = rtrim((string) config('services.gallery_www.base_url'), '/') . '/' . ltrim($request->path(), '/');
    $query = $request->getQueryString();

    return redirect()->away($target . ($query ? '?' . $query : ''));
};

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
Route::post('/auth/otp/verify', [EmailOtpController::class, 'verifyCodeWeb'])
    ->name('auth.otp.verify.web');

Route::get('/account-deletion/cancel/{token}', [AccountDeletionCancellationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}');
Route::post('/account-deletion/cancel/{token}', [AccountDeletionCancellationController::class, 'cancel'])
    ->where('token', '[A-Za-z0-9]{64}');
Route::get('/account-deletion/cancelled', fn () => view('account-deletion.cancelled'));

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
Route::get('/app/connect/server', [AppConnectController::class, 'server'])
    ->name('app.connect.server');

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
        Route::get('/tags', [UserLibraryController::class, 'tags'])->name('tags');
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
    Route::post('/books/{book}/tags', [\App\Http\Controllers\TagController::class, 'update'])->name('books.tags.update');

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
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/tokens', [ApiTokenController::class, 'store'])->name('profile.tokens.store');
    Route::delete('/profile/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('profile.tokens.destroy');
});

Route::get('/account-deletion/scheduled', fn () => view('account-deletion.scheduled'))
    ->name('account-deletion.scheduled');

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

// General image proxy for covers and previews. These serve arbitrary files from
// BOOK_STORAGE_PATH (the /cover/{path} route is also reused by the admin library-repair
// tool to stream non-image files for comparison), so they require authentication —
// unauthenticated requests must not be able to read any file in the book root.
Route::middleware(['auth'])->group(function (): void {
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
});

// Skin asset proxy — moved to audiobook-librarian-www.
Route::get('/skin-asset/{skinId}/{path}', $redirectToGalleryWww)
    ->where('path', '.*')->name('skin.asset.proxy');

// Admin series autocomplete endpoint for book form (accessible to admin users)
Route::get('/admin/series-autocomplete', [BookAutocompleteController::class, 'autocompleteSeries'])
    ->name('admin.series.autocomplete')
    ->middleware(['auth', 'admin']);

// --- Blended content-management routes (permission-gated, no separate admin namespace) ---
// Read-only pages are open to any authenticated user; mutating actions require the
// matching PermissionKey (see app/Enums/PermissionKey.php and CLAUDE.md plan
// "Blend admin and user modes"). Old /admin/{entity} URLs redirect below.
Route::middleware(['auth'])->group(function (): void {
    // Tags (system tag catalog — distinct from a single book's own tags, see
    // App\Http\Controllers\TagController::update for that).
    Route::get('/tags', [\App\Http\Controllers\SystemTagController::class, 'index'])->name('tags.index');
    Route::middleware('permission:manage-tags')->group(function (): void {
        Route::get('/tags/{tag}/edit', [\App\Http\Controllers\SystemTagController::class, 'edit'])
            ->where('tag', '.+')->name('tags.edit');
        Route::put('/tags/{tag}', [\App\Http\Controllers\SystemTagController::class, 'update'])
            ->where('tag', '.+')->name('tags.update');
        Route::delete('/tags/{tag}', [\App\Http\Controllers\SystemTagController::class, 'destroy'])
            ->where('tag', '.+')->name('tags.destroy');
    });

    // Genres
    Route::get('/genres', [GenreController::class, 'index'])->name('genres.index');
    Route::get('/genres/{genre}/authors', [GenreController::class, 'authors'])->name('genres.authors');
    Route::middleware('permission:manage-genres')->group(function (): void {
        Route::get('/genres/create', [GenreController::class, 'create'])->name('genres.create');
        Route::post('/genres', [GenreController::class, 'store'])->name('genres.store');
        Route::get('/genres/{genre}/edit', [GenreController::class, 'edit'])->name('genres.edit');
        Route::put('/genres/{genre}', [GenreController::class, 'update'])->name('genres.update');
        Route::delete('/genres/{genre}', [GenreController::class, 'destroy'])->name('genres.destroy');
        Route::post('/genres/merge', [GenreController::class, 'merge'])->name('genres.merge');
    });

    // Authors
    Route::get('/authors', [AuthorController::class, 'index'])->name('authors.index');
    Route::get('/authors/ajax', [AuthorController::class, 'ajax'])->name('authors.ajax');
    Route::get('/authors/{author}/browse', [AuthorController::class, 'browse'])->name('authors.browse');
    Route::middleware('permission:manage-authors')->group(function (): void {
        Route::get('/authors/create', [AuthorController::class, 'create'])->name('authors.create');
        Route::post('/authors', [AuthorController::class, 'store'])->name('authors.store');
        Route::get('/authors/{author}/edit', [AuthorController::class, 'edit'])->name('authors.edit');
        Route::put('/authors/{author}', [AuthorController::class, 'update'])->name('authors.update');
        Route::delete('/authors/{author}', [AuthorController::class, 'destroy'])->name('authors.destroy');
        Route::post('/authors/toggle-merge', [AuthorController::class, 'toggleMerge'])->name('authors.toggle-merge');
        Route::post('/authors/clear-merge', [AuthorController::class, 'clearMerge'])->name('authors.clear-merge');
        Route::post('/authors/merge', [AuthorController::class, 'merge'])->name('authors.merge');
    });

    // Badges
    Route::get('/badges', [\App\Http\Controllers\BadgeController::class, 'index'])->name('badges.index');
    Route::middleware('permission:manage-badges')->group(function (): void {
        Route::get('/badges/create', [\App\Http\Controllers\BadgeController::class, 'create'])->name('badges.create');
        Route::post('/badges', [\App\Http\Controllers\BadgeController::class, 'store'])->name('badges.store');
        Route::get('/badges/{badge}/edit', [\App\Http\Controllers\BadgeController::class, 'edit'])->name('badges.edit');
        Route::put('/badges/{badge}', [\App\Http\Controllers\BadgeController::class, 'update'])->name('badges.update');
        Route::delete('/badges/{badge}', [\App\Http\Controllers\BadgeController::class, 'destroy'])->name('badges.destroy');
        Route::post('/badges/{badge}/activate', [\App\Http\Controllers\BadgeController::class, 'activate'])
            ->name('badges.activate');
        Route::delete('/badges/{badge}/force', [\App\Http\Controllers\BadgeController::class, 'forceDestroy'])
            ->name('badges.forceDestroy');
    });

    // Series (ManageSeriesController handles the list/merge/rename page;
    // SeriesController handles a single series record's edit/update/destroy)
    Route::get('/series/manage', [\App\Http\Controllers\ManageSeriesController::class, 'index'])->name('series.manage');
    Route::middleware('permission:manage-series')->group(function (): void {
        Route::post('/series/merge', [\App\Http\Controllers\ManageSeriesController::class, 'merge'])->name('series.merge');
        Route::post('/series/rename', [\App\Http\Controllers\ManageSeriesController::class, 'rename'])->name('series.rename');
        Route::get('/series/{series}/edit', [\App\Http\Controllers\SeriesController::class, 'edit'])->name('series.edit');
        Route::put('/series/{series}', [\App\Http\Controllers\SeriesController::class, 'update'])->name('series.update');
        Route::delete('/series/{series}', [\App\Http\Controllers\SeriesController::class, 'destroy'])->name('series.destroy');
    });
});

Route::name('admin.')->prefix('admin')->middleware(['auth', 'admin'])->group(function () use ($redirectToGalleryWww): void {
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
    Route::get('/series/ajax', [BookSeriesController::class, 'seriesAjax'])->name('series.ajax');
    Route::post(
        '/import/rename',
        [Admin\BookFilesystemController::class, 'renameImportItem']
    )->name('import.rename');

    // AJAX: List files in book directory
    Route::get('books/files-ajax', [Admin\BookFilesystemController::class, 'filesAjax'])->name('books.filesAjax');

    // AJAX: List other books by the same author or in the same series
    Route::get('books/related-ajax', [Admin\BookController::class, 'relatedBooksAjax'])->name('books.relatedAjax');

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

    // 'index' and 'show' are deliberately excluded here: both were exact duplicates of
    // BookController's versions (index() gained a manage-books branch that renders the
    // same admin.books.index view; show() rendered the identical books.show view). Their
    // names are registered outside this admin-only-gated group (below, at top level) as
    // plain redirects to the shared /books URLs, so a manage-books permission holder who
    // isn't a full admin can still follow an old bookmarked /admin/books(/…) link.
    Route::resource('books', Admin\BookController::class)->except(['create', 'show', 'index']);
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
    Route::resource('users', Admin\UserController::class);
    Route::post('users/{id}/verify', [Admin\UserController::class, 'verify'])
        ->name('users.verify');
    Route::post('users/{id}/send-otp', [Admin\UserController::class, 'sendOtp'])
        ->name('users.sendOtp');
    Route::post('users/{id}/login-qr', [Admin\UserController::class, 'generateLoginQr'])
        ->name('users.loginQr');
    Route::patch('users/{id}/permissions', [Admin\UserController::class, 'updatePermissions'])
        ->name('users.updatePermissions');

    // Event timeline
    Route::get('users/{user}/events', [Admin\EventTimelineController::class, 'index'])
        ->name('events.timeline');

    // Books in progress (materialized positions)
    Route::get('users/{user}/book-positions', [Admin\BookPositionController::class, 'index'])
        ->name('books.positions');

    // Queue management (admin only)
    Route::middleware(['auth', 'admin'])->group(function (): void {
        Route::get('/queue', [Admin\QueueController::class, 'index'])->name('queue.index');
        Route::get('/queue/list', [Admin\QueueController::class, 'list'])->name('queue.list');
        Route::post('/queue/remove/{id}', [Admin\QueueController::class, 'remove'])->name('queue.remove');
        Route::get('/queue/status', [Admin\QueueController::class, 'status'])->name('queue.status');
        Route::post('/queue/start', [Admin\QueueController::class, 'startWorker'])->name('queue.start');
        Route::post('/queue/stop', [Admin\QueueController::class, 'stopWorker'])->name('queue.stop');
        Route::post('/queue/pause', [Admin\QueueController::class, 'pauseQueue'])->name('queue.pause');
        Route::post('/queue/resume', [Admin\QueueController::class, 'resumeQueue'])->name('queue.resume');
        Route::post('/queue/retry/{id?}', [Admin\QueueController::class, 'retryFailed'])->name('queue.retry');
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

    // Skin & Theme Management — moved to audiobook-librarian-www. Kept behind
    // this group's existing auth+admin middleware as extra safety, even
    // though it's a harmless redirect.
    Route::name('skins.')->prefix('skins')->group(function () use ($redirectToGalleryWww): void {
        Route::get('/', $redirectToGalleryWww)->name('index');
        Route::get('/create', $redirectToGalleryWww)->name('create');
        Route::post('/', $redirectToGalleryWww)->name('store');
        Route::get('/{skin}', $redirectToGalleryWww)->name('show');
        Route::get('/{skin}/edit', $redirectToGalleryWww)->name('edit');
        Route::match(['put', 'patch'], '/{skin}', $redirectToGalleryWww)->name('update');
        Route::delete('/{skin}', $redirectToGalleryWww)->name('destroy');
    });
    Route::name('themes.')->prefix('themes')->group(function () use ($redirectToGalleryWww): void {
        Route::get('/', $redirectToGalleryWww)->name('index');
        Route::get('/create', $redirectToGalleryWww)->name('create');
        Route::post('/', $redirectToGalleryWww)->name('store');
        Route::get('/{theme}', $redirectToGalleryWww)->name('show');
        Route::get('/{theme}/edit', $redirectToGalleryWww)->name('edit');
        Route::match(['put', 'patch'], '/{theme}', $redirectToGalleryWww)->name('update');
        Route::delete('/{theme}', $redirectToGalleryWww)->name('destroy');
    });
});

// Gallery Routes (Skins & Themes) — moved to audiobook-librarian-www; every
// route here just redirects to the identical path there (same URL structure
// on both apps), see $redirectToGalleryWww above.
Route::name('gallery.')->prefix('gallery')->group(function () use ($redirectToGalleryWww): void {
    Route::name('skins.')->prefix('skins')->group(function () use ($redirectToGalleryWww): void {
        Route::get('/', $redirectToGalleryWww)->name('index');
        Route::get('/create', $redirectToGalleryWww)->name('create');
        Route::get('/design-new', $redirectToGalleryWww)->name('designerNew');
        Route::post('/', $redirectToGalleryWww)->name('store');
        Route::get('/my-skins', $redirectToGalleryWww)->name('my-skins');
        Route::get('/sample-data', $redirectToGalleryWww)->name('sample-data');
        Route::get('/{id}', $redirectToGalleryWww)->name('show')->whereNumber('id');
        Route::get('/{id}/edit', $redirectToGalleryWww)->name('edit')->whereNumber('id');
        Route::put('/{id}', $redirectToGalleryWww)->name('update')->whereNumber('id');
        Route::delete('/{id}', $redirectToGalleryWww)->name('destroy')->whereNumber('id');
        Route::post('/{id}/fork', $redirectToGalleryWww)->name('fork')->whereNumber('id');
        Route::post('/{id}/rate', $redirectToGalleryWww)->name('rate')->whereNumber('id');

        Route::get('/{id}/designer', $redirectToGalleryWww)->name('designer')->whereNumber('id');
        Route::post('/{id}/manifest', $redirectToGalleryWww)->name('updateManifest')->whereNumber('id');
        Route::post('/{id}/assets', $redirectToGalleryWww)->name('uploadAsset')->whereNumber('id');
        Route::get('/{id}/assets', $redirectToGalleryWww)->name('listAssets')->whereNumber('id');
        Route::post('/{id}/fork-designer', $redirectToGalleryWww)->name('forkForDesigner')->whereNumber('id');
        Route::get('/{id}/export', $redirectToGalleryWww)->name('exportZip')->whereNumber('id');

        Route::prefix('builtin')->name('builtin.')->group(function () use ($redirectToGalleryWww): void {
            Route::get('/', $redirectToGalleryWww)->name('index');
            Route::get('/asset/{slug}/{path}', $redirectToGalleryWww)->name('asset')->where('path', '.*');
            Route::get('/{slug}', $redirectToGalleryWww)->name('show');
            Route::get('/{slug}/designer', $redirectToGalleryWww)->name('designer');
            Route::post('/{slug}/manifest', $redirectToGalleryWww)->name('updateManifest');
            Route::post('/{slug}/assets', $redirectToGalleryWww)->name('uploadAsset');
            Route::get('/{slug}/assets', $redirectToGalleryWww)->name('listAssets');
            Route::post('/{slug}/fork', $redirectToGalleryWww)->name('fork');
            Route::get('/{slug}/download', $redirectToGalleryWww)->name('download');
        });
    });

    Route::name('themes.')->prefix('themes')->group(function () use ($redirectToGalleryWww): void {
        Route::get('/', $redirectToGalleryWww)->name('index');
        Route::get('/create', $redirectToGalleryWww)->name('create');
        Route::post('/', $redirectToGalleryWww)->name('store');
        Route::get('/my-themes', $redirectToGalleryWww)->name('my-themes');
        Route::get('/{id}', $redirectToGalleryWww)->name('show');
        Route::get('/{id}/edit', $redirectToGalleryWww)->name('edit');
        Route::put('/{id}', $redirectToGalleryWww)->name('update');
        Route::delete('/{id}', $redirectToGalleryWww)->name('destroy');
        Route::post('/{id}/fork', $redirectToGalleryWww)->name('fork');
        Route::post('/{id}/rate', $redirectToGalleryWww)->name('rate');
    });
});

// Redirects for bookmarked/open old /admin/{entity} URLs that moved to top-level,
// permission-gated routes above (Phase 1 of the admin/user blend). Registered last
// so any still-active /admin/* route (e.g. admin.series.ajax, used by the book form)
// is matched first — this only catches paths nothing above claimed.
foreach (['tags', 'genres', 'authors', 'badges', 'series'] as $blendedEntity) {
    Route::get('/admin/' . $blendedEntity . '/{any?}', fn (?string $any = null) => redirect(
        '/' . $blendedEntity . ($any ? '/' . $any : '') . (($qs = request()->getQueryString()) ? '?' . $qs : '')
    ))->where('any', '.*');
}

// admin.books.index / admin.books.show: named (many other admin views still call these
// by name) redirects to the shared /books URLs, registered last for the same shadowing
// reason as above (e.g. must not swallow admin.books.relatedAjax at
// /admin/books/related-ajax) and outside the 'admin' role middleware so a manage-books
// permission holder who isn't a full admin can still follow an old bookmarked link.
Route::name('admin.')->prefix('admin')->middleware(['auth'])->group(function (): void {
    Route::get('/books', fn () => redirect()->route('books.index'))->name('books.index');
    Route::get('/books/{book}', fn ($book) => redirect()->route('books.show', $book))->name('books.show');
});
