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
            'content' => $request->input('content'),
        ]);

        if (! is_string($messageId) || $messageId === '') {
            return response()->json(['error' => 'Failed to create message'], 500);
        }

        return response()->json(['id' => $messageId], 201);
    }
}
