<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    private $firestoreService;

    public function __construct(FirestoreService $firestoreService)
    {
        $this->firestoreService = $firestoreService;
    }

    /**
     * Display a listing of messages
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function index()
    {
        try {
            // Get all messages, including acknowledged ones
            $messages = $this->firestoreService->getMessages(null, true, 100);
            $users = $this->firestoreService->getUsersForMessaging();

            return view('admin.messages.index', [
                'messages' => $messages,
                'users' => $users
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch messages: ' . $e->getMessage());
            return back()->with('error', 'Failed to load messages. Please try again.');
        }
    }

    /**
     * Acknowledge a message
     *
     * @param string $messageId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function acknowledge($messageId)
    {
        try {
            $success = $this->firestoreService->acknowledgeMessage($messageId);

            if ($success) {
                return back()->with('success', 'Message acknowledged.');
            }

            return back()->with('error', 'Failed to acknowledge message.');
        } catch (\Exception $e) {
            Log::error('Failed to acknowledge message: ' . $e->getMessage());
            return back()->with('error', 'An error occurred while acknowledging the message.');
        }
    }
}
