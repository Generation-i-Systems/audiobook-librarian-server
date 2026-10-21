<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
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
     * Display a listing of messages for one recipient (the current user by default) or, with
     * ?recipient=all, for every user
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function index(Request $request)
    {
        try {
            $selectedRecipient = (string) $request->query('recipient', (string) Auth::id());
            $recipientId = $selectedRecipient === 'all' ? null : $selectedRecipient;

            // Include acknowledged messages too
            $messages = $this->documentStoreService->getMessages($recipientId, true, 100);
            $users = $this->documentStoreService->getUsersForMessaging();

            return view('admin.messages.index', [
                'messages' => $messages,
                'users' => $users,
                'selectedRecipient' => $selectedRecipient,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch messages: ' . $e->getMessage());

            return back()->with('error', __('Failed to load messages. Please try again.'));
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
                return back()->with('success', __('Message acknowledged.'));
            }

            return back()->with('error', __('Failed to acknowledge message.'));
        } catch (\Exception $e) {
            Log::error('Failed to acknowledge message: ' . $e->getMessage());

            return back()->with('error', __('An error occurred while acknowledging the message.'));
        }
    }
}
