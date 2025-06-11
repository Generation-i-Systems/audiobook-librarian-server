<?php

namespace App\Http\Controllers;

use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FollowController extends Controller
{
    public function follow(Request $request, $followableType, $followableId)
    {
        // Validate input
        $request->validate([
            'followable_type' => 'required|in:author,series',
            'followable_id' => 'required|integer',
        ]);

        // No need to fetch author/series from Eloquent, just trust input (validated above)
        $firestore = new FirestoreService();
        $firestore->getClient()->collection('follows')->add([
            'user_id' => Auth::id(),
            'followable_type' => $followableType,
            'followable_id' => $followableId,
        ]);

        return back()->with('success', 'Successfully followed!');
    }

    public function unfollow(Request $request, $followableType, $followableId)
    {
        // Validate input
        $request->validate([
            'followable_type' => 'required|in:author,series',
            'followable_id' => 'required|integer',
        ]);
        // No need to fetch author/series from Eloquent, just trust input (validated above)
        $firestore = new FirestoreService();
        $follows = $firestore->getClient()->collection('follows')
            ->where('user_id', '=', Auth::id())
            ->where('followable_type', '=', $followableType)
            ->where('followable_id', '=', $followableId)
            ->documents();
        foreach ($follows as $follow) {
            $follow->reference()->delete();
        }

        return back()->with('success', 'Successfully unfollowed!');
    }
}
