<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    public function index()
    {
        $messages = Message::with('user')->orderBy('created_at', 'desc')->get();
        $users = User::all();
        $admin_permissions = User::where('admin_permissions', true)->get();
        return view('admin.messages.index', compact('messages', 'users', 'admin_permissions'));
    }

    public function acknowledge(Message $message)
    {
        $message->acknowledged_at = now();
        $message->save();

        return back()->with('success', 'Message acknowledged.');
    }
}
