<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;

class MessageApiController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['content' => 'required|string']);

        // Messages from mobile apps will not have an authenticated user
        $userId = auth()->check() ? auth()->id() : null;

        $messages = $this->documentStoreService->createUserMessage([
            'user_id' => $userId,
            'content' => $request->input('content'),
            'is_from_admin' => false,
        ]);
        return response()->json($messages, 201); // Created
    }
}
