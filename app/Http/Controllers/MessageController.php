<?php

namespace App\Http\Controllers;

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
     * Store a new message from a user to admin
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $request->validate(['content' => 'required|string']);

        try {
            // Messages from mobile apps will not have an authenticated user
            $userId = Auth::check() ? Auth::id() : null;

            $messageData = [
                'content' => $request->input('content'),
                'from_user_id' => $userId,
                'is_from_admin' => false,
                'is_read' => false,
            ];

            $messageId = $this->firestoreService->createMessage($messageData);

            if ($messageId) {
                return back()->with('success', 'Message sent to admin!');
            }

            return back()->with('error', 'Failed to send message. Please try again.');
        } catch (\Exception $e) {
            Log::error('Failed to send message: '.$e->getMessage());

            return back()->with('error', 'An error occurred while sending your message.');
        }
    }

    /**
     * Store a new message from admin to a user
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeAdmin(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
            'to_user_id' => 'required|string',
        ]);

        try {
            $messageData = [
                'content' => $request->input('content'),
                'from_user_id' => Auth::id(),
                'to_user_id' => $request->input('to_user_id'),
                'is_from_admin' => true,
                'is_read' => false,
            ];

            $messageId = $this->firestoreService->createMessage($messageData);

            if ($messageId) {
                return back()->with('success', 'Message sent to user!');
            }

            return back()->with('error', 'Failed to send message. Please try again.');
        } catch (\Exception $e) {
            Log::error('Failed to send admin message: '.$e->getMessage());

            return back()->with('error', 'An error occurred while sending the message.');
        }
    }
}
