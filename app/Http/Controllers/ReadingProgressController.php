<?php

namespace App\Http\Controllers;

use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReadingProgressController extends Controller
{
    public function update(Request $request)
    {
        $request->validate([
            'book_id' => 'required|string',
            'current_position' => 'required|integer|min:0',
        ]);
        $user = Auth::user();
        $firestore = new FirestoreService;
        $userId = $user->id;
        $bookId = $request->book_id;
        // Find or create reading progress document
        $progressQuery = $firestore->getClient()->collection('reading_progress')
            ->where('user_id', '=', $userId)
            ->where('book_id', '=', $bookId)
            ->documents();
        $progressDoc = null;
        foreach ($progressQuery as $doc) {
            if ($doc->exists()) {
                $progressDoc = $doc;
                break;
            }
        }
        if ($progressDoc) {
            $progressDoc->reference()->set([
                'current_position' => $request->current_position,
            ], ['merge' => true]);
        } else {
            $firestore->getClient()->collection('reading_progress')->add([
                'user_id' => $userId,
                'book_id' => $bookId,
                'current_position' => $request->current_position,
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function get(Request $request)
    {
        $request->validate([
            'book_id' => 'required|string',
        ]);
        $user = Auth::user();
        $firestore = new FirestoreService;
        $userId = $user->id;
        $bookId = $request->book_id;
        $progressQuery = $firestore->getClient()->collection('reading_progress')
            ->where('user_id', '=', $userId)
            ->where('book_id', '=', $bookId)
            ->documents();
        $currentPosition = 0;
        foreach ($progressQuery as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                $currentPosition = $data['current_position'] ?? 0;
                break;
            }
        }

        return response()->json(['current_position' => $currentPosition]);
    }
}
