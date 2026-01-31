<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MessageApiController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function index(Request $request)
    {
        $userId = auth()->id();
        if (!$userId) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $messages = \App\Models\Message::where('recipient_id', $userId)
            ->whereNull('acknowledged_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $messages]);
    }

    public function store(Request $request)
    {
        $request->validate(['content' => 'required|string']);

        $admins = $this->documentStoreService->getAdminUsers();
        $adminId = $admins[0]['id'] ?? null;

        if (! is_int($adminId)) {
            return response()->json(['error' => 'No admin user available'], 500);
        }

        $senderId = auth()->id();

        if (! is_int($senderId)) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $messageId = $this->documentStoreService->createMessage([
            'sender_id' => $senderId,
            'recipient_id' => $adminId,
            'type' => 'general',
            'content' => $request->input('content'),
        ]);

        if (! is_string($messageId) || $messageId === '') {
            return response()->json(['error' => 'Failed to create message'], 500);
        }

        return response()->json(['id' => $messageId], 201);
    }

    public function acknowledge(Request $request, int $id)
    {
        $userId = auth()->id();
        $message = \App\Models\Message::where('recipient_id', $userId)
            ->where('id', $id)
            ->first();

        if (!$message) {
            return response()->json(['error' => 'Message not found'], 404);
        }

        $message->update(['acknowledged_at' => now()]);

        return response()->json(['success' => true]);
    }
}
