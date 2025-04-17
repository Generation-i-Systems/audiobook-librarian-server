<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookRequest;
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
        ]);

        $bookRequest = BookRequest::create([
            'user_id' => Auth::id(),
            'title' => $request->title,
            'author' => $request->author,
            'series' => $request->series,
            'description' => $request->description,
        ]);

        return response()->json($bookRequest, 201); // 201 Created
    }
}
