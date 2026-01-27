<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
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
            $admins = $this->documentStoreService->getAdminUsers();
            $adminId = $admins[0]['id'] ?? null;

            if (! is_int($adminId)) {
                return back()->with('error', 'No admin user available');
            }

            $senderId = Auth::check() ? Auth::id() : null;

            $messageData = [
                'content' => $request->input('content'),
                'sender_id' => is_int($senderId) ? $senderId : null,
                'recipient_id' => $adminId,
            ];

            $messageId = $this->documentStoreService->createMessage($messageData);

            if ($messageId) {
                return back()->with('success', 'Message sent to admin!');
            }

            return back()->with('error', 'Failed to send message. Please try again.');
        } catch (\Exception $e) {
            Log::error('Failed to send message: ' . $e->getMessage());

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
                'sender_id' => Auth::id(),
                'recipient_id' => (int) $request->input('to_user_id'),
            ];

            $messageId = $this->documentStoreService->createMessage($messageData);

            if ($messageId) {
                return back()->with('success', 'Message sent to user!');
            }

            return back()->with('error', 'Failed to send message. Please try again.');
        } catch (\Exception $e) {
            Log::error('Failed to send admin message: ' . $e->getMessage());

            return back()->with('error', 'An error occurred while sending the message.');
        }
    }
}
