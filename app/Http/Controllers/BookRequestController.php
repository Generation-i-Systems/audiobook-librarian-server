<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\FirestoreService;

class BookRequestController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'series' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $firestore = new \App\Services\FirestoreService();
        $firestore->db->collection('book_requests')->add([
            'user_id' => Auth::id(),
            'title' => $request->title,
            'author' => $request->author,
            'series' => $request->series,
            'description' => $request->description,
        ]);

        return back()->with('success', 'Book request submitted!');
    }
}
