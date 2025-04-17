<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookApiController;
use App\Http\Controllers\Api\BookRequestApiController;
use App\Http\Controllers\Api\FollowApiController;
use App\Http\Controllers\Api\ReadingProgressApiController;
use App\Http\Controllers\Api\MessageApiController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Book Routes
    Route::get('/books', [BookApiController::class, 'index']);
    Route::get('/books/{book}', [BookApiController::class, 'show']);
    Route::get('/books/{book}/download', [BookApiController::class, 'download']);

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
