<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;
use App\Services\FirestoreService;

class MessagesController extends Controller
{
    // List messages for current user (mailbox style)
    public function index()
    {
        $user = Auth::user();
        $firestore = new \App\Services\FirestoreService();
        // Get all messages for the current user, sort by is_read and created_at desc
        $messages = [];
        $docs = $firestore->db->collection('messages')->where('to_user_id', '=', $user->id)->documents();
        foreach ($docs as $doc) {
            $messages[] = $doc->data();
        }
        // Optionally sort in PHP if Firestore ordering is unavailable
        usort($messages, function($a, $b) {
            if (($a['is_read'] ?? false) == ($b['is_read'] ?? false)) {
                return strtotime($b['created_at'] ?? '') <=> strtotime($a['created_at'] ?? '');
            }
            return ($a['is_read'] ?? false) <=> ($b['is_read'] ?? false);
        });
        return view('admin.messages.index', compact('messages'));
    }

    // Show a single message
    public function show($id)
    {
        $user = Auth::user();
        $firestore = new \App\Services\FirestoreService();
        $doc = $firestore->db->collection('messages')->document($id)->snapshot();
        if (!$doc->exists() || ($doc->data()['to_user_id'] ?? null) != $user->id) {
            abort(404);
        }
        $message = $doc->data();
        return view('admin.messages.show', compact('message'));
    }

    // Mark as read (AJAX or POST)
    public function markAsRead($id)
    {
        $user = Auth::user();
        $firestore = new \App\Services\FirestoreService();
        $docRef = $firestore->db->collection('messages')->document($id);
        $doc = $docRef->snapshot();
        if (!$doc->exists() || ($doc->data()['to_user_id'] ?? null) != $user->id) {
            return response()->json(['success' => false, 'error' => 'Message not found'], 404);
        }
        $docRef->update([['path' => 'is_read', 'value' => true]]);
        return response()->json(['success' => true]);
    }

    // Show create message form
    public function create()
    {
        $firestore = new \App\Services\FirestoreService();
        $users = $firestore->db->collection('users')->documents();
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
        $firestore = new \App\Services\FirestoreService();
        $firestore->db->collection('messages')->add($data);
        return redirect()->route('admin.messages.index')->with('success', 'Message sent.');
    }
}
