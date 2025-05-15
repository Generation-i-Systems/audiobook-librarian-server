<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageApiController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['content' => 'required|string']);

        // Messages from mobile apps will not have an authenticated user
        $userId = auth()->check() ? auth()->id() : null;

        $firestore = new FirestoreService();
        $firestore->db->collection('messages')->add([
            'user_id' => $userId,
            'content' => $request->input('content'),
            'is_from_admin' => false, // All messages via API are from users
        ]);

        $messagesDocs = $firestore->db->collection('messages')->where('user_id', '=', $userId)->documents();
        $messages = [];
        foreach ($messagesDocs as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                $data['id'] = $doc->id();
                $messages[] = $data;
            }
        }
        return response()->json($messages, 201); // Created
    }
}
