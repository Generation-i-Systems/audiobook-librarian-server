<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
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
        $this->validateTarget($followableType, $followableId);

        $userId = Auth::id();

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
        $this->validateTarget($followableType, $followableId);

        $userId = Auth::id();

        // Delete follow relationship
        $success = $this->documentStore->deleteFollow($userId, $followableType, $followableId);

        if (!$success) {
            return response()->json(['error' => 'Failed to unfollow.'], 500);
        }

        return response()->json(['message' => 'Successfully unfollowed!'], 200);
    }

    /**
     * The target comes from the URL; the request body is not consulted.
     */
    private function validateTarget(string $followableType, string $followableId): void
    {
        Validator::make(
            ['followable_type' => $followableType, 'followable_id' => $followableId],
            ['followable_type' => 'required|in:author,series', 'followable_id' => 'required|integer|min:1']
        )->validate();
    }
}
