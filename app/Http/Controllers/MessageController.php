<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['content' => 'required|string']);

        // Messages from mobile apps will not have an authenticated user
        $userId = Auth::check() ? Auth::id() : null;

        Message::create([
            'user_id' => $userId,
            'content' => $request->input('content'),
            'is_from_admin' => false,
        ]);

        return back()->with('success', 'Message sent to admin!');
    }

    //Admin messages creation
    public function storeAdmin(Request $request)
    {
         $request->validate(['content' => 'required|string']);

         Message::create([
            'user_id' => Auth::id(),
            'content' => $request->input('content'),
            'is_from_admin' => true,
        ]);

        return back()->with('success', 'Message sent to admin!');
    }
}
