<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    private $firestoreService;

    public function __construct(FirestoreService $firestoreService)
    {
        $this->firestoreService = $firestoreService;
    }

    public function index()
    {
        $messages = Message::with('user')->orderBy('created_at', 'desc')->get();
        $users = User::all();
        return view('admin.messages.index', compact('messages', 'users'));
    }

    public function acknowledge(Message $message)
    {
        $message->acknowledged_at = now();
        $message->save();

        return back()->with('success', 'Message acknowledged.');
    }
}
