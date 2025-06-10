<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FollowApiController extends Controller
{
    public function follow(Request $request, $followableType, $followableId)
    {
        // Validate input
        $request->validate([
            'followable_type' => 'required|in:author,series',
            'followable_id' => 'required|integer',
        ]);

        $firestore = new FirestoreService;
        $userId = Auth::id();
        $followableId = $followableId;
        if ($userId === $followableId) {
            return response()->json(['error' => 'You cannot follow yourself.'], 400);
        }
        $existing = $firestore->getClient()->collection('follows')
            ->where('user_id', '=', $userId)
            ->where('followable_type', '=', $followableType)
            ->where('followable_id', '=', $followableId)
            ->documents();
        foreach ($existing as $doc) {
            if ($doc->exists()) {
                return response()->json(['error' => 'Already following.'], 400);
            }
        }
        $firestore->getClient()->collection('follows')->add([
            'user_id' => $userId,
            'followable_type' => $followableType,
            'followable_id' => $followableId,
        ]);

        return response()->json(['message' => 'Followed successfully.'], 201);
    }

    public function unfollow(Request $request, $followableType, $followableId)
    {
        // Validate input
        $request->validate([
            'followable_type' => 'required|in:author,series',
            'followable_id' => 'required|integer',
        ]);

        $firestore = new FirestoreService;
        $follows = $firestore->getClient()->collection('follows')
            ->where('user_id', '=', Auth::id())
            ->where('followable_type', '=', $followableType)
            ->where('followable_id', '=', $followableId)
            ->documents();
        foreach ($follows as $follow) {
            $follow->reference()->delete();
        }

        return response()->json(['message' => 'Successfully unfollowed!'], 200);
    }
}
