<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FollowController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function follow(Request $request, $followableType, $followableId)
    {
        // Validate input
        $request->validate([
            'followable_type' => 'required|in:author,series',
            'followable_id' => 'required|integer',
        ]);

        // No need to fetch author/series from Eloquent, just trust input (validated above)
        $this->documentStoreService->createFollow(
            (string) Auth::id(),
            $followableType,
            $followableId
        );

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
        $this->documentStoreService->deleteFollow(
            (string) Auth::id(),
            $followableType,
            $followableId
        );

        return back()->with('success', 'Successfully unfollowed!');
    }
}
