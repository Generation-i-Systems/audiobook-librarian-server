<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\BookQueueController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\ReadingProgressController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\Admin;

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

Route::resource('books', BookController::class);
Route::get('/books/import-from-title', [BookController::class, 'importFromTitle'])->name('books.importFromTitle');
Route::post('/books/search-google-books', [BookController::class, 'searchGoogleBooks'])->name('books.searchGoogleBooks');
Route::post('/books/import-from-google-books', [BookController::class, 'importFromGoogleBooks'])->name('books.importFromGoogleBooks');

Route::post('/books/{book}/reviews', [ReviewController::class, 'store'])->name('reviews.store');
Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');

// Book Queue Routes
Route::get('/queue', [BookQueueController::class, 'index'])->name('queue.index');
Route::post('/queue/add/{book}', [BookQueueController::class, 'add'])->name('queue.add');
Route::delete('/queue/remove/{book}', [BookQueueController::class, 'remove'])->name('queue.remove');
Route::post('/queue/update-order', [BookQueueController::class, 'updateOrder'])->name('queue.updateOrder');

//Follow and Unfollow routes
Route::post('/follow/{followableType}/{followableId}', [FollowController::class, 'follow'])->name('follow');
Route::delete('/unfollow/{followableType}/{followableId}', [FollowController::class, 'unfollow'])->name('unfollow');

// Reading Progress Routes
Route::post('/reading-progress/{book}', [ReadingProgressController::class, 'update'])->name('reading_progress.update');
Route::get('/reading-progress/{book}', [ReadingProgressController::class, 'get'])->name('reading_progress.get');

// Message Routes
Route::post('/messages', [MessageController::class, 'store'])->name('messages.store');

//Admin Routes
Route::prefix('admin')->middleware('auth')->group(function () {
    Route::resource('genres', Admin\GenreController::class);
    Route::resource('authors', Admin\AuthorController::class);
    Route::resource('messages', Admin\MessageController::class);

    Route::post('/send-notification', [AdminNotificationController::class, 'sendNotification'])->name('admin.sendNotification');
    Route::post('/messages', [MessageController::class, 'storeAdmin'])->name('admin.messages.store');
});
