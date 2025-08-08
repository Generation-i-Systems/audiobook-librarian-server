<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
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
            $messages = $this->documentStoreService->getMessages(null, true, 100);
            $users = $this->documentStoreService->getUsersForMessaging();

            return view('admin.messages.index', [
                'messages' => $messages,
                'users' => $users,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch messages: ' . $e->getMessage());

            return back()->with('error', 'Failed to load messages. Please try again.');
        }
    }


    /**
     * Acknowledge a message
     *
     * @param  string  $messageId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function acknowledge($messageId)
    {
        try {
            $success = $this->documentStoreService->acknowledgeMessage($messageId);

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
