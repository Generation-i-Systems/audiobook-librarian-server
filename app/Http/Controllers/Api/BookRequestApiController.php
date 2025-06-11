<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
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
        $firestore = new FirestoreService();
        $docRef = $firestore->getClient()->collection('book_requests')->add([
            'user_id' => $userId,
            'book_id' => $request->input('book_id'),
            'title' => $request->title,
            'author' => $request->author,
            'series' => $request->series,
            'description' => $request->description,
            'status' => 'pending',
        ]);
        $bookRequest = $docRef->snapshot()->data();
        $bookRequest['id'] = $docRef->id();

        return response()->json($bookRequest, 201); // 201 Created
    }
}
