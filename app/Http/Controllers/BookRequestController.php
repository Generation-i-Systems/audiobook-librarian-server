<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookRequestController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'series' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $admins = $this->documentStoreService->getAdminUsers();
        $adminId = $admins[0]['id'] ?? null;

        if (! is_int($adminId)) {
            return back()->with('error', 'No admin user available');
        }

        $senderId = Auth::id();

        if (! is_int($senderId)) {
            return back()->with('error', 'Unauthenticated');
        }

        $payload = [
            'type' => 'book_request',
            'title' => $request->title,
            'author' => $request->author,
            'series' => $request->series,
            'description' => $request->description,
        ];

        $messageId = $this->documentStoreService->createMessage([
            'sender_id' => $senderId,
            'recipient_id' => $adminId,
            'content' => json_encode($payload),
        ]);

        if (! is_string($messageId) || $messageId === '') {
            return back()->with('error', 'Failed to submit book request');
        }

        return back()->with('success', 'Book request submitted!');
    }
}
