<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageApiController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['content' => 'required|string']);

        // Messages from mobile apps will not have an authenticated user
        $userId = Auth::check() ? Auth::id() : null;

        $message = Message::create([
            'user_id' => $userId,
            'content' => $request->input('content'),
            'is_from_admin' => false, // All messages via API are from users
        ]);

        return response()->json($message, 201); // Created
    }
}
