<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookRequestApiController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'series' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'book_id' => 'required',
        ]);

        $userId = Auth::id();
        $bookRequest = $this->documentStoreService->createBookRequest([
            'user_id' => $userId,
            'book_id' => $request->input('book_id'),
            'title' => $request->title,
            'author' => $request->author,
            'series' => $request->series,
            'description' => $request->description,
            'status' => 'pending',
        ]);

        return response()->json($bookRequest, 201); // 201 Created
    }
}
