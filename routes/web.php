<?php

use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\DB;
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
            'user_model' => get_class($user),
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
        } else {
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
        }
    });

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

    // Debug database relationships
    Route::get('/debug/relationships', function () {
        return [
            'author_book' => DB::table('author_book')->get(),
            'book_narrator' => DB::table('book_narrator')->get(),
            'book_genre' => DB::table('book_genre')->get(),
            'books' => \App\Models\Book::with(['authors', 'narrators', 'genres'])->limit(5)->get(),
        ];
    });
}

Route::get('/', function () {
    if (Auth::check()) {
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

Route::get('/home', function () {
    return redirect()->route('books.index')->with('status', 'Welcome to Audiobook Librarian!');
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

// CSRF token refresh endpoint
Route::get('/csrf-token', function () {
    return response()->json(['csrf_token' => csrf_token()]);
})->name('csrf.token');

// Regular book routes (handled by the auth middleware group above)

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

// Admin series autocomplete endpoint for book form (accessible to admin users)
Route::get('/admin/series-autocomplete', [Admin\BookController::class, 'autocompleteSeries'])
    ->name('admin.series.autocomplete')
    ->middleware(['auth', 'admin']);

Route::name('admin.')->prefix('admin')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', function () {
        return redirect()->route('admin.books.index');
    });
    Route::post('/books/resync-from-path', [Admin\BookController::class, 'resyncFromPath'])
        ->name('books.resyncFromPath');

    Route::post(
        '/users/{user}/update-role',
        [Admin\AdminController::class, 'updateRole']
    )->name('users.updateRole');
    Route::get('/books/import', [Admin\BookController::class, 'import'])->name('books.import');
    Route::get('/books/import-file', [Admin\BookController::class, 'importFile'])->name('books.importFile');

    // Import file browser routes
    Route::prefix('import')->group(function () {
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
    Route::get('/books/search', action: [Admin\BookController::class, 'searchBooks'])->name('books.search');

    // Legacy endpoints (deprecated)
    Route::get('/books/googleBooks', action: [Admin\BookController::class, 'googleBooks'])->name('books.googleBooks');
    Route::get('/books/audible', action: [Admin\BookController::class, 'audible'])->name('books.audible');

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
    )->name('send.notification');
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
