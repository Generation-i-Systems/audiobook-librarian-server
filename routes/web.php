<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\BookQueueController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\ReadingProgressController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ImageProxyController;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

// --- DEBUG AUTH/SESSION ROUTES (local only) ---
if (app()->environment('local')) {
    Route::get('/debug/middleware', function (\Illuminate\Http\Request $request) {
        return [
            'route_middleware' => $request->route()->gatherMiddleware(),
            'web_group' => \Illuminate\Support\Facades\Route::getMiddlewareGroups()['web'] ?? null,
        ];
    });

    Route::get('/debug/auth', function () {
        \App\Auth\FirestoreUserProvider::logAuthState(); // Log detailed state
        return [
            'auth_user' => Auth::user(),
            'auth_id' => Auth::id(),
            'session_id' => session()->getId(),
            'session_data' => session()->all(),
            'user_class' => Auth::user() ? get_class(Auth::user()) : null,
            'guard' => Auth::getDefaultDriver(),
            'provider' => config('auth.guards.' . Auth::getDefaultDriver() . '.provider'),
            'session_driver' => config('session.driver'),
            'session_cookie' => config('session.cookie'),
            'session_cookie_value' => request()->cookie(config('session.cookie')),
        ];
    });
    Route::get('/debug/session', function () {
        return [
            'session_id' => session()->getId(),
            'session_data' => session()->all(),
        ];
    });
    Route::get('/debug/sessiondb', function () {
        $sessionId = session()->getId();
        $row = null;
        try {
            $row = \DB::table('sessions')->where('id', $sessionId)->first();
        } catch (\Exception $e) {
            $row = $e->getMessage();
        }
        return [
            'session_id' => $sessionId,
            'db_row' => $row,
        ];
    });

    Route::get('/debug/logout', function () {
        Auth::logout();
        session()->invalidate();
        return ['status' => 'logged out'];
    });

    Route::get('/debug/session-write', function () {
        session(['foo' => 'bar']);
        return ['session_id' => session()->getId(), 'session_data' => session()->all()];
    });
}

Route::get('/', function () {
    return view('welcome');
});

// TEMPORARY DEBUG: Dump all Firestore users
Route::get('/firestore-users-dump', function () {
    $result = \App\Services\FirestoreService::dumpAllUsers();
    return response()->json($result);
});

Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

Route::middleware(['auth', 'standard'])->group(function () {
    Route::resource('books', BookController::class)->only(['index', 'show', 'download', 'create']);
    Route::get('/books/create', [App\Http\Controllers\Admin\BookController::class, 'showCreateForm'])->name('books.create');
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

Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
Route::put(
    '/profile/change-password',
    [ProfileController::class, 'changePassword']
)->name('profile.changePassword');
Route::post(
    '/profile/request-admin',
    [ProfileController::class, 'requestAdminPermissions']
)->name('profile.requestAdminPermissions');

// General image proxy for covers and previews
Route::get('/image-proxy', [ImageProxyController::class, 'show'])->name('image.proxy');
// Pretty URL for covers, supports slashes in path
Route::get('/cover/{path}', [ImageProxyController::class, 'cover'])->where('path', '.*')->name('cover.proxy');
Route::get('/google-books-cover/{encodedUrl}', [ImageProxyController::class, 'googleBooksCover'])->where('encodedUrl', '.+')->name('google.books.cover.proxy');

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
    Route::post('/import/rename', [Admin\BookController::class, 'books.renameImportItem'])->name('import.rename');

    // AJAX: List files in book directory
    Route::get('books/files-ajax', [Admin\BookController::class, 'filesAjax'])->name('books.filesAjax');

    Route::resource('genres', Admin\GenreController::class);
    Route::resource('authors', Admin\AuthorController::class);
    Route::resource('books', Admin\BookController::class);
    Route::resource('account_requests', Admin\AccountRequestController::class);
    Route::get('/books/import-from-title', [Admin\BookController::class, 'importFromTitle'])->name('books.importFromTitle');
    Route::post('/books/search-google-books', [Admin\BookController::class, 'searchGoogleBooks'])->name('books.searchGoogleBooks');
    Route::post('/books/import-from-google-books', [Admin\BookController::class, 'importFromGoogleBooks'])->name('books.importFromGoogleBooks');
    Route::post('/books/processImport', [Admin\BookController::class, 'processImport'])->name('books.processImport');
    Route::get(
        '/directory-browser',
        [Admin\DirectoryBrowserController::class, 'browse']
    )->name('directoryBrowser');

    // Bulk import books from directory (recursive, queued)
    Route::post('/books/bulk-import', [Admin\BookController::class, 'bulkImportBooks'])->name('books.bulkImport');
    // Bulk import from a specific directory (recursive)
    Route::post('/books/bulk-import-dir', [Admin\BookController::class, 'bulkImportBooksFromDir'])->name('books.bulkImportDir');

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
    Route::post('messages/{id}/mark-as-read', [Admin\MessagesController::class, 'markAsRead'])->name('messages.markAsRead');

    Route::post(
        '/send-notification',
        [AdminNotificationController::class, 'sendNotification']
    )->name('sendNotification');
    Route::post('/messages', [MessageController::class, 'storeAdmin'])->name('messages.storeAdmin');
    Route::post(
        '/messages/{message}/acknowledge',
        [Admin\MessageController::class, 'acknowledge']
    )->name('messages.acknowledge');
});
