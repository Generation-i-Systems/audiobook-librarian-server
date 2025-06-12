<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\ImageProxyController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReadingProgressController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;

// --- DEBUG AUTH/SESSION ROUTES (local only) ---
if (app()->environment('local')) {
    // Move all /debug/ routes to DebugController
    Route::get('/debug/middleware', [Admin\DebugController::class, 'debugMiddleware']);
    Route::get('/debug/auth', [Admin\DebugController::class, 'auth']);
    Route::get('/debug/session', [Admin\DebugController::class, 'session']);
    Route::get('/debug/sessiondb', [Admin\DebugController::class, 'sessiondb']);
    Route::get('/debug/firestore/{collection}/{docId}', [Admin\DebugController::class, 'showDocument']);

    Route::get('/debug/logout', [Admin\DebugController::class, 'logout']);
    Route::get('/debug/session-write', [Admin\DebugController::class, 'sessionWrite']);

    // Dump all Firestore users/books via DebugController
    Route::get('/debug/firestore-users-dump', [Admin\DebugController::class, 'firestoreUsersDump']);
    Route::get('/debug/firestore-books-dump', [Admin\DebugController::class, 'firestoreBooksDump']);
}

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('books.index')->with('status', 'Welcome to your audiobook library!');
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

Route::get('/home', function () {
    return redirect()->route('books.index')->with('status', 'Welcome to your audiobook library!');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::resource('books', BookController::class)->only(['index', 'show']);
    Route::get('/books/create', [
        \App\Http\Controllers\Admin\BookController::class,
        'showCreateForm',
    ])->name('books.create');
    Route::get('/books/{book}/download', [BookController::class, 'download'])->name('books.download');
    Route::post('/books/{book}/reviews', [ReviewController::class, 'store'])->name('reviews.store');
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');
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
});

Route::post('/messages', [MessageController::class, 'store'])->name('messages.store');

// Regular book routes
Route::post('/admin/books/resync-from-path', [\App\Http\Controllers\Admin\BookController::class, 'resyncFromPath'])->name('admin.books.resyncFromPath');
Route::get('/books', [BookController::class, 'index'])->name('books.index');
Route::get('/books/{id?}', [BookController::class, 'show'])->name('books.show');

// JSON API endpoints for AJAX requests
Route::get('/api/books/json', [BookController::class, 'jsonIndex'])->name('api.books.json');
Route::get('/api/books/recent/json', [BookController::class, 'jsonRecent'])->name('api.books.recent.json');
Route::get('/books/{id}/download', [BookController::class, 'download'])->name('books.download');
Route::post('/books/set-preference', [BookController::class, 'setPreference'])->name('books.set-preference');
Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
Route::put(
    '/profile/change-password',
    [ProfileController::class, 'changePassword']
)->name('profile.changePassword');
Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
Route::post(
    '/profile/request-admin',
    [ProfileController::class, 'requestAdminPermissions']
)->name('profile.requestAdminPermissions');

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

Route::name('admin.')->prefix('admin')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', function () {
        return redirect()->route('admin.books.index');
    });
    Route::post(
        '/users/{user}/update-role',
        [Admin\AdminController::class, 'updateRole']
    )->name('users.updateRole');
    Route::get('/books/import', [Admin\BookController::class, 'import'])->name('books.import');
    Route::get('/books/googleBooks', action: [Admin\BookController::class, 'googleBooks'])->name('books.googleBooks');

    // AJAX endpoints for Tom Select
    Route::get('/authors/ajax', [Admin\AuthorController::class, 'ajax'])->name('authors.ajax');
    Route::get('/series/ajax', [Admin\BookController::class, 'seriesAjax'])->name('series.ajax');
    Route::post(
        '/import/rename',
        [Admin\BookController::class, 'books.renameImportItem']
    )->name('import.rename');

    // AJAX: List files in book directory
    Route::get('books/files-ajax', [Admin\BookController::class, 'filesAjax'])->name('books.filesAjax');

    Route::resource('genres', Admin\GenreController::class);
    Route::resource('authors', Admin\AuthorController::class);
    Route::resource('books', Admin\BookController::class);
    Route::get('books/{id}/raw-json', [Admin\BookController::class, 'getRawJson'])->name('books.rawJson');

    // Autocomplete routes for Book form
    Route::get('/books/autocomplete/authors', [
        Admin\BookController::class,
        'autocompleteAuthors',
    ])->name('books.autocomplete.authors');

    Route::get('/books/autocomplete/series', [
        Admin\BookController::class,
        'autocompleteSeries',
    ])->name('books.autocomplete.series');

    Route::resource('account_requests', Admin\AccountRequestController::class);
    Route::get('/books/import-from-title', [
        Admin\BookController::class,
        'importFromTitle',
    ])->name('books.importFromTitle');

    Route::post('/books/search-google-books', [
        Admin\BookController::class,
        'searchGoogleBooks',
    ])->name('books.searchGoogleBooks');

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

    Route::resource('users', Admin\UserController::class);

    // Queue management (admin only)
    Route::middleware(['auth', 'admin'])->group(function () {
        Route::get('/queue', [Admin\QueueController::class, 'index'])->name('queue.index');
        Route::get('/queue/list', [Admin\QueueController::class, 'list']);
        Route::post('/queue/remove/{id}', [Admin\QueueController::class, 'remove']);
        Route::get('/queue/status', [Admin\QueueController::class, 'status']);
        Route::post('/queue/start', [Admin\QueueController::class, 'startWorker']);
        Route::post('/queue/clear', [Admin\QueueController::class, 'clear'])->name('queue.clear');
    });

    Route::resource('messages', Admin\MessageController::class);
    // Admin messaging system
    Route::get('messages', [Admin\MessagesController::class, 'index'])->name('messages.index');
    Route::get('messages/create', [Admin\MessagesController::class, 'create'])->name('messages.create');
    Route::post('messages', [Admin\MessagesController::class, 'store'])->name('messages.store');
    // Route::get('messages/{id}', [Admin\MessagesController::class, 'show'])->name('messages.show');
    Route::post('messages/{id}/mark-as-read', [
        Admin\MessagesController::class,
        'markAsRead',
    ])->name('messages.markAsRead');

    Route::post(
        '/send-notification',
        [AdminNotificationController::class, 'sendNotification']
    )->name('sendNotification');
    Route::post('/messages', [MessageController::class, 'storeAdmin'])->name('messages.storeAdmin');
    // Message routes
    Route::post(
        '/messages/{messageId}/acknowledge',
        [Admin\MessageController::class, 'acknowledge']
    )->name('messages.acknowledge');

    // Admin message creation
    Route::post('/admin/messages', [MessageController::class, 'storeAdmin'])->name('admin.messages.store');

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
});
