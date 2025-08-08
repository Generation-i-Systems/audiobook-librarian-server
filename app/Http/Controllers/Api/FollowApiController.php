<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Contracts\DocumentStoreServiceInterface;

class FollowApiController extends Controller
{
    protected $documentStore;


    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        $this->documentStore = $documentStore;
    }


    public function follow(Request $request, string $followableType, string $followableId)
    {
        // Validate input
        $request->validate([
            'followable_type' => 'required|in:author,series',
            'followable_id' => 'required|integer',
        ]);

        $userId = Auth::id();

        if ($userId === $followableId) {
            return response()->json(['error' => 'You cannot follow yourself.'], 400);
        }

        // Check if already following
        if ($this->documentStore->followExists($userId, $followableType, $followableId)) {
            return response()->json(['error' => 'Already following.'], 400);
        }

        // Create follow relationship
        $success = $this->documentStore->createFollow($userId, $followableType, $followableId);

        if (!$success) {
            return response()->json(['error' => 'Failed to create follow relationship.'], 500);
        }

        return response()->json(['message' => 'Followed successfully.'], 201);
    }


    public function unfollow(Request $request, string $followableType, string $followableId)
    {
        // Validate input
        $request->validate([
            'followable_type' => 'required|in:author,series',
            'followable_id' => 'required|integer',
        ]);

        $userId = Auth::id();

        // Delete follow relationship
        $success = $this->documentStore->deleteFollow($userId, $followableType, $followableId);

        if (!$success) {
            return response()->json(['error' => 'Failed to unfollow.'], 500);
        }

        return response()->json(['message' => 'Successfully unfollowed!'], 200);
    }
}
