<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Message;
use Illuminate\Support\Facades\Auth;

class MessagesController extends Controller
{
    // List messages for current user (mailbox style)
    public function index()
    {
        $user = Auth::user();
        $messages = Message::where('to_user_id', $user->id)
            ->orderBy('is_read')
            ->orderByDesc('created_at')
            ->get();
        return view('admin.messages.index', compact('messages'));
    }

    // Show a single message
    public function show($id)
    {
        $user = Auth::user();
        $message = Message::where('to_user_id', $user->id)->findOrFail($id);
        return view('admin.messages.show', compact('message'));
    }

    // Mark as read (AJAX or POST)
    public function markAsRead($id)
    {
        $user = Auth::user();
        $message = Message::where('to_user_id', $user->id)->findOrFail($id);
        $message->is_read = true;
        $message->save();
        return response()->json(['success' => true]);
    }

    // Show create message form
    public function create()
    {
        $users = \App\Models\User::all();
        return view('admin.messages.create', compact('users'));
    }

    // Store/send a new message
    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'to_user_id' => 'nullable|exists:users,id',
        ]);
        $data['from_user_id'] = Auth::id();
        $data['is_read'] = false;
        $message = Message::create($data);
        return redirect()->route('admin.messages.index')->with('success', 'Message sent.');
    }
}
