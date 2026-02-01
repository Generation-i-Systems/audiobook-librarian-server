<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessagesController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }


    // List messages for current user (mailbox style)
    public function index()
    {
        $user = Auth::user();
        $messages = $this->documentStoreService->getMessages($user->id);

        return view('admin.messages.index', compact('messages'));
    }


    // Show a single message
    public function show($id)
    {
        $user = Auth::user();
        $message = $this->documentStoreService->getDocument('messages', $id);
        if (!$message || ($message['to_user_id'] ?? null) != $user->id) {
            abort(404);
        }

        return view('admin.messages.show', compact('message'));
    }


    // Mark as read (AJAX or POST)
    public function markAsRead($id)
    {
        $user = Auth::user();
        $message = $this->documentStoreService->getDocument('messages', $id);
        if (!$message || ($message['to_user_id'] ?? null) != $user->id) {
            return response()->json(['success' => false, 'error' => 'Message not found'], 404);
        }
        $this->documentStoreService->updateDocument('messages', $id, ['is_read' => true]);

        return response()->json(['success' => true]);
    }


    // Show create message form
    public function create()
    {
        $users = $this->documentStoreService->getAllUsers();

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

        $recipientId = $data['to_user_id'] ?? null;

        if (! is_int($recipientId)) {
            return redirect()->route('admin.messages.index')->with('error', 'Recipient is required.');
        }

        $content = $data['subject'] . "\n\n" . $data['body'];

        $messageId = $this->documentStoreService->createMessage([
            'sender_id' => Auth::id(),
            'recipient_id' => $recipientId,
            'content' => $content,
        ]);

        if (! is_string($messageId) || $messageId === '') {
            return redirect()->route('admin.messages.index')->with('error', 'Failed to send message.');
        }

        return redirect()->route('admin.messages.index')->with('success', 'Message sent.');
    }
}
