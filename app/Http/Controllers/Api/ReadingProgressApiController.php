<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReadingProgressApiController extends Controller
{
    public function update(Request $request)
    {
        $request->validate([
            'current_position' => 'required|integer|min:0',
        ]);

        $user = Auth::user();

        $firestore = new FirestoreService;
        $userId = Auth::id();
        $bookId = $request->input('book_id');
        $progressDocs = $firestore->getClient()->collection('reading_progress')
            ->where('user_id', '=', $userId)
            ->where('book_id', '=', $bookId)
            ->documents();
        $found = false;
        foreach ($progressDocs as $doc) {
            if ($doc->exists()) {
                $doc->reference()->set([
                    'current_position' => $request->current_position,
                ], ['merge' => true]);
                $found = true;
            }
        }
        if (! $found) {
            $firestore->getClient()->collection('reading_progress')->add([
                'user_id' => $userId,
                'book_id' => $bookId,
                'current_position' => $request->current_position,
            ]);
        }

        return response()->json(['success' => true], 200);
    }

    public function reset(Request $request)
    {
        $user = Auth::user();

        $firestore = new FirestoreService;
        $userId = Auth::id();
        $bookId = $request->input('book_id');
        $progressDocs = $firestore->getClient()->collection('reading_progress')
            ->where('user_id', '=', $userId)
            ->where('book_id', '=', $bookId)
            ->documents();
        foreach ($progressDocs as $doc) {
            if ($doc->exists()) {
                $doc->reference()->delete();
            }
        }

        return response()->json(['message' => 'Progress reset.']);
    }

    public function get(Request $request)
    {
        $user = Auth::user();

        $firestore = new FirestoreService;
        $userId = Auth::id();
        $bookId = $request->input('book_id');
        $progressDocs = $firestore->getClient()->collection('reading_progress')
            ->where('user_id', '=', $userId)
            ->where('book_id', '=', $bookId)
            ->documents();
        $progress = 0;
        foreach ($progressDocs as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                $progress = isset($data['current_position']) ? $data['current_position'] : 0;
                break;
            }
        }

        return response()->json(['progress' => $progress]);
    }
}
