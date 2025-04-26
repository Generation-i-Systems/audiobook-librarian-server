<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookApiController;
use App\Http\Controllers\Api\BookRequestApiController;
use App\Http\Controllers\Api\FollowApiController;
use App\Http\Controllers\Api\ReadingProgressApiController;
use App\Http\Controllers\Api\MessageApiController;

Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        Route::get('/me', function (Request $request) {
            return response()->json($request->user());
        });

        // Book Routes
        Route::get('/books', [BookApiController::class, 'index']);
        Route::get('/books/{book}', [BookApiController::class, 'show']);
        Route::get('/books/{book}/download', [BookApiController::class, 'download']);
        Route::get('/books/browse', [BookApiController::class, 'browse']);
        Route::get('/books/search', [BookApiController::class, 'search']);
        Route::post('/books/queue/download', [BookApiController::class, 'queueDownload']);
        Route::get('/books/queue/download/{zipId}', [BookApiController::class, 'downloadQueuedZip']);
        Route::post('/books/queue/download/{zipId}/mark-downloaded', [BookApiController::class, 'markZipDownloaded']);
        Route::get('/books/{book}/cover', [BookApiController::class, 'cover']);

        // Book Request Route
        Route::post('/book-requests', [BookRequestApiController::class, 'store']);

        //Follow and Unfollow routes
        Route::post('/follow/{followableType}/{followableId}', [FollowApiController::class, 'follow']);
        Route::delete('/unfollow/{followableType}/{followableId}', [FollowApiController::class, 'unfollow']);

        // Reading Progress Routes
        Route::post('/reading-progress/{book}', [ReadingProgressApiController::class, 'update']);
        Route::get('/reading-progress/{book}', [ReadingProgressApiController::class, 'get']);

        // Message Route
        Route::post('/messages', [MessageApiController::class, 'store']);

        // Logout
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Authentication Routes (outside the auth:sanctum middleware)
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});
